<?php

declare(strict_types=1);

namespace App\Filament\Resources\EpisodeTopics\Pages;

use App\Filament\Resources\Episodes\EpisodeResource;
use App\Filament\Resources\EpisodeTopics\EpisodeTopicResource;
use App\Models\EpisodeTopic;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEpisodeTopic extends EditRecord
{
    protected static string $resource = EpisodeTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToEpisode')
                ->label('Bölüme dön')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                // Pass the owning Episode MODEL so Filament builds the URL with
                // its uuid route key (episode_id is the internal numeric PK).
                // episode() is a relation query, not a lazy access.
                ->url(fn (EpisodeTopic $record): string => EpisodeResource::getUrl('edit', [
                    'record' => $record->episode()->firstOrFail(),
                ])),
            DeleteAction::make()->label('Başlığı sil'),
        ];
    }
}
