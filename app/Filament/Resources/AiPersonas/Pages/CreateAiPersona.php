<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPersonas\Pages;

use App\Filament\Resources\AiPersonas\AiPersonaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiPersona extends CreateRecord
{
    protected static string $resource = AiPersonaResource::class;
}
