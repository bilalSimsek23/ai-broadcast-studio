<x-filament-panels::page>
    {{--
        Page-specific layout CSS. Plain CSS (not Tailwind utilities) so it does
        NOT depend on the app's Vite/Tailwind build — Filament panel pages load
        Filament's own compiled stylesheet, not resources/css/app.css. All
        card / badge / button / select chrome comes from Filament's `fi-*`
        component classes; this only does grid/spacing/tiles.
    --}}
    <style>
        .sc-root { display: grid; gap: 1.5rem; }
        .sc-topbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .sc-topbar__caption { font-size: .8125rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; opacity: .5; }
        .sc-topbar__badges { display: flex; gap: .5rem; }

        .sc-cols { display: grid; gap: 1.5rem; align-items: start; }
        @media (min-width: 1024px) { .sc-cols { grid-template-columns: 1fr 1fr; } }

        .sc-fields { display: grid; gap: 1.25rem; }
        @media (min-width: 1280px) {
            .sc-fields { grid-template-columns: 1fr 1fr; }
            .sc-fields > :last-child { grid-column: 1 / -1; }
        }
        .sc-field { display: grid; gap: .4rem; align-content: start; }
        .sc-label { font-size: .8125rem; font-weight: 600; }
        .sc-help { margin: 0; font-size: .75rem; line-height: 1.45; opacity: .62; }
        .sc-help--warn { color: #b45309; opacity: 1; }
        .sc-help--danger { color: #dc2626; opacity: 1; }
        .dark .sc-help--warn { color: #fbbf24; }
        .dark .sc-help--danger { color: #f87171; }
        .sc-linkbtn { background: none; border: 0; padding: 0; font: inherit; font-weight: 600; text-decoration: underline; cursor: pointer; color: inherit; }

        .sc-tiles { display: grid; gap: .75rem; grid-template-columns: repeat(3, minmax(0, 1fr)); }
        @media (max-width: 640px) { .sc-tiles { grid-template-columns: 1fr; } }
        .sc-tile { border: 1px solid rgba(120, 130, 150, .22); border-radius: .75rem; padding: .85rem 1rem; display: grid; gap: .4rem; }
        .dark .sc-tile { border-color: rgba(255, 255, 255, .1); background: rgba(255, 255, 255, .02); }
        .sc-tile__label { font-size: .6875rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; opacity: .55; }
        .sc-tile__value { display: flex; align-items: center; gap: .5rem; font-size: 1rem; font-weight: 600; }
        .sc-tile__value--time { font-size: 1.6rem; font-variant-numeric: tabular-nums; letter-spacing: .02em; }

        .sc-dot { width: .58rem; height: .58rem; border-radius: 9999px; background: #9aa4b2; flex: none; box-shadow: 0 0 0 3px rgba(154, 164, 178, .18); }
        .sc-dot--connected, .sc-dot--live { background: #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, .2); }
        .sc-dot--connecting, .sc-dot--muted { background: #d97706; box-shadow: 0 0 0 3px rgba(217, 119, 6, .2); }
        .sc-dot--error { background: #dc2626; box-shadow: 0 0 0 3px rgba(220, 38, 38, .2); }

        .sc-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.25rem; }

        .sc-alert { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
            border: 1px solid rgba(120, 130, 150, .28); border-radius: .75rem; padding: .85rem 1.1rem; }
        .dark .sc-alert { border-color: rgba(255, 255, 255, .12); }
        .sc-alert--ok { border-color: rgba(22, 163, 74, .45); }
        .sc-alert--warn { border-color: rgba(217, 119, 6, .5); }
        .sc-alert--danger { border-color: rgba(220, 38, 38, .55); background: rgba(220, 38, 38, .06); }
        .sc-alert__body { flex: 1 1 18rem; display: grid; gap: .25rem; }
        .sc-alert__title { font-size: .75rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; opacity: .6; }
        .sc-alert__line { display: flex; align-items: center; gap: .5rem; font-weight: 600; }

        .sc-notes { margin: 0; padding-left: 1.1rem; display: grid; gap: .35rem; font-size: .8125rem; opacity: .7; }

        .sc-summary { display: grid; grid-template-columns: max-content 1fr; gap: .3rem 1.25rem; font-size: .8125rem; align-items: baseline; margin: 1rem 0 0; }
        .sc-summary dt { margin: 0; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; font-size: .6875rem; opacity: .55; }
        .sc-summary dd { margin: 0; }

        .sc-textarea { width: 100%; min-height: 4.75rem; resize: vertical; border: 1px solid rgba(120, 130, 150, .3);
            border-radius: .5rem; padding: .55rem .75rem; font: inherit; background: transparent; color: inherit; }
        .dark .sc-textarea { border-color: rgba(255, 255, 255, .12); }
        .sc-textarea:focus { outline: 2px solid rgba(120, 190, 255, .5); outline-offset: 1px; }
        .sc-preview { margin-top: 1rem; width: 100%; max-height: 340px; object-fit: contain;
            border-radius: .6rem; border: 1px solid rgba(120, 130, 150, .25); background: #000; }
    </style>

    <div x-data="studioControl" x-cloak class="sc-root">

        {{-- Title is rendered by the Filament page header; here we add the
             prominent connection status badge. --}}
        <div class="sc-topbar">
            <span class="sc-topbar__caption">Reji kontrol paneli</span>
            <div class="sc-topbar__badges">
                <x-filament::badge color="gray"
                    x-show="!connected && !(deviceError || deviceLost) && status !== 'Bağlanıyor…'">
                    Bağlı değil
                </x-filament::badge>
                <x-filament::badge color="warning" x-cloak
                    x-show="!connected && status === 'Bağlanıyor…' && !(deviceError || deviceLost)">
                    Bağlanıyor
                </x-filament::badge>
                <x-filament::badge color="success" x-cloak
                    x-show="connected && !(deviceError || deviceLost)">
                    Bağlı
                </x-filament::badge>
                <x-filament::badge color="danger" x-cloak x-show="deviceError || deviceLost">
                    Hata
                </x-filament::badge>
            </div>
        </div>

        {{-- Yayın Hazırlığı: which prepared, Ready episode + persona goes on air --}}
        <x-filament::section
            icon="heroicon-o-film"
            heading="Yayın Hazırlığı"
            description="Yalnızca 'Yayına Hazır' bölümler listelenir. Bölüm seçilmeden bağlanılamaz."
        >
            <div class="sc-fields">
                <div class="sc-field">
                    <label class="sc-label" for="sc-episode">Yayın Bölümü</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="sc-episode"
                            x-model="episodeUuid"
                            x-on:change="onEpisodeChange()"
                            x-bind:disabled="connected"
                        >
                            <option value="">— bölüm seçin —</option>
                            <template x-for="e in episodes" :key="e.uuid">
                                <option :value="e.uuid" x-text="e.label"></option>
                            </template>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p class="sc-help" x-show="!episodeUuid" x-cloak>Canlı yayına çıkacak bölümü seçin.</p>
                    <p class="sc-help sc-help--warn" x-show="episodeUuid && !selectedEpisode" x-cloak>
                        Kayıtlı bölüm artık "Yayına Hazır" listesinde yok — yeniden seçin.
                    </p>
                </div>

                <div class="sc-field" x-show="needsPersonaChoice" x-cloak>
                    <label class="sc-label" for="sc-persona">Canlı AI Karakteri</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="sc-persona"
                            x-model="personaUuid"
                            x-on:change="onPersonaChange()"
                            x-bind:disabled="connected"
                        >
                            <template x-for="p in episodePersonas" :key="p.uuid">
                                <option :value="p.uuid" x-text="p.title ? (p.name + ' — ' + p.title) : p.name"></option>
                            </template>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p class="sc-help">Bu bölümün kadrosunda birden fazla AI karakteri var; yayına çıkacak olanı seçin.</p>
                </div>

                <div class="sc-field">
                    <label class="sc-label" for="sc-duration">Oturum Süresi</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="sc-duration"
                            x-model.number="durationSeconds"
                            x-on:change="onDurationChange()"
                            x-bind:disabled="connected"
                        >
                            @foreach ($durations as $seconds => $label)
                                <option value="{{ $seconds }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p class="sc-help">Yayın bu sürenin sonunda otomatik kapanır. "Sınırsız" seçilirse kapanmaz.</p>
                </div>
            </div>

            <template x-if="selectedEpisode">
                <dl class="sc-summary">
                    <dt>Program</dt><dd x-text="selectedEpisode.program"></dd>
                    <dt>Bölüm</dt><dd x-text="selectedEpisode.title"></dd>
                    <dt>Ana Konu</dt><dd x-text="selectedEpisode.main_topic"></dd>
                    <dt>AI Karakteri</dt><dd x-text="activePersonaLabel"></dd>
                    <dt>Süre</dt><dd x-text="durationLabel"></dd>
                    <dt>Yayın Durumu</dt><dd x-text="selectedEpisode.status_label"></dd>
                </dl>
            </template>
        </x-filament::section>

        {{-- Yayın Görseli: operator describes an image, generates it, previews
             it, and pushes it to the broadcast screen on command. Nothing is
             stored server-side; the image travels over the BroadcastChannel. --}}
        <x-filament::section
            icon="heroicon-o-photo"
            heading="Yayın Görseli"
            description="Bir görsel tarif edin, üretin, önizleyin ve komutla yayın ekranına gönderin. Görsel hiçbir yere kaydedilmez."
        >
            <x-slot name="afterHeader">
                <x-filament::badge color="success" x-cloak x-show="imageOnAir">Yayında</x-filament::badge>
            </x-slot>

            @unless ($imageDriverReady)
                <p class="sc-help sc-help--warn" style="margin-bottom: 1rem;">
                    Sahte görsel sürücüsü etkin — üretilen görsel prompt'a göre değil, bir "PROVA"
                    placeholder'ıdır. Gerçek görsel için <code>.env</code> dosyasında
                    <code>AI_IMAGE_DRIVER=openai</code> ayarlayın (mevcut OpenAI anahtarını kullanır),
                    ardından <code>php artisan config:clear</code>.
                </p>
            @endunless

            <div class="sc-fields">
                <div class="sc-field" style="grid-column: 1 / -1;">
                    <label class="sc-label" for="sc-image-prompt">Görsel tarifi</label>
                    <textarea
                        id="sc-image-prompt"
                        class="sc-textarea"
                        rows="3"
                        x-model="imagePrompt"
                        x-bind:disabled="imageBusy"
                        placeholder="Örn: Cahiliye dönemi Arap yarımadasında bir pazar yeri, gün batımı, gerçekçi illüstrasyon"
                    ></textarea>
                    <p class="sc-help">
                        Yapay zekâ bu tarife göre görseli üretir.
                        <button type="button" class="sc-linkbtn" x-cloak x-show="selectedEpisode" x-on:click="fillPromptFromEpisode()">
                            Konudan doldur
                        </button>
                    </p>
                </div>

                <div class="sc-field">
                    <label class="sc-label" for="sc-image-size">Boyut</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="sc-image-size" x-model="imageSize" x-bind:disabled="imageBusy">
                            @foreach ($imageSizes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="sc-field">
                    <label class="sc-label" for="sc-image-quality">Kalite</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="sc-image-quality" x-model="imageQuality" x-bind:disabled="imageBusy">
                            @foreach ($imageQualities as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p class="sc-help">Yüksek kalite daha yavaş — sunucu zaman aşımına uğrarsa "Hızlı" seçin.</p>
                </div>
            </div>

            <div class="sc-actions">
                <x-filament::button
                    color="primary"
                    icon="heroicon-o-sparkles"
                    x-on:click="generateImage()"
                    x-bind:disabled="imageBusy || imagePrompt.trim().length < 3"
                >
                    <span x-show="!imageBusy">Görsel Oluştur</span>
                    <span x-cloak x-show="imageBusy">Üretiliyor…</span>
                </x-filament::button>

                <x-filament::button
                    color="success"
                    icon="heroicon-o-tv"
                    x-cloak
                    x-show="stagedImage && !imageOnAir"
                    x-on:click="putImageOnAir()"
                >
                    Yayına Ver
                </x-filament::button>

                <x-filament::button
                    color="danger"
                    icon="heroicon-o-x-mark"
                    x-cloak
                    x-show="imageOnAir"
                    x-on:click="clearImageFromAir()"
                >
                    Yayından Kaldır
                </x-filament::button>
            </div>

            <p class="sc-help sc-help--danger" x-cloak x-show="imageError" x-text="imageError"></p>
            <p class="sc-help sc-help--warn" x-cloak x-show="imageOnAir && !broadcastAlive">
                Görsel işaretlendi ama yayın ekranı kapalı — Studio Live ekranını açın, görsel otomatik gidecek.
            </p>
            <p class="sc-help" x-cloak x-show="imageOnAir && broadcastAlive">Görsel yayın ekranında.</p>

            <template x-if="stagedImage">
                <img class="sc-preview" :src="stagedImage" alt="">
            </template>
        </x-filament::section>

        {{-- Broadcast screen (Studio Live) status --}}
        <div class="sc-alert" :class="broadcastAlive ? 'sc-alert--ok' : 'sc-alert--warn'">
            <div class="sc-alert__body">
                <div class="sc-alert__title">Yayın Ekranı</div>
                <div class="sc-alert__line">
                    <span class="sc-dot" :class="broadcastAlive ? 'sc-dot--live' : 'sc-dot--connecting'"></span>
                    <span x-show="broadcastAlive" x-cloak>Hazır</span>
                    <span x-show="!broadcastAlive" x-cloak>Kapalı</span>
                </div>
                <p class="sc-help" x-show="!broadcastAlive" x-cloak>
                    Yayın ekranını bu tarayıcıda VEYA aşağıdaki adresle uzakta (vMix / OBS) açın.
                </p>
                <p class="sc-help" style="word-break: break-all;">
                    <strong>Ses motoru</strong> (mikrofonlu Chrome — reji PC):<br>
                    <code>{{ $broadcastUrl }}</code>
                    <button type="button" class="sc-linkbtn" x-on:click="copyBroadcastUrl('engine')">kopyala</button>
                    <span x-cloak x-show="urlCopied === 'engine'"> ✓</span>
                </p>
                <p class="sc-help" style="word-break: break-all;">
                    <strong>Görüntü</strong> (vMix web input — orb + görsel, ses yok):<br>
                    <code>{{ $broadcastDisplayUrl }}</code>
                    <button type="button" class="sc-linkbtn" x-on:click="copyBroadcastUrl('display')">kopyala</button>
                    <span x-cloak x-show="urlCopied === 'display'"> ✓</span>
                </p>
                @unless ($broadcastTokenSet)
                    <p class="sc-help sc-help--warn">STUDIO_LIVE_ACCESS_TOKEN ayarlı değil — uzak ekran yalnızca giriş yapmış adminde çalışır.</p>
                @endunless
            </div>
            <x-filament::button
                tag="a"
                href="{{ $broadcastUrl }}"
                target="_blank"
                color="gray"
                size="sm"
                icon="heroicon-o-arrow-top-right-on-square"
            >
                Yayın ekranını aç
            </x-filament::button>
        </div>

        {{-- Critical device alerts (conditions unchanged) --}}
        <div class="sc-alert sc-alert--danger" x-show="deviceLost" x-cloak>
            <div class="sc-alert__body">
                <div class="sc-alert__title">Kritik</div>
                <div class="sc-alert__line">Yayında kullanılan AI ses girişi kayboldu.</div>
                <p class="sc-help sc-help--danger">Cihazı yeniden takıp seçin ya da yayını durdurun.</p>
            </div>
        </div>
        <div class="sc-alert sc-alert--danger" x-show="deviceError" x-cloak>
            <div class="sc-alert__body">
                <div class="sc-alert__title">Cihaz hatası</div>
                <div class="sc-alert__line">Seçilen AI ses girişi açılamadı.</div>
                <p class="sc-help sc-help--danger">Farklı bir giriş cihazı seçin.</p>
            </div>
        </div>

        {{-- Two columns: audio routing | live session --}}
        <div class="sc-cols">
            <x-filament::section
                icon="heroicon-o-speaker-wave"
                heading="Ses Yönlendirme"
                description="Reji makinesindeki fiziksel ses giriş/çıkışını ve AI sesini seç."
            >
                <x-slot name="afterHeader">
                    <x-filament::button
                        size="sm"
                        color="gray"
                        icon="heroicon-o-arrow-path"
                        x-on:click="refreshDevices()"
                    >
                        Cihazları Yenile
                    </x-filament::button>
                </x-slot>

                <div class="sc-fields">
                    <div class="sc-field">
                        <label class="sc-label" for="sc-input">AI Ses Girişi</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="sc-input" x-model="inputId" x-on:change="onInputChange()">
                                <option value="">— cihaz seçin —</option>
                                <template x-for="d in inputs" :key="d.id">
                                    <option :value="d.id" x-text="d.label"></option>
                                </template>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <p class="sc-help">Yapay zekânın dinleyeceği ses kaynağı</p>
                        <p class="sc-help sc-help--danger" x-show="inputId && inputMissing" x-cloak>
                            Kayıtlı giriş cihazı bulunamadı — yeniden seçin.
                        </p>
                        <p class="sc-help" x-show="inputId && !inputMissing" x-cloak>
                            <span x-show="!connected">Seçildi — oturum başlayınca kullanılacak.</span>
                            <span x-show="connected" x-text="inputActive ? 'Yayında ve aktif.' : 'Yayında (cihaz doğrulanıyor)…'"></span>
                        </p>
                        <p class="sc-help sc-help--warn" x-show="permissionNeeded" x-cloak>
                            Cihaz adları için mikrofon izni gerekiyor.
                            <button type="button" class="sc-linkbtn" x-on:click="grantPermission()">İzin ver</button>
                        </p>
                    </div>

                    <div class="sc-field">
                        <label class="sc-label" for="sc-output">AI Ses Çıkışı</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select
                                id="sc-output"
                                x-model="outputId"
                                x-on:change="onOutputChange()"
                                x-bind:disabled="broadcastAlive && outputSupported === false"
                            >
                                <option value="">— sistem varsayılanı —</option>
                                <template x-for="d in outputs" :key="d.id">
                                    <option :value="d.id" x-text="d.label"></option>
                                </template>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <p class="sc-help">Yapay zekâ sesinin gönderileceği çıkış</p>
                        <p class="sc-help sc-help--warn" x-show="broadcastAlive && outputSupported === false" x-cloak>
                            Bu tarayıcı ses çıkışı seçimini desteklemiyor. Sistem varsayılan çıkışı kullanılacak.
                        </p>
                        <p class="sc-help sc-help--danger" x-show="outputId && outputMissing" x-cloak>
                            Kayıtlı çıkış cihazı bulunamadı — yeniden seçin.
                        </p>
                    </div>

                    <div class="sc-field">
                        <label class="sc-label" for="sc-voice">AI Sesi</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select
                                id="sc-voice"
                                x-model="voiceId"
                                x-on:change="onVoiceChange()"
                                x-bind:disabled="connected"
                            >
                                @foreach ($voices as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <p class="sc-help">Yapay zekânın konuşacağı ses (varsayılan: erkek)</p>
                        <p class="sc-help" x-show="connected" x-cloak>
                            Ses değişikliği bir sonraki bağlantıda geçerli olur.
                        </p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section
                icon="heroicon-o-signal"
                heading="Canlı Oturum"
                description="Oturumu buradan başlat, sessize al ve bitir."
            >
                <div class="sc-tiles">
                    <div class="sc-tile">
                        <div class="sc-tile__label">Bağlantı</div>
                        <div class="sc-tile__value">
                            <span class="sc-dot" :class="(deviceError || deviceLost) ? 'sc-dot--error' : (connected ? 'sc-dot--connected' : (status === 'Bağlanıyor…' ? 'sc-dot--connecting' : ''))"></span>
                            <span x-text="(deviceError || deviceLost) ? 'HATA' : (connected ? 'BAĞLI' : (status === 'Bağlanıyor…' ? 'BAĞLANIYOR' : 'BAĞLI DEĞİL'))"></span>
                        </div>
                    </div>
                    <div class="sc-tile">
                        <div class="sc-tile__label">Mikrofon</div>
                        <div class="sc-tile__value">
                            <span class="sc-dot" :class="!connected ? '' : (muted ? 'sc-dot--muted' : 'sc-dot--connected')"></span>
                            <span x-text="!connected ? 'KAPALI' : (muted ? 'SESSİZ' : 'AÇIK')"></span>
                        </div>
                    </div>
                    <div class="sc-tile">
                        <div class="sc-tile__label">Kalan süre</div>
                        <div class="sc-tile__value sc-tile__value--time" x-text="remainingLabel"></div>
                    </div>
                </div>

                <div class="sc-actions">
                    <x-filament::button
                        size="lg"
                        color="success"
                        icon="heroicon-o-play"
                        x-show="!connected && !pendingConnect"
                        x-on:click="send('connect')"
                        x-bind:disabled="!broadcastAlive || !inputId || inputMissing || !selectedEpisode || !personaUuid"
                    >
                        BAĞLAN
                    </x-filament::button>
                    <x-filament::button
                        size="lg"
                        color="warning"
                        icon="heroicon-o-microphone"
                        x-cloak
                        x-show="connected && !muted"
                        x-on:click="send('mute')"
                    >
                        MİKROFONU SESSİZE AL
                    </x-filament::button>
                    <x-filament::button
                        size="lg"
                        color="success"
                        icon="heroicon-o-microphone"
                        x-cloak
                        x-show="connected && muted"
                        x-on:click="send('unmute')"
                    >
                        MİKROFONU AÇ
                    </x-filament::button>
                    <x-filament::button
                        size="lg"
                        color="danger"
                        icon="heroicon-o-stop"
                        x-cloak
                        x-show="connected || pendingConnect"
                        x-on:click="send('hangup')"
                    >
                        GÖRÜŞMEYİ BİTİR
                    </x-filament::button>
                </div>
                <p class="sc-help sc-help--warn" x-cloak x-show="pendingConnect">
                    Bağlanma isteği gönderildi ama yayın ekranı henüz bağlanmadı. Ekranın açık ve mikrofon izninin verili olduğundan emin olun; sıfırlamak için "Görüşmeyi Bitir".
                </p>
            </x-filament::section>
        </div>

        {{-- Operator info --}}
        <x-filament::section icon="heroicon-o-information-circle" heading="Operatör Bilgisi" compact>
            <ul class="sc-notes">
                <li>Kontroller yalnızca bu sayfadadır; yayın ekranında hiçbir kontrol görünmez.</li>
                <li>Seçilen ses cihazları ve ses tercihi yalnızca bu tarayıcıda saklanır.</li>
                <li><span x-text="'Durum: ' + (status || '—')"></span></li>
            </ul>
        </x-filament::section>
    </div>

    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.data('studioControl', function () {
                var IN_KEY = 'studio.control.inputDeviceId';
                var OUT_KEY = 'studio.control.outputDeviceId';
                var VOICE_KEY = 'studio.control.voiceId';
                var EP_KEY = 'studio.control.episodeUuid';
                var PER_KEY = 'studio.control.personaUuid';
                var DUR_KEY = 'studio.control.durationSeconds';
                var DEFAULT_VOICE = @js($defaultVoice);
                var EPISODES = @js($episodes);
                var DURATION_LABELS = @js($durations);
                var DURATION_KEYS = Object.keys(DURATION_LABELS).map(Number);
                var DEFAULT_DURATION = @js($defaultDuration);
                var IMAGE_ENDPOINT = @js($imageEndpoint);
                var DEFAULT_IMAGE_SIZE = @js($defaultImageSize);
                var DEFAULT_IMAGE_QUALITY = @js($defaultImageQuality);
                var CONTROL_WRITE = @js($controlWriteEndpoint);
                var CONTROL_READ = @js($controlReadEndpoint);
                var STATE_READ = @js($stateReadEndpoint);
                var BROADCAST_URL = @js($broadcastUrl);
                var BROADCAST_DISPLAY_URL = @js($broadcastDisplayUrl);
                var CSRF = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
                var lsGet = function (k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } };
                var lsSet = function (k, v) { try { v ? localStorage.setItem(k, v) : localStorage.removeItem(k); } catch (e) {} };

                return {
                    connected: false, muted: false, status: 'Hazır', remaining: null, broadcastAlive: false,
                    inputs: [], outputs: [], inputId: '', outputId: '', voiceId: DEFAULT_VOICE,
                    episodes: EPISODES, episodeUuid: '', personaUuid: '',
                    durationSeconds: DEFAULT_DURATION,
                    inputMissing: false, outputMissing: false, permissionNeeded: false,
                    outputSupported: null, inputActive: null, deviceError: false, deviceLost: false,
                    imagePrompt: '', imageSize: DEFAULT_IMAGE_SIZE, imageQuality: DEFAULT_IMAGE_QUALITY,
                    stagedImage: '', imageBusy: false, imageError: '', imageOnAir: false, urlCopied: false,
                    _bc: null, _last: 0, _iv: null, _sv: null, _pcT: null, _dcT: null,
                    _control: { desired: 'idle', muted: false }, _imageTicket: null,

                    get remainingLabel() {
                        if (this.remaining === null || this.remaining === undefined) {
                            return this.connected ? '∞' : '—';
                        }
                        var m = Math.floor(this.remaining / 60), s = this.remaining % 60;
                        return m + ':' + (s < 10 ? '0' : '') + s;
                    },
                    get durationLabel() {
                        return DURATION_LABELS[this.durationSeconds] || (Math.round(this.durationSeconds / 60) + ' dk');
                    },
                    get pendingConnect() {
                        return this._control.desired === 'connected' && !this.connected;
                    },
                    get selectedEpisode() {
                        var uuid = this.episodeUuid;
                        return this.episodes.find(function (e) { return e.uuid === uuid; }) || null;
                    },
                    get episodePersonas() {
                        return this.selectedEpisode ? this.selectedEpisode.personas : [];
                    },
                    get needsPersonaChoice() {
                        return !this.connected && this.episodePersonas.length > 1;
                    },
                    get activePersonaLabel() {
                        var uuid = this.personaUuid;
                        var p = this.episodePersonas.find(function (x) { return x.uuid === uuid; });
                        if (!p) return '—';
                        return p.title ? (p.name + ' — ' + p.title) : p.name;
                    },
                    // Keep personaUuid valid for the current episode: force the
                    // single persona, keep a still-valid choice, else default to
                    // the first (sort_order) persona.
                    _syncPersona: function () {
                        var list = this.episodePersonas;
                        if (list.length === 0) { this.personaUuid = ''; return; }
                        if (list.length === 1) { this.personaUuid = list[0].uuid; return; }
                        var current = this.personaUuid;
                        if (!list.some(function (p) { return p.uuid === current; })) {
                            this.personaUuid = list[0].uuid;
                        }
                    },

                    init: function () {
                        this.inputId = lsGet(IN_KEY);
                        this.outputId = lsGet(OUT_KEY);
                        this.voiceId = lsGet(VOICE_KEY) || DEFAULT_VOICE;
                        this.episodeUuid = lsGet(EP_KEY);
                        this.personaUuid = lsGet(PER_KEY);
                        this._syncPersona();

                        var savedDur = parseInt(lsGet(DUR_KEY), 10);
                        this.durationSeconds = (DURATION_KEYS.indexOf(savedDur) !== -1) ? savedDur : DEFAULT_DURATION;

                        // Adopt the server's current intent so reloading this
                        // console does NOT reset a running (remote) broadcast —
                        // but only keep a "connected" intent if a broadcast is
                        // actually live; otherwise it is a stale doc, start idle.
                        fetch(CONTROL_READ, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } })
                            .then((r) => r.ok ? r.json() : null)
                            .then((doc) => {
                                if (!doc || typeof doc !== 'object') return;
                                this._control.muted = !!doc.muted;
                                if (doc.image && doc.image.ticket) this._imageTicket = doc.image.ticket;
                                this.imageOnAir = !!(doc.image && doc.image.visible);
                                if (doc.desired !== 'connected') { this._control.desired = 'idle'; return; }
                                return fetch(STATE_READ, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } })
                                    .then((r) => r.ok ? r.json() : null)
                                    .then((st) => { this._control.desired = (st && st.alive && st.connected) ? 'connected' : 'idle'; });
                            })
                            .then(() => {
                                // Write the (possibly corrected) intent back so a
                                // stale "connected" doc from a past session clears.
                                if (this._control.desired === 'idle') this._doPushControl();
                            })
                            .catch(() => { this._control.desired = 'idle'; });

                        try { this._bc = new BroadcastChannel('studio-live'); } catch (e) { this._bc = null; }
                        if (this._bc) {
                            this._bc.onmessage = (e) => {
                                var d = e.data || {};
                                if (d.type === 'hello') {
                                    this.sendDevices();
                                    if (this.imageOnAir) this._pushImage();
                                    return;
                                }
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
                            // Some pro-audio drivers fire devicechange in bursts —
                            // debounce so we don't re-enumerate + re-push in a loop.
                            navigator.mediaDevices.addEventListener('devicechange', () => {
                                clearTimeout(this._dcT);
                                this._dcT = setTimeout(() => this.refreshDevices(), 800);
                            });
                        }

                        this._iv = setInterval(() => {
                            this.broadcastAlive = (Date.now() - this._last) < 5000;
                            if (!this.broadcastAlive) {
                                this.connected = false; this.muted = false; this.remaining = null;
                                this.inputActive = null; this.deviceLost = false; this.deviceError = false;
                                this.status = 'Yayın ekranı kapalı';
                            }
                        }, 1000);

                        // Server relay: pick up a REMOTE broadcast screen's state.
                        this._sv = setInterval(() => this.pollRemoteState(), 1500);
                        this.pollRemoteState();
                    },

                    destroy: function () {
                        if (this._iv) clearInterval(this._iv);
                        if (this._sv) clearInterval(this._sv);
                        clearTimeout(this._pcT);
                        clearTimeout(this._dcT);
                        if (this._bc) this._bc.close();
                    },

                    pollRemoteState: function () {
                        fetch(STATE_READ, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } })
                            .then((r) => r.ok ? r.json() : null)
                            .then((st) => {
                                if (!st || !st.alive) return;
                                // Same-browser BroadcastChannel wins when it is live.
                                if (Date.now() - this._last < 4000) return;
                                this.connected = !!st.connected;
                                this.muted = !!st.muted;
                                this.status = st.status || '';
                                this.remaining = (st.remainingSeconds === undefined || st.remainingSeconds === null) ? null : st.remainingSeconds;
                                this.outputSupported = (st.outputSupported === undefined ? null : !!st.outputSupported);
                                this.inputActive = st.inputActive || null;
                                this.deviceError = !!st.deviceError;
                                this.deviceLost = !!st.deviceLost;
                                this._last = Date.now();
                                this.broadcastAlive = true;
                            }).catch(() => {});
                    },

                    copyBroadcastUrl: function (which) {
                        var url = which === 'display' ? BROADCAST_DISPLAY_URL : BROADCAST_URL;
                        var done = () => { this.urlCopied = which; setTimeout(() => { this.urlCopied = false; }, 2000); };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(url).then(done).catch(function () {});
                        } else {
                            var t = document.createElement('textarea');
                            t.value = url; document.body.appendChild(t); t.select();
                            try { document.execCommand('copy'); done(); } catch (e) {}
                            document.body.removeChild(t);
                        }
                    },

                    // Debounced so a burst of changes (a devicechange storm, a
                    // few quick selects) collapses into one relay write.
                    _pushControl: function () {
                        clearTimeout(this._pcT);
                        this._pcT = setTimeout(() => this._doPushControl(), 400);
                    },
                    _doPushControl: function () {
                        fetch(CONTROL_WRITE, {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                            body: JSON.stringify({
                                desired: this._control.desired,
                                muted: this._control.muted,
                                voiceId: this.voiceId || null,
                                inputId: this.inputId || null,
                                outputId: this.outputId || null,
                                episodeUuid: this.episodeUuid || null,
                                personaUuid: this.personaUuid || null,
                                durationSeconds: this.durationSeconds,
                                image: { visible: !!this.imageOnAir, ticket: this._imageTicket || null }
                            })
                        }).catch(() => {});
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
                    onVoiceChange: function () {
                        lsSet(VOICE_KEY, this.voiceId);
                        this.sendDevices();
                    },
                    onEpisodeChange: function () {
                        lsSet(EP_KEY, this.episodeUuid);
                        this.personaUuid = '';
                        this._syncPersona();
                        lsSet(PER_KEY, this.personaUuid);
                        this.sendDevices();
                    },
                    onPersonaChange: function () {
                        lsSet(PER_KEY, this.personaUuid);
                        this.sendDevices();
                    },
                    onDurationChange: function () {
                        lsSet(DUR_KEY, String(this.durationSeconds));
                        this.sendDevices();
                    },

                    // --- Broadcast image ---------------------------------------
                    fillPromptFromEpisode: function () {
                        var e = this.selectedEpisode;
                        if (!e) return;
                        this.imagePrompt = (e.main_topic && e.main_topic !== '—') ? e.main_topic : e.title;
                    },

                    generateImage: async function () {
                        var brief = this.imagePrompt.trim();
                        if (brief.length < 3 || this.imageBusy) return;
                        this.imageBusy = true;
                        this.imageError = '';
                        try {
                            var token = document.querySelector('meta[name=csrf-token]');
                            var r = await fetch(IMAGE_ENDPOINT, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''
                                },
                                body: JSON.stringify({
                                    prompt: brief,
                                    size: this.imageSize || null,
                                    quality: this.imageQuality || null,
                                    episode: this.episodeUuid || null
                                })
                            });
                            var body = null;
                            try { body = await r.json(); } catch (e) {}
                            if (!r.ok || !body || !body.ticket) {
                                this.imageError = (body && body.message) ? body.message : 'Görsel isteği başlatılamadı.';
                                this.imageBusy = false;
                                return;
                            }
                            // The image is generated by a background job; poll for it.
                            await this._pollImage(body.ticket);
                        } catch (e) {
                            this.imageError = 'Görsel oluşturulamadı (ağ hatası).';
                            this.imageBusy = false;
                        }
                    },

                    _pollImage: async function (ticket) {
                        var url = IMAGE_ENDPOINT + '/' + encodeURIComponent(ticket);
                        var deadline = Date.now() + 180000;
                        while (Date.now() < deadline) {
                            await new Promise(function (res) { setTimeout(res, 2500); });
                            var s = null;
                            try {
                                var r = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                                s = await r.json();
                            } catch (e) { continue; }
                            if (!s) continue;
                            if (s.status === 'ready' && s.image) {
                                this.stagedImage = s.image;
                                this._imageTicket = ticket;
                                if (this.imageOnAir) { this._pushImage(); this._pushControl(); }
                                this.imageBusy = false;
                                return;
                            }
                            if (s.status === 'failed') {
                                this.imageError = s.message || 'Görsel oluşturulamadı.';
                                this.imageBusy = false;
                                return;
                            }
                            if (s.status === 'expired') {
                                this.imageError = 'Görsel isteği bulunamadı (zaman aşımı). Tekrar deneyin.';
                                this.imageBusy = false;
                                return;
                            }
                        }
                        this.imageError = 'Görsel çok uzun sürdü. Daha düşük kalite deneyin veya tekrar deneyin.';
                        this.imageBusy = false;
                    },

                    _pushImage: function () {
                        if (this._bc && this.stagedImage) {
                            this._bc.postMessage({ type: 'image', action: 'show', src: this.stagedImage });
                        }
                    },
                    putImageOnAir: function () {
                        if (!this.stagedImage) return;
                        this.imageOnAir = true;
                        this._pushImage();
                        this._pushControl();
                    },
                    clearImageFromAir: function () {
                        this.imageOnAir = false;
                        if (this._bc) this._bc.postMessage({ type: 'image', action: 'hide' });
                        this._pushControl();
                    },

                    sendDevices: function () {
                        if (this._bc) this._bc.postMessage({
                            type: 'devices',
                            inputId: this.inputId || null,
                            outputId: this.outputId || null,
                            voiceId: this.voiceId || null,
                            episodeUuid: this.episodeUuid || null,
                            personaUuid: this.personaUuid || null,
                            durationSeconds: this.durationSeconds
                        });
                        this._pushControl();
                    },
                    // Same-browser fast path (BroadcastChannel) + server relay
                    // (level-based intent) so a REMOTE screen follows too.
                    send: function (cmd) {
                        if (cmd === 'connect') this._control.desired = 'connected';
                        else if (cmd === 'hangup') this._control.desired = 'idle';
                        else if (cmd === 'mute') this._control.muted = true;
                        else if (cmd === 'unmute') this._control.muted = false;
                        if (this._bc) this._bc.postMessage({ type: 'cmd', cmd: cmd });
                        this._pushControl();
                    },
                };
            });
        });
    </script>
</x-filament-panels::page>
