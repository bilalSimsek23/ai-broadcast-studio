<x-filament-panels::page>
    <div
        x-data="studioControl"
        x-cloak
        class="mx-auto w-full max-w-2xl space-y-6"
    >
        {{-- Broadcast screen liveness --}}
        <div
            class="rounded-lg border px-4 py-3 text-sm"
            :class="broadcastAlive
                ? 'border-green-300 bg-green-50 text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300'
                : 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'"
        >
            <span x-show="broadcastAlive">Yayın ekranı bağlı.</span>
            <span x-show="!broadcastAlive">
                Yayın ekranı kapalı — bu tarayıcıda
                <a href="/studio/live" target="_blank" class="font-semibold underline">/studio/live</a>
                sekmesini açın (yayın çıkışında).
            </span>
        </div>

        {{-- Critical device alerts --}}
        <div x-show="deviceLost" x-cloak
             class="rounded-lg border border-red-400 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300">
            KRİTİK: Yayında kullanılan AI ses girişi kayboldu. Cihazı yeniden takın/seçin ya da yayını durdurun.
        </div>
        <div x-show="deviceError" x-cloak
             class="rounded-lg border border-red-400 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300">
            Seçilen AI ses girişi açılamadı. Farklı bir giriş cihazı seçin.
        </div>

        {{-- Device selectors --}}
        <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Ses cihazları</h3>
                <x-filament::button size="xs" color="gray" x-on:click="refreshDevices()">
                    Ses cihazlarını yenile
                </x-filament::button>
            </div>

            <p x-show="permissionNeeded" x-cloak class="text-xs text-amber-600 dark:text-amber-400">
                Cihaz adlarını görebilmek için mikrofon izni gerekiyor.
                <button type="button" class="font-semibold underline" x-on:click="grantPermission()">İzin ver</button>
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">AI Ses Girişi</span>
                    <select
                        x-model="inputId"
                        x-on:change="onInputChange()"
                        class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                    >
                        <option value="">— cihaz seçin —</option>
                        <template x-for="d in inputs" :key="d.id">
                            <option :value="d.id" x-text="d.label"></option>
                        </template>
                    </select>
                    <span x-show="inputId && inputMissing" x-cloak class="text-xs text-red-600 dark:text-red-400">
                        Kayıtlı giriş cihazı bulunamadı — yeniden seçin.
                    </span>
                    <span x-show="inputId && !inputMissing" x-cloak class="text-xs text-gray-500 dark:text-gray-400">
                        <span x-show="!connected">Seçildi — oturum başlayınca kullanılacak.</span>
                        <span x-show="connected" x-text="inputActive ? 'Yayında ve aktif.' : 'Yayında (cihaz doğrulanıyor)…'"></span>
                    </span>
                </label>

                <label class="block space-y-1">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">AI Ses Çıkışı</span>
                    <select
                        x-model="outputId"
                        x-on:change="onOutputChange()"
                        x-bind:disabled="broadcastAlive && outputSupported === false"
                        class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm disabled:opacity-50 dark:border-white/10 dark:bg-white/5"
                    >
                        <option value="">— sistem varsayılanı —</option>
                        <template x-for="d in outputs" :key="d.id">
                            <option :value="d.id" x-text="d.label"></option>
                        </template>
                    </select>
                    <span x-show="broadcastAlive && outputSupported === false" x-cloak class="text-xs text-amber-600 dark:text-amber-400">
                        Bu tarayıcı ses çıkışı seçimini desteklemiyor. Sistem varsayılan çıkışı kullanılacak.
                    </span>
                    <span x-show="outputId && outputMissing" x-cloak class="text-xs text-red-600 dark:text-red-400">
                        Kayıtlı çıkış cihazı bulunamadı — yeniden seçin.
                    </span>
                </label>
            </div>
        </div>

        {{-- Status --}}
        <div class="grid grid-cols-2 gap-x-6 gap-y-3 rounded-xl border border-gray-200 bg-white p-5 text-sm dark:border-white/10 dark:bg-white/5">
            <div class="text-gray-500 dark:text-gray-400">Bağlantı</div>
            <div x-text="connected ? 'BAĞLI' : 'Bağlı değil'" :class="connected ? 'font-semibold text-green-600 dark:text-green-400' : ''"></div>

            <div class="text-gray-500 dark:text-gray-400">Mikrofon</div>
            <div x-text="!connected ? '—' : (muted ? 'SESSİZ' : 'AÇIK')"
                 :class="muted ? 'font-semibold text-amber-600 dark:text-amber-400' : (connected ? 'font-semibold text-green-600 dark:text-green-400' : '')"></div>

            <div class="text-gray-500 dark:text-gray-400">Kalan süre</div>
            <div class="font-mono" x-text="remainingLabel"></div>

            <div class="text-gray-500 dark:text-gray-400">Durum</div>
            <div x-text="status || '—'"></div>
        </div>

        {{-- Transport + mute --}}
        <div class="flex flex-wrap gap-3">
            <x-filament::button x-show="!connected" x-on:click="send('connect')" x-bind:disabled="!broadcastAlive || !inputId || inputMissing">
                Bağlan
            </x-filament::button>
            <x-filament::button x-show="connected" color="danger" x-on:click="send('hangup')">
                Görüşmeyi bitir
            </x-filament::button>
            <x-filament::button x-show="connected && !muted" color="gray" x-on:click="send('mute')">
                Mikrofonu sessize al
            </x-filament::button>
            <x-filament::button x-show="connected && muted" color="warning" x-on:click="send('unmute')">
                Mikrofonu aç
            </x-filament::button>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Kontroller yalnızca bu sayfadadır; yayın ekranında hiçbir kontrol görünmez.
            Seçilen cihaz kimlikleri yalnızca bu tarayıcıda saklanır.
        </p>
    </div>

    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.data('studioControl', function () {
                var IN_KEY = 'studio.control.inputDeviceId';
                var OUT_KEY = 'studio.control.outputDeviceId';
                var lsGet = function (k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } };
                var lsSet = function (k, v) { try { v ? localStorage.setItem(k, v) : localStorage.removeItem(k); } catch (e) {} };

                return {
                    connected: false, muted: false, status: 'Hazır', remaining: null, broadcastAlive: false,
                    inputs: [], outputs: [], inputId: '', outputId: '',
                    inputMissing: false, outputMissing: false, permissionNeeded: false,
                    outputSupported: null, inputActive: null, deviceError: false, deviceLost: false,
                    _bc: null, _last: 0, _iv: null,

                    get remainingLabel() {
                        if (this.remaining === null || this.remaining === undefined) return '—';
                        var m = Math.floor(this.remaining / 60), s = this.remaining % 60;
                        return m + ':' + (s < 10 ? '0' : '') + s;
                    },

                    init: function () {
                        this.inputId = lsGet(IN_KEY);
                        this.outputId = lsGet(OUT_KEY);

                        try { this._bc = new BroadcastChannel('studio-live'); } catch (e) { this._bc = null; }
                        if (this._bc) {
                            this._bc.onmessage = (e) => {
                                var d = e.data || {};
                                if (d.type === 'hello') { this.sendDevices(); return; }
                                if (d.type !== 'state') return;
                                this.connected = !!d.connected;
                                this.muted = !!d.muted;
                                this.status = d.status || '';
                                this.remaining = (d.remainingSeconds === undefined ? null : d.remainingSeconds);
                                this.outputSupported = (d.outputSupported === undefined ? null : !!d.outputSupported);
                                this.inputActive = d.inputActive || null;
                                this.deviceError = !!d.deviceError;
                                this.deviceLost = !!d.deviceLost;
                                this._last = Date.now();
                                this.broadcastAlive = true;
                            };
                            this._bc.postMessage({ type: 'hello' });
                        }

                        this.refreshDevices();

                        if (navigator.mediaDevices && 'ondevicechange' in navigator.mediaDevices) {
                            navigator.mediaDevices.addEventListener('devicechange', () => this.refreshDevices());
                        }

                        this._iv = setInterval(() => {
                            this.broadcastAlive = (Date.now() - this._last) < 5000;
                            if (!this.broadcastAlive) {
                                this.connected = false; this.muted = false; this.remaining = null;
                                this.inputActive = null; this.deviceLost = false; this.deviceError = false;
                                this.status = 'Yayın ekranı kapalı';
                            }
                        }, 1000);
                    },

                    destroy: function () {
                        if (this._iv) clearInterval(this._iv);
                        if (this._bc) this._bc.close();
                    },

                    _disambiguate: function (raw) {
                        var counts = {};
                        raw.forEach(function (d) { counts[d.label] = (counts[d.label] || 0) + 1; });
                        var seen = {};
                        return raw.map(function (d) {
                            var label = d.label;
                            if (counts[label] > 1) {
                                seen[label] = (seen[label] || 0) + 1;
                                var tag = (d.id && d.id !== 'default' && d.id !== 'communications')
                                    ? d.id.slice(0, 6) : ('#' + seen[label]);
                                label = label + ' (' + tag + ')';
                            }
                            return { id: d.id, label: label };
                        });
                    },

                    refreshDevices: async function () {
                        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
                        var list;
                        try { list = await navigator.mediaDevices.enumerateDevices(); }
                        catch (e) { return; }

                        var ins = [], outs = [], anyEmpty = false, idx = { in: 0, out: 0 };
                        list.forEach(function (d) {
                            if (d.kind === 'audioinput') {
                                idx.in++;
                                if (!d.label) anyEmpty = true;
                                ins.push({ id: d.deviceId, label: d.label || ('Giriş ' + idx.in) });
                            } else if (d.kind === 'audiooutput') {
                                idx.out++;
                                if (!d.label) anyEmpty = true;
                                outs.push({ id: d.deviceId, label: d.label || ('Çıkış ' + idx.out) });
                            }
                        });

                        this.inputs = this._disambiguate(ins);
                        this.outputs = this._disambiguate(outs);
                        this.permissionNeeded = anyEmpty;

                        this.inputMissing = !!this.inputId && !this.inputs.some((d) => d.id === this.inputId);
                        this.outputMissing = !!this.outputId && !this.outputs.some((d) => d.id === this.outputId);

                        if (this.inputId && !this.inputMissing) this.sendDevices();
                    },

                    grantPermission: async function () {
                        try {
                            var s = await navigator.mediaDevices.getUserMedia({ audio: true });
                            s.getTracks().forEach(function (t) { t.stop(); });
                        } catch (e) { /* denied — labels stay hidden */ }
                        this.refreshDevices();
                    },

                    onInputChange: function () {
                        lsSet(IN_KEY, this.inputId);
                        this.inputMissing = false;
                        this.sendDevices();
                    },
                    onOutputChange: function () {
                        lsSet(OUT_KEY, this.outputId);
                        this.outputMissing = false;
                        this.sendDevices();
                    },

                    sendDevices: function () {
                        if (this._bc) this._bc.postMessage({ type: 'devices', inputId: this.inputId || null, outputId: this.outputId || null });
                    },
                    send: function (cmd) {
                        if (this._bc) this._bc.postMessage({ type: 'cmd', cmd: cmd });
                    },
                };
            });
        });
    </script>
</x-filament-panels::page>
