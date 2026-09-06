<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shows\Pages;

use App\Enums\ShowStatus;
use App\Filament\Resources\Shows\ShowResource;
use App\Filament\Support\AdminActions;
use Filament\Resources\Pages\EditRecord;

class EditShow extends EditRecord
{
    protected static string $resource = ShowResource::class;

    protected function getHeaderActions(): array
    {
        // No delete on the edit page - deletion (and its RESTRICT guard) lives
        // on the list row action. Editing offers "Arşivle" instead.
        return [
            AdminActions::archive(ShowStatus::Archived),
        ];
    }
}
