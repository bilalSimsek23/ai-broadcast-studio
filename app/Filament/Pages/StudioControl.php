<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_admin === true;
    }
}
