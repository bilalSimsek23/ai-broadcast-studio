<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPersonas\Pages;

use App\Enums\AiPersonaStatus;
use App\Filament\Resources\AiPersonas\AiPersonaResource;
use App\Filament\Support\AdminActions;
use Filament\Resources\Pages\EditRecord;

class EditAiPersona extends EditRecord
{
    protected static string $resource = AiPersonaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AdminActions::archive(AiPersonaStatus::Archived),
        ];
    }
}
