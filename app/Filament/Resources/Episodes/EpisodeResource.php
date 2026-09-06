<?php

declare(strict_types=1);

namespace App\Filament\Resources\Episodes;

use App\Filament\Resources\Episodes\Pages\CreateEpisode;
use App\Filament\Resources\Episodes\Pages\EditEpisode;
use App\Filament\Resources\Episodes\Pages\ListEpisodes;
use App\Filament\Resources\Episodes\Pages\PrepareEpisode;
use App\Filament\Resources\Episodes\RelationManagers\AiPersonasRelationManager;
use App\Filament\Resources\Episodes\RelationManagers\TopicsRelationManager;
use App\Filament\Resources\Episodes\Schemas\EpisodeForm;
use App\Filament\Resources\Episodes\Tables\EpisodesTable;
use App\Models\Episode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class EpisodeResource extends Resource
{
    protected static ?string $model = Episode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static string|UnitEnum|null $navigationGroup = 'Yayın Yönetimi';

    protected static ?string $navigationLabel = 'Bölümler';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Bölüm';

    protected static ?string $pluralModelLabel = 'Bölümler';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return EpisodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EpisodesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AiPersonasRelationManager::class,
            TopicsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEpisodes::route('/'),
            'create' => CreateEpisode::route('/create'),
            'edit' => EditEpisode::route('/{record}/edit'),
            'prepare' => PrepareEpisode::route('/{record}/prepare'),
        ];
    }
}
