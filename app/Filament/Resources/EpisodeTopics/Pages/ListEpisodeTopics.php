<?php

declare(strict_types=1);

namespace App\Filament\Resources\EpisodeTopics\Pages;

use App\Filament\Resources\EpisodeTopics\EpisodeTopicResource;
use Filament\Resources\Pages\ListRecords;

class ListEpisodeTopics extends ListRecords
{
    protected static string $resource = EpisodeTopicResource::class;
}
