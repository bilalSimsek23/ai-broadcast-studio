<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPersonas\Pages;

use App\Filament\Resources\AiPersonas\AiPersonaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiPersonas extends ListRecords
{
    protected static string $resource = AiPersonaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
