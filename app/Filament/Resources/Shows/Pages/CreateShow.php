<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shows\Pages;

use App\Filament\Resources\Shows\ShowResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShow extends CreateRecord
{
    protected static string $resource = ShowResource::class;
}
