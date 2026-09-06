<?php

declare(strict_types=1);

namespace App\Filament\Resources\EpisodeTopics;

use App\Filament\Resources\EpisodeTopics\Pages\EditEpisodeTopic;
use App\Filament\Resources\EpisodeTopics\Pages\ListEpisodeTopics;
use App\Filament\Resources\EpisodeTopics\RelationManagers\QuestionsRelationManager;
use App\Models\EpisodeTopic;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Not shown in navigation - reached only from the Episode edit page's
 * "Tartışma başlıkları" relation manager. Exists as a full resource so a topic
 * gets its own edit page with a Questions relation manager.
 */
class EpisodeTopicResource extends Resource
{
    protected static ?string $model = EpisodeTopic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'Tartışma başlığı';

    protected static ?string $pluralModelLabel = 'Tartışma başlıkları';

    protected static ?string $recordTitleAttribute = 'title';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Başlık')->required()->maxLength(255),
            Textarea::make('description')->label('Açıklama')->rows(3)->maxLength(4000),
            Textarea::make('ai_context')->label('AI bağlamı')->rows(4)->maxLength(8000),
            Textarea::make('presenter_notes')->label('Sunucu notları')->rows(4)->maxLength(8000),
            TextInput::make('sort_order')->label('Sıra')->numeric()->minValue(0)->default(0)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Başlık')->searchable(),
                TextColumn::make('episode.title')->label('Bölüm'),
                TextColumn::make('sort_order')->label('Sıra')->sortable(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getRelations(): array
    {
        return [
            QuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEpisodeTopics::route('/'),
            'edit' => EditEpisodeTopic::route('/{record}/edit'),
        ];
    }
}
