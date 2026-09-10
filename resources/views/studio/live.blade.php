<!DOCTYPE html>
<html lang="tr" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Canlı Stüdyo</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        /* The broadcast output. Steady state is ONLY the orb — no controls,
           no status, no counter, no text of any kind. Operator controls live
           on the Filament "Canlı Yayın Kontrolü" page. */
        body {
            background: radial-gradient(1400px 900px at 50% 45%, #0b1622, #04070c 72%);
            display: grid; place-items: center; overflow: hidden;
            cursor: none;
        }
        #orb { display: block; }
        /* Broadcast still image. Covers the whole screen when on air, fades
           back to the orb when cleared. Driven ONLY from the control page. */
        #still {
            position: fixed; inset: 0; width: 100%; height: 100%;
            object-fit: contain; background: #04070c;
            opacity: 0; transition: opacity .45s ease; pointer-events: none;
        }
        #still.on { opacity: 1; }
        /* Audio-engine-only takeover / notice overlay. The clean output
           (?mode=display) never renders this. */
        #tk { position: fixed; inset: 0; display: none; place-items: center;
            background: rgba(4,7,12,.85); z-index: 10; cursor: default; }
        #tk.on { display: grid; }
        .tk-box { max-width: 30rem; margin: 1.5rem; padding: 1.6rem 1.8rem;
            border: 1px solid rgba(150,180,220,.25); border-radius: 14px;
            background: #0b1622; color: #dce8f7;
            font: 15px/1.5 system-ui, -apple-system, sans-serif; text-align: center; }
        .tk-box h1 { margin: 0 0 .6rem; font-size: 1.1rem; }
        .tk-box p { margin: 0 0 1.1rem; opacity: .82; }
        .tk-actions { display: flex; gap: .7rem; justify-content: center; flex-wrap: wrap; }
        .tk-btn { padding: .55rem 1.15rem; border-radius: 9px; cursor: pointer;
            user-select: none; font-weight: 600; border: 1px solid rgba(150,180,220,.3); }
        .tk-btn--go { background: #2563eb; border-color: #2563eb; color: #fff; }
        .tk-note { display: none; margin-top: 1rem; opacity: .72; font-size: 13px; }
        .tk-note.on { display: block; }
    </style>
</head>
<body>
    <canvas id="orb" aria-hidden="true"></canvas>
    <img id="still" alt="" aria-hidden="true">
    <audio id="sink" autoplay playsinline></audio>
@unless ($displayMode)
    <div id="tk" aria-hidden="true">
        <div class="tk-box">
            <h1 id="tk-title">Bu yayın ekranı başka bir yerde açık</h1>
            <p id="tk-msg">Buradan devralırsanız yayın bu ekran üzerinden devam eder; diğer ekran devre dışı kalır.</p>
            <div class="tk-actions">
                <div class="tk-btn tk-btn--go" id="tk-yes" role="button" tabindex="0">Devral</div>
                <div class="tk-btn" id="tk-no" role="button" tabindex="0">İptal</div>
            </div>
            <div class="tk-note" id="tk-note"></div>
        </div>
    </div>
@endunless

    <script>
        (function () {
            'use strict';

            var endpoint = "{{ $sessionEndpoint }}";
            var controlEndpoint = "{{ $controlEndpoint }}";
            var stateEndpoint = "{{ $stateEndpoint }}";
            var stateReadEndpoint = "{{ $stateReadEndpoint }}";
            var claimEndpoint = "{{ $claimEndpoint }}";
            var imageStatusBase = "{{ $imageStatusBase }}";
            var csrf = document.querySelector('meta[name=csrf-token]').getAttribute('content');
            // Access token from the URL (?token=…) — lets this screen run
            // remotely / in vMix without an admin session. Empty when opened by
            // a logged-in admin in the same browser.
            var accessToken = new URLSearchParams(location.search).get('token') || '';
            // ?mode=display = ORB + images only, no mic / WebRTC (for vMix web
            // input). Default = the full audio engine.
            var displayMode = @js($displayMode);
            var isEngine = !displayMode;
            var myEngineId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
                : ('eng-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
            var owning = false, displayLevel = 0;
            var $ = function (id) { return document.getElementById(id); };
            var dpr = window.devicePixelRatio || 1;

            var pc = null, micStream = null, audioCtx = null, analyser = null;
            var timerId = null, speaking = 0, connecting = false, lastConnectAt = 0;
            var muted = false, remainingSeconds = null, status = 'Hazır';
            var inputDeviceId = null, outputDeviceId = null, selectedVoice = null;
            var selectedEpisode = null, selectedPersona = null, selectedMaxSeconds = null;
            var activeInputId = null, shownImageTicket = null;
            var outputSupported = ('setSinkId' in HTMLMediaElement.prototype);
            var deviceError = false, deviceLost = false;
            var freq = new Uint8Array(128);

            var bc = null;
            try { bc = new BroadcastChannel('studio-live'); } catch (e) { bc = null; }

            function stateSnapshot() {
                return {
                    connected: !!pc, muted: muted,
                    remainingSeconds: remainingSeconds, status: status,
                    inputActive: activeInputId, outputSupported: outputSupported,
                    deviceError: deviceError, deviceLost: deviceLost,
                    imageVisible: $('still').classList.contains('on'),
                    level: Math.max(0, Math.min(1, speaking)),
                    engineId: myEngineId
                };
            }

            function publish() {
                // Same-browser fast path.
                if (bc) {
                    var m = stateSnapshot(); m.type = 'state';
                    bc.postMessage(m);
                }
            }

            // Server relay: post our state so a REMOTE console / display screen
            // can read it. Only the OWNING audio engine writes it.
            function pushState() {
                if (!owning) return;
                fetch(stateEndpoint, {
                    method: 'POST', credentials: 'same-origin',
                    headers: authHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
                    body: JSON.stringify(stateSnapshot())
                }).catch(function () {});
            }

            function authHeaders(extra) {
                var h = extra || {};
                h['X-CSRF-TOKEN'] = csrf;
                if (accessToken) h['X-Studio-Token'] = accessToken;
                return h;
            }

            function setStatus(t) { status = t; publish(); }

            if (bc) {
                bc.onmessage = function (e) {
                    var d = e.data || {};
                    if (d.type === 'hello') { publish(); return; }
                    if (d.type === 'devices') {
                        applyConfig(d.inputId, d.outputId, d.voiceId, d.episodeUuid, d.personaUuid, d.durationSeconds);
                        return;
                    }
                    if (d.type === 'image') {
                        var still = $('still');
                        if (d.action === 'show' && d.src) { still.src = d.src; still.classList.add('on'); }
                        else if (d.action === 'hide') { still.classList.remove('on'); shownImageTicket = null; }
                        return;
                    }
                    if (d.type !== 'cmd' || !owning) return;
                    if (d.cmd === 'connect') connect();
                    else if (d.cmd === 'hangup') hangup('Görüşme bitti');
                    else if (d.cmd === 'mute') setMuted(true);
                    else if (d.cmd === 'unmute') setMuted(false);
                };
            }

            function applyConfig(inId, outId, voice, ep, per, dur) {
                inputDeviceId = inId || null;
                outputDeviceId = outId || null;
                selectedVoice = voice || null;
                selectedEpisode = ep || null;
                selectedPersona = per || null;
                selectedMaxSeconds = (typeof dur === 'number') ? dur : null;
                applyOutputDevice();
            }

            // --- server command relay (for a REMOTE / separate-browser screen) --
            function applyControl(doc) {
                if (!doc || typeof doc !== 'object') return;

                applyConfig(doc.inputId, doc.outputId, doc.voiceId, doc.episodeUuid, doc.personaUuid, doc.durationSeconds);

                var img = doc.image || {};
                if (img.visible && img.ticket && img.ticket !== shownImageTicket) {
                    showImageByTicket(img.ticket);
                } else if (!img.visible && $('still').classList.contains('on')) {
                    $('still').classList.remove('on'); shownImageTicket = null;
                }

                // Display-only screens never touch audio.
                if (!owning) return;

                if (doc.muted !== undefined && !!doc.muted !== muted) setMuted(!!doc.muted);

                if (doc.desired === 'connected' && !pc && !connecting) {
                    // Cooldown so a failing connect() (no mic permission, bad
                    // episode…) can't hammer /studio/live/session once a second.
                    if (Date.now() - lastConnectAt > 10000) { lastConnectAt = Date.now(); connect(); }
                } else if (doc.desired === 'idle' && (pc || connecting)) {
                    hangup('Görüşme bitti');
                }
            }

            function showImageByTicket(ticket) {
                fetch(imageStatusBase + encodeURIComponent(ticket), {
                    credentials: 'same-origin',
                    headers: authHeaders({ 'Accept': 'application/json' })
                }).then(function (r) { return r.json(); }).then(function (s) {
                    if (s && s.status === 'ready' && s.image) {
                        $('still').src = s.image;
                        $('still').classList.add('on');
                        shownImageTicket = ticket;
                    }
                }).catch(function () {});
            }

            function pollControl() {
                fetch(controlEndpoint, {
                    credentials: 'same-origin',
                    headers: authHeaders({ 'Accept': 'application/json' })
                }).then(function (r) { return r.ok ? r.json() : null; })
                  .then(function (doc) { applyControl(doc); })
                  .catch(function () {});
            }

            // --- display-only screen (vMix web input): orb amplitude + images --
            function pollDisplayState() {
                fetch(stateReadEndpoint, {
                    credentials: 'same-origin',
                    headers: authHeaders({ 'Accept': 'application/json' })
                }).then(function (r) { return r.ok ? r.json() : null; })
                  .then(function (st) {
                      if (st && st.alive) {
                          displayLevel = (typeof st.level === 'number') ? st.level : 0;
                      } else {
                          displayLevel = 0;
                      }
                  }).catch(function () {});
            }

            // --- audio engine ownership (single active engine) ----------------
            var _engineIvs = [];
            function claim(force) {
                return fetch(claimEndpoint, {
                    method: 'POST', credentials: 'same-origin',
                    headers: authHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
                    body: JSON.stringify({ engineId: myEngineId, force: !!force })
                }).then(function (r) { return r.ok ? r.json() : null; });
            }

            function startEngine() {
                if (owning) return;
                owning = true;
                hideTk();
                _engineIvs.push(setInterval(ownerHeartbeat, 5000));
                var tick = 0;
                _engineIvs.push(setInterval(function () {
                    tick++;
                    if (pc || tick % 4 === 0) { publish(); pushState(); }
                }, 500));
                _engineIvs.push(setInterval(pollControl, 2000));
                publish(); pushState(); pollControl();
                if (bc) bc.postMessage({ type: 'hello' });
            }

            function stopEngine() {
                owning = false;
                _engineIvs.forEach(clearInterval);
                _engineIvs = [];
            }

            function ownerHeartbeat() {
                claim(false).then(function (res) {
                    if (res && res.granted === false) {
                        // Someone took over from another screen.
                        stopEngine();
                        hangup('Devralındı');
                        showTk('Yayın başka bir ekrana devralındı',
                            'Bu ekran artık pasif. Yayını buraya geri almak için devralın.',
                            'Buradan Devral', 'Kapat');
                    }
                }).catch(function () {});
            }

            function showTk(title, msg, yes, no) {
                if (displayMode) return;
                var t = $('tk-title'), m = $('tk-msg'), y = $('tk-yes'), n = $('tk-no');
                if (!t) return;
                t.textContent = title; m.textContent = msg;
                y.textContent = yes; n.textContent = no;
                $('tk-note').classList.remove('on');
                $('tk').classList.add('on');
            }
            function hideTk() { var el = $('tk'); if (el) el.classList.remove('on'); }

            @unless ($displayMode)
            (function wireTk() {
                var y = $('tk-yes'), n = $('tk-no');
                var go = function () { claim(true).then(function (res) {
                    if (res && res.granted) startEngine();
                }); };
                var dismiss = function () {
                    hideTk();
                    if (!owning) {
                        $('tk-note').textContent = 'Bu ekran pasif — başka bir yayın ekranı aktif.';
                        $('tk-note').classList.add('on');
                        $('tk').classList.add('on');
                        $('tk-title').textContent = 'Pasif ekran';
                        $('tk-msg').textContent = '';
                        $('tk').querySelector('.tk-actions').style.display = 'none';
                    }
                };
                y.addEventListener('click', go);
                n.addEventListener('click', dismiss);
                y.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') go(); });
                n.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') dismiss(); });
            })();
            @endunless

            // --- boot --------------------------------------------------------
            if (displayMode) {
                setInterval(pollControl, 2000);
                setInterval(pollDisplayState, 400);
                pollControl();
                pollDisplayState();
            } else {
                claim(false).then(function (res) {
                    if (res && res.granted) {
                        startEngine();
                    } else if (res && res.owner) {
                        showTk('Bu yayın ekranı başka bir yerde açık',
                            'Buradan devralırsanız yayın bu ekran üzerinden devam eder; diğer ekran devre dışı kalır.',
                            'Devral', 'İptal');
                    } else {
                        // Relay unreachable — run standalone so a same-browser
                        // admin session still works.
                        startEngine();
                    }
                }).catch(function () { startEngine(); });
            }

            // Autoplay unlock — one click anywhere on the broadcast screen.
            document.addEventListener('click', function () {
                try { $('sink').play().catch(function () {}); } catch (e) {}
                if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
            });

            // A used input device vanishing mid-broadcast is critical.
            if (navigator.mediaDevices && 'ondevicechange' in navigator.mediaDevices) {
                navigator.mediaDevices.addEventListener('devicechange', function () {
                    if (!pc || !activeInputId) return;
                    navigator.mediaDevices.enumerateDevices().then(function (list) {
                        var present = list.some(function (d) {
                            return d.kind === 'audioinput' && d.deviceId === activeInputId;
                        });
                        if (!present) { deviceLost = true; setStatus('AI ses girişi kayboldu'); }
                    }).catch(function () {});
                });
            }

            function audioConstraints(base) {
                var c = {};
                if (base && typeof base === 'object') {
                    if ('echoCancellation' in base) c.echoCancellation = base.echoCancellation;
                    if ('noiseSuppression' in base) c.noiseSuppression = base.noiseSuppression;
                    if ('autoGainControl' in base) c.autoGainControl = base.autoGainControl;
                }
                if (inputDeviceId) c.deviceId = { exact: inputDeviceId };
                return Object.keys(c).length ? c : true;
            }

            function applyOutputDevice() {
                if (!outputSupported || !outputDeviceId) return;
                try {
                    $('sink').setSinkId(outputDeviceId).catch(function () {
                        setStatus('AI ses çıkışı ayarlanamadı');
                    });
                } catch (e) {}
            }

            async function connect() {
                if (pc || connecting) return;
                connecting = true;
                deviceError = false; deviceLost = false;
                setStatus('Bağlanıyor…');

                var s;
                try {
                    var r = await fetch(endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: authHeaders({ 'Accept': 'application/json', 'Content-Type': 'application/json' }),
                        // episode + persona are validated server-side
                        // (App\AI\Realtime\ResolveStudioEpisode); voice against
                        // the config allow-list. No silent fallback for a wrong
                        // episode — a 422 with a Turkish message comes back.
                        body: JSON.stringify({
                            voice: selectedVoice || null,
                            episode: selectedEpisode || null,
                            persona: selectedPersona || null,
                            max_seconds: (selectedMaxSeconds === null ? null : selectedMaxSeconds)
                        })
                    });
                    if (!r.ok) {
                        var err = null;
                        try { err = await r.json(); } catch (e2) {}
                        setStatus((err && err.message) ? err.message : 'Oturum başlatılamadı');
                        connecting = false;
                        return;
                    }
                    s = await r.json();
                } catch (e) { setStatus('Oturum başlatılamadı'); connecting = false; return; }

                try {
                    micStream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraints(s.audio_constraints) });
                } catch (e) {
                    // Do NOT silently fall back to the default device.
                    if (inputDeviceId && (e && (e.name === 'OverconstrainedError' || e.name === 'NotFoundError'))) {
                        deviceError = true;
                        setStatus('Seçilen AI ses girişi açılamadı — cihazı yeniden seçin');
                    } else {
                        setStatus('Mikrofon izni yok');
                    }
                    connecting = false;
                    return;
                }

                var track = micStream.getAudioTracks()[0];
                activeInputId = (track.getSettings && track.getSettings().deviceId) || inputDeviceId || null;
                if (muted) track.enabled = false;

                pc = new RTCPeerConnection();
                pc.addTrack(track, micStream);
                pc.addTransceiver('audio', { direction: 'recvonly' });
                pc.ontrack = function (e) {
                    var sink = $('sink');
                    sink.srcObject = e.streams[0];
                    applyOutputDevice();
                    sink.play().then(function () { setStatus('Yayında'); })
                               .catch(function () { setStatus('Yayın ekranına tıklayın'); });
                    listen(e.streams[0]);
                };
                pc.oniceconnectionstatechange = function () {
                    if (!pc) return;
                    var st = pc.iceConnectionState;
                    if (st === 'connected' || st === 'completed') setStatus('Yayında');
                    if (st === 'failed' || st === 'disconnected') hangup('Bağlantı kesildi');
                };

                var offer = await pc.createOffer();
                await pc.setLocalDescription(offer);

                var answer;
                try {
                    var sdp = await fetch(s.webrtc_url + '?model=' + encodeURIComponent(s.model), {
                        method: 'POST', body: offer.sdp,
                        headers: { 'Authorization': 'Bearer ' + s.client_secret, 'Content-Type': 'application/sdp' }
                    });
                    if (!sdp.ok) throw 0;
                    answer = await sdp.text();
                } catch (e) { hangup('Bağlantı kurulamadı'); return; }

                await pc.setRemoteDescription({ type: 'answer', sdp: answer });
                connecting = false;
                publish(); pushState();
                // 0 / null from the backend = no session limit; otherwise the
                // browser enforces it and auto-hangs up at zero.
                if (Number(s.session_max_seconds) > 0) {
                    startTimer(s.session_max_seconds);
                } else {
                    remainingSeconds = null;
                    setStatus('Yayında');
                }
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

            // Mute/unmute acts on the SELECTED input track only; the WebRTC
            // session and the AI session are untouched.
            function setMuted(m) {
                muted = !!m;
                if (micStream) micStream.getAudioTracks().forEach(function (t) { t.enabled = !muted; });
                setStatus(muted ? 'Mikrofon sessiz' : 'Yayında');
            }

            function startTimer(total) {
                total = Number(total);
                if (!isFinite(total) || total <= 0) { hangup('Yapılandırma hatası'); return; }
                remainingSeconds = Math.round(total);
                var tick = function () {
                    publish(); pushState();
                    if (remainingSeconds <= 0) { hangup('Süre doldu'); return; }
                    remainingSeconds -= 1;
                };
                tick();
                timerId = setInterval(tick, 1000);
            }

            // The single clean-shutdown path — used by the session time limit
            // AND by the operator end command: close the peer connection, stop
            // playback, release the microphone.
            function hangup(reason) {
                connecting = false;
                if (timerId) { clearInterval(timerId); timerId = null; }
                if (pc) {
                    pc.oniceconnectionstatechange = null; pc.ontrack = null;
                    try { pc.close(); } catch (e) {}
                    pc = null;
                }
                if (micStream) { micStream.getAudioTracks().forEach(function (t) { t.stop(); }); micStream = null; }
                if (audioCtx) { try { audioCtx.close(); } catch (e) {} audioCtx = null; }
                analyser = null;
                var sink = $('sink');
                try { sink.pause(); } catch (e) {}
                sink.srcObject = null;
                muted = false; remainingSeconds = null; activeInputId = null;
                setStatus(reason || 'Görüşme bitti');
                pushState();
            }

            // --- orb --------------------------------------------------------
            var canvas = $('orb'), g2d = canvas.getContext('2d');

            // Contained by construction: at MAX amplitude the outermost glow is
            // exactly S * SAFE (< half the canvas), so nothing is ever clipped.
            var PULSE = 0.32, GLOW = 1.6, SAFE = 0.49;

            function resize() {
                var css = Math.round(Math.min(window.innerWidth, window.innerHeight) * 0.70);
                canvas.style.width = canvas.style.height = css + 'px';
                canvas.width = canvas.height = Math.max(1, Math.round(css * dpr));
            }
            window.addEventListener('resize', resize);
            resize();

            function draw(t) {
                var S = canvas.width, c = S / 2, TAU = Math.PI * 2;
                g2d.clearRect(0, 0, S, S);

                var a = 0;
                if (displayMode) {
                    a = Math.max(0, Math.min(1, displayLevel));
                } else if (analyser) {
                    analyser.getByteFrequencyData(freq);
                    var sum = 0;
                    for (var i = 0; i < freq.length; i++) sum += freq[i];
                    a = (sum / freq.length) / 255;
                }
                speaking += (a - speaking) * 0.15;
                var sp = Math.max(0, Math.min(0.97, speaking));
                var wob = 0.03 * (0.5 + 0.5 * Math.sin(t / 1100));
                var amp = Math.min(1, sp + wob);

                var maxCore = S * SAFE / GLOW;
                var baseCore = maxCore / (1 + PULSE);
                var core = baseCore * (1 + amp * PULSE);   // <= maxCore
                var glowR = core * GLOW;                    // <= S * SAFE

                var grad = g2d.createRadialGradient(c, c, core * 0.12, c, c, glowR);
                grad.addColorStop(0, 'rgba(120,190,255,' + (0.30 + sp * 0.5) + ')');
                grad.addColorStop(1, 'rgba(120,190,255,0)');
                g2d.fillStyle = grad;
                g2d.beginPath(); g2d.arc(c, c, glowR, 0, TAU); g2d.fill();

                g2d.fillStyle = 'rgba(190,225,255,' + (0.10 + sp * 0.28) + ')';
                g2d.beginPath(); g2d.arc(c, c, core * 0.80, 0, TAU); g2d.fill();

                g2d.strokeStyle = 'rgba(185,222,255,' + (0.42 + sp * 0.45) + ')';
                g2d.lineWidth = Math.max(2, S * 0.005);
                g2d.beginPath(); g2d.arc(c, c, core, 0, TAU); g2d.stroke();

                requestAnimationFrame(draw);
            }
            requestAnimationFrame(draw);
        })();
    </script>
</body>
</html>
