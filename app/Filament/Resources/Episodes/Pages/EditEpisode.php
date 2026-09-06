<?php

declare(strict_types=1);

namespace App\Filament\Resources\Episodes\Pages;

use App\Enums\EpisodeStatus;
use App\Filament\Resources\Episodes\EpisodeResource;
use App\Filament\Support\AdminActions;
use App\Models\Episode;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditEpisode extends EditRecord
{
    protected static string $resource = EpisodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepare')
                ->label('Program Hazırlığı')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('primary')
                ->url(fn (Episode $record): string => EpisodeResource::getUrl('prepare', ['record' => $record])),

            AdminActions::archive(EpisodeStatus::Archived),
        ];
    }
}
