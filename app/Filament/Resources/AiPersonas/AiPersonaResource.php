<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPersonas;

use App\Filament\Resources\AiPersonas\Pages\CreateAiPersona;
use App\Filament\Resources\AiPersonas\Pages\EditAiPersona;
use App\Filament\Resources\AiPersonas\Pages\ListAiPersonas;
use App\Filament\Resources\AiPersonas\Schemas\AiPersonaForm;
use App\Filament\Resources\AiPersonas\Tables\AiPersonasTable;
use App\Models\AiPersona;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiPersonaResource extends Resource
{
    protected static ?string $model = AiPersona::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Yayın Yönetimi';

    protected static ?string $navigationLabel = 'AI Karakterleri';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'AI Karakteri';

    protected static ?string $pluralModelLabel = 'AI Karakterleri';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AiPersonaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiPersonasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiPersonas::route('/'),
            'create' => CreateAiPersona::route('/create'),
            'edit' => EditAiPersona::route('/{record}/edit'),
        ];
    }
}
