<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shows;

use App\Filament\Resources\Shows\Pages\CreateShow;
use App\Filament\Resources\Shows\Pages\EditShow;
use App\Filament\Resources\Shows\Pages\ListShows;
use App\Filament\Resources\Shows\Schemas\ShowForm;
use App\Filament\Resources\Shows\Tables\ShowsTable;
use App\Models\Show;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ShowResource extends Resource
{
    protected static ?string $model = Show::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Yayın Yönetimi';

    protected static ?string $navigationLabel = 'Programlar';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Program';

    protected static ?string $pluralModelLabel = 'Programlar';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ShowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShowsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShows::route('/'),
            'create' => CreateShow::route('/create'),
            'edit' => EditShow::route('/{record}/edit'),
        ];
    }
}
