<!DOCTYPE html>
<html lang="tr" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Canlı Stüdyo — AI Broadcast Studio</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            background: radial-gradient(1200px 800px at 50% 40%, #0b1622, #05080d 70%);
            color: #e8f0fa;
            font: 500 16px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 2rem; overflow: hidden; -webkit-user-select: none; user-select: none;
        }
        #stage { position: relative; display: grid; place-items: center; }
        #orb { display: block; filter: drop-shadow(0 0 40px rgba(90, 160, 240, .35)); }
        #status {
            position: absolute; bottom: -2.75rem; left: 50%; transform: translateX(-50%);
            white-space: nowrap; font-size: .95rem; letter-spacing: .02em; color: #9fb6cf;
        }
        #timer {
            position: absolute; top: -2.75rem; left: 50%; transform: translateX(-50%);
            font-variant-numeric: tabular-nums; font-size: .95rem; color: #7f97b0;
        }
        #controls { display: flex; flex-wrap: wrap; gap: 1rem; justify-content: center; margin-top: 2.5rem; }
        button {
            appearance: none; border: 1px solid rgba(150, 190, 240, .35);
            background: rgba(30, 60, 100, .35); color: #e8f0fa;
            padding: .8rem 1.6rem; border-radius: 999px; font: inherit; cursor: pointer;
            transition: background .15s ease, opacity .15s ease;
        }
        button:hover { background: rgba(45, 85, 140, .5); }
        button:disabled { opacity: .5; cursor: default; }
        #hangup { border-color: rgba(240, 150, 150, .4); background: rgba(120, 40, 40, .35); }
        #hangup:hover { background: rgba(150, 55, 55, .5); }
        #broadcast { border-color: rgba(150, 190, 240, .25); background: rgba(20, 40, 70, .3); }
        #hint {
            position: fixed; left: 50%; bottom: 1.5rem; transform: translateX(-50%);
            font-size: .8rem; letter-spacing: .03em; color: #7c90a6;
            background: rgba(5, 10, 18, .6); padding: .4rem .9rem; border-radius: 999px;
            pointer-events: none;
        }
        /* Clean broadcast view: only the orb. Operator controls, status text and
           the countdown are hidden — but the session time limit and its
           auto-close keep running. */
        body.clean { cursor: none; }
        body.clean #controls,
        body.clean #status,
        body.clean #timer { display: none !important; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
    <div id="stage">
        <canvas id="orb" aria-hidden="true"></canvas>
        <span id="timer" hidden>--:--</span>
        <p id="status" role="status" aria-live="polite">Hazır</p>
    </div>

    <div id="controls">
        <button id="connect" type="button">Bağlan</button>
        <button id="broadcast" type="button" hidden>Yayın görünümü</button>
        <button id="hangup" type="button" hidden>Görüşmeyi bitir</button>
    </div>

    <p id="hint" hidden>Kontrollere dönmek için Esc'e basın</p>

    <audio id="sink" autoplay hidden></audio>

    <script>
        (function () {
            'use strict';
            var endpoint = "{{ $sessionEndpoint }}";
            var csrf = document.querySelector('meta[name=csrf-token]').getAttribute('content');
            var $ = function (id) { return document.getElementById(id); };
            var dpr = window.devicePixelRatio || 1;

            var pc = null, micStream = null, audioCtx = null, analyser = null;
            var timerId = null, hintTimer = null, speaking = 0;
            var freq = new Uint8Array(128);

            function setStatus(t) { $('status').textContent = t; }

            async function connect() {
                $('connect').disabled = true;
                setStatus('Bağlanıyor…');

                var s;
                try {
                    var r = await fetch(endpoint, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }
                    });
                    if (!r.ok) throw new Error('session');
                    s = await r.json();
                } catch (e) {
                    setStatus('Oturum başlatılamadı. Tekrar deneyin.');
                    $('connect').disabled = false;
                    return;
                }

                try {
                    micStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                } catch (e) {
                    setStatus('Mikrofon izni gerekli.');
                    $('connect').disabled = false;
                    return;
                }

                pc = new RTCPeerConnection();
                pc.addTrack(micStream.getAudioTracks()[0], micStream);
                pc.addTransceiver('audio', { direction: 'recvonly' });
                pc.ontrack = function (e) { $('sink').srcObject = e.streams[0]; listen(e.streams[0]); };
                pc.oniceconnectionstatechange = function () {
                    var st = pc ? pc.iceConnectionState : 'closed';
                    if (st === 'connected' || st === 'completed') setStatus('Bağlı — konuşabilirsiniz');
                    if (st === 'failed' || st === 'disconnected' || st === 'closed') hangup('Bağlantı kesildi');
                };
                pc.createDataChannel('oai-events');

                var offer = await pc.createOffer();
                await pc.setLocalDescription(offer);

                var answer;
                try {
                    var sdpRes = await fetch(s.webrtc_url + '?model=' + encodeURIComponent(s.model), {
                        method: 'POST',
                        body: offer.sdp,
                        headers: { 'Authorization': 'Bearer ' + s.client_secret, 'Content-Type': 'application/sdp' }
                    });
                    if (!sdpRes.ok) throw new Error('sdp');
                    answer = await sdpRes.text();
                } catch (e) {
                    hangup('Bağlantı kurulamadı');
                    return;
                }

                await pc.setRemoteDescription({ type: 'answer', sdp: answer });

                $('connect').hidden = true;
                $('hangup').hidden = false;
                $('broadcast').hidden = false;
                // The only source of truth for the session length is the backend
                // config (config('ai.realtime.session_max_seconds')); the browser
                // never carries its own copy.
                startTimer(s.session_max_seconds);
            }

            function listen(stream) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
                var src = audioCtx.createMediaStreamSource(stream);
                analyser = audioCtx.createAnalyser();
                analyser.fftSize = 256;
                src.connect(analyser);
            }

            function startTimer(total) {
                total = Number(total);
                if (!isFinite(total) || total <= 0) { hangup('Yapılandırma hatası'); return; }

                var left = total;
                $('timer').hidden = false;
                var tick = function () {
                    var m = Math.floor(left / 60), sec = left % 60;
                    $('timer').textContent = m + ':' + (sec < 10 ? '0' : '') + sec;
                    if (left <= 0) { hangup('Süre doldu'); return; }
                    left -= 1;
                };
                tick();
                timerId = setInterval(tick, 1000);
            }

            // Single clean-shutdown path — used both on "Görüşmeyi bitir" and on
            // the session time limit expiring: close the peer connection, stop
            // playback, release the microphone, restore the operator view.
            function hangup(reason) {
                if (timerId) { clearInterval(timerId); timerId = null; }
                if (pc) { try { pc.close(); } catch (e) {} pc = null; }
                if (micStream) { micStream.getTracks().forEach(function (t) { t.stop(); }); micStream = null; }
                if (audioCtx) { try { audioCtx.close(); } catch (e) {} audioCtx = null; }
                analyser = null;

                var sink = $('sink');
                try { sink.pause(); } catch (e) {}
                sink.srcObject = null;

                exitClean();
                $('timer').hidden = true;
                $('broadcast').hidden = true;
                $('hangup').hidden = true;
                $('connect').hidden = false;
                $('connect').disabled = false;
                setStatus(reason || 'Görüşme bitti');
            }

            // --- clean broadcast view -----------------------------------------
            function peekHint() {
                $('hint').hidden = false;
                if (hintTimer) clearTimeout(hintTimer);
                hintTimer = setTimeout(function () { $('hint').hidden = true; }, 2200);
            }
            function enterClean() { document.body.classList.add('clean'); peekHint(); }
            function exitClean() {
                document.body.classList.remove('clean');
                if (hintTimer) { clearTimeout(hintTimer); hintTimer = null; }
                $('hint').hidden = true;
            }
            function isClean() { return document.body.classList.contains('clean'); }

            $('broadcast').addEventListener('click', function (e) { e.stopPropagation(); enterClean(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isClean()) exitClean(); });
            document.addEventListener('click', function () { if (isClean()) exitClean(); });
            document.addEventListener('mousemove', function () { if (isClean()) peekHint(); });

            // --- orb ---------------------------------------------------------
            var canvas = $('orb'), g2d = canvas.getContext('2d');
            function resize() {
                var d = Math.min(window.innerWidth, window.innerHeight) * 0.62;
                canvas.width = canvas.height = Math.round(d * dpr);
                canvas.style.width = canvas.style.height = Math.round(d) + 'px';
            }
            window.addEventListener('resize', resize);
            resize();

            function draw(t) {
                var w = canvas.width, h = canvas.height, cx = w / 2, cy = h / 2;
                g2d.clearRect(0, 0, w, h);

                var amp = 0;
                if (analyser) {
                    analyser.getByteFrequencyData(freq);
                    var sum = 0;
                    for (var i = 0; i < freq.length; i++) sum += freq[i];
                    amp = (sum / freq.length) / 255;
                }
                speaking += (amp - speaking) * 0.15;

                var idle = 0.5 + 0.5 * Math.sin(t / 900);
                var base = w * 0.30;
                var r = base * (1 + speaking * 0.85) + idle * base * 0.05;
                var TAU = Math.PI * 2;

                var grad = g2d.createRadialGradient(cx, cy, r * 0.15, cx, cy, r * 1.7);
                grad.addColorStop(0, 'rgba(120, 190, 255, ' + (0.32 + speaking * 0.5) + ')');
                grad.addColorStop(1, 'rgba(120, 190, 255, 0)');
                g2d.fillStyle = grad;
                g2d.beginPath(); g2d.arc(cx, cy, r * 1.7, 0, TAU); g2d.fill();

                g2d.fillStyle = 'rgba(190, 225, 255, ' + (0.10 + speaking * 0.30) + ')';
                g2d.beginPath(); g2d.arc(cx, cy, r * 0.78, 0, TAU); g2d.fill();

                g2d.strokeStyle = 'rgba(185, 222, 255, ' + (0.45 + speaking * 0.45) + ')';
                g2d.lineWidth = Math.max(2, w * 0.006);
                g2d.beginPath(); g2d.arc(cx, cy, r, 0, TAU); g2d.stroke();

                requestAnimationFrame(draw);
            }
            requestAnimationFrame(draw);

            $('connect').addEventListener('click', connect);
            $('hangup').addEventListener('click', function () { hangup('Görüşme bitti'); });
        })();
    </script>
</body>
</html>
