<?php

declare(strict_types=1);

namespace App\Filament\Support;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared admin actions that respect the TASK-0001 domain deletion policy:
 * a Show with episodes and an AiPersona used in an episode line-up are
 * FK-RESTRICTed and must not be deleted. The UI never bypasses that; it just
 * fails gracefully instead of surfacing a raw database exception.
 */
final class AdminActions
{
    /**
     * A delete action that is hidden while the record is protected by the
     * domain deletion policy (a Show with episodes, an AiPersona used in a
     * line-up). It also re-checks at click time and shows a friendly
     * notification instead of attempting a delete that the database would
     * RESTRICT. The DB constraint is never removed - this is UX, not a bypass.
     *
     * @param  (Closure(Model): bool)|null  $isProtected
     */
    public static function guardedDelete(?Closure $isProtected = null): DeleteAction
    {
        return DeleteAction::make()
            ->hidden(fn (Model $record): bool => $isProtected !== null && $isProtected($record))
            ->before(function (DeleteAction $action, Model $record) use ($isProtected): void {
                if ($isProtected !== null && $isProtected($record)) {
                    self::deletionBlockedNotification();
                    $action->halt();
                }
            });
    }

    /**
     * "Arşivle" - mark the record's status enum as its Archived case instead of
     * deleting it. Hidden once the record is already archived.
     */
    public static function archive(BackedEnum $archivedCase): Action
    {
        return Action::make('archive')
            ->label('Arşivle')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Kaydı arşivle')
            ->modalDescription('Kayıt silinmez; durumu "Arşivlendi" olarak işaretlenir. Geçmiş yayın verisi korunur.')
            ->modalSubmitActionLabel('Arşivle')
            ->visible(fn (Model $record): bool => $record->getAttribute('status') !== $archivedCase)
            ->action(function (Model $record) use ($archivedCase): void {
                $record->update(['status' => $archivedCase]);
                Notification::make()->success()->title('Kayıt arşivlendi')->send();
            });
    }

    /**
     * Select options for an AiPersona logical-key column, taken from
     * config/ai.php. Label == value (the values ARE the logical keys). This is
     * how the admin form prevents an unregistered key from being entered; the
     * AiPersona model's saving hook remains the authoritative guard.
     *
     * @return array<string, string>
     */
    public static function logicalKeyOptions(string $column): array
    {
        /** @var list<string> $keys */
        $keys = array_values((array) config("ai.persona.{$column}", []));

        return array_combine($keys, $keys);
    }

    private static function deletionBlockedNotification(): void
    {
        Notification::make()
            ->danger()
            ->title('Kayıt silinemez')
            ->body('Bu kayıt geçmiş yayın verisi tarafından kullanılıyor. Silmek yerine "Arşivle" seçeneğini kullanın.')
            ->persistent()
            ->send();
    }
}
