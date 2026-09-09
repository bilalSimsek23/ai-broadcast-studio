<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\EpisodeAiPersona;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * "Canlı Yayın Kontrolü" — the director's operator console for the /studio/live
 * realtime voice broadcast.
 *
 * It holds NO media itself. The /studio/live page (opened in the same browser,
 * on the broadcast output) owns the microphone, the RTCPeerConnection and the
 * audio sink and renders only the orb. This page enumerates the reji machine's
 * physical audio devices in the browser, lets the director pick the AI's input
 * and output device (persisted in localStorage — never server config), shows
 * connection / mute / remaining-time state, and drives /studio/live over a
 * same-origin BroadcastChannel. No server-side audio relay
 * (CLAUDE.md §2.9 / §5).
 */
class StudioControl extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static string|UnitEnum|null $navigationGroup = 'Yayın Yönetimi';

    protected static ?string $navigationLabel = 'Canlı Yayın Kontrolü';

    protected static ?string $title = 'Canlı Yayın Kontrolü';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'studio-control';

    protected string $view = 'filament.pages.studio-control';

    // Reji / broadcast use — wide desktop layout (1080p / 1440p monitors).
    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_admin === true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $voices = config('ai.realtime.voices');
        $default = config('ai.realtime.connections.openai.voice');

        return [
            'voices' => is_array($voices) ? $voices : [],
            'defaultVoice' => is_string($default) && $default !== '' ? $default : 'marin',
            'episodes' => $this->readyEpisodes(),
            'durations' => $this->sessionDurations(),
            'defaultDuration' => $this->defaultDuration(),
            'imageEndpoint' => route('studio.image', [], absolute: false),
            'imageSizes' => $this->imageSizes(),
            'defaultImageSize' => $this->defaultImageSize(),
            'imageDriverReady' => config('ai.image.driver') === 'openai',
        ];
    }

    /**
     * Session lengths the director may pick (seconds => label; `0` = no limit),
     * from config('ai.realtime.session_durations').
     *
     * @return array<int, string>
     */
    private function sessionDurations(): array
    {
        $durations = config('ai.realtime.session_durations');

        if (! is_array($durations)) {
            return [];
        }

        $out = [];

        foreach ($durations as $seconds => $label) {
            if (is_int($seconds) && is_string($label)) {
                $out[$seconds] = $label;
            }
        }

        return $out;
    }

    private function defaultDuration(): int
    {
        $default = config('ai.realtime.session_max_seconds');

        return is_int($default) ? $default : 1200;
    }

    /**
     * The pixel sizes the operator may request for a broadcast image, as
     * key => label. The request is validated against these KEYS server-side.
     *
     * @return array<string, string>
     */
    private function imageSizes(): array
    {
        $sizes = config('ai.image.sizes');

        if (! is_array($sizes)) {
            return [];
        }

        $out = [];

        foreach ($sizes as $key => $label) {
            if (is_string($key) && is_string($label)) {
                $out[$key] = $label;
            }
        }

        return $out;
    }

    private function defaultImageSize(): string
    {
        $default = config('ai.image.size');

        if (is_string($default) && $default !== '') {
            return $default;
        }

        $first = array_key_first($this->imageSizes());

        return is_string($first) ? $first : '1536x1024';
    }

    /**
     * The Ready episodes the director may put on air, each with its full
     * line-up (so the persona selector can populate client-side). No raw
     * credential ever appears here.
     *
     * @return list<array{
     *     uuid: string, label: string, program: string, title: string,
     *     main_topic: string, status_label: string,
     *     personas: list<array{uuid: string, name: string, title: string}>
     * }>
     */
    private function readyEpisodes(): array
    {
        return Episode::query()
            ->where('status', EpisodeStatus::Ready->value)
            ->with(['show', 'lineup.aiPersona'])
            ->orderByDesc('broadcast_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Episode $episode): array {
                // A Ready episode always has a show (readiness check requires it).
                $program = $episode->show->name;
                $number = $episode->episode_number;

                return [
                    'uuid' => $episode->uuid,
                    'label' => trim($program.' — Bölüm '.($number ?? '?').' — '.$episode->title),
                    'program' => $program,
                    'title' => $episode->title,
                    'main_topic' => (string) ($episode->main_topic ?? '—'),
                    'status_label' => 'Yayına Hazır',
                    'personas' => $episode->lineup
                        ->map(static fn (EpisodeAiPersona $slot): array => [
                            'uuid' => $slot->aiPersona->uuid,
                            'name' => $slot->aiPersona->name,
                            'title' => (string) ($slot->aiPersona->title ?? ''),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }
}
