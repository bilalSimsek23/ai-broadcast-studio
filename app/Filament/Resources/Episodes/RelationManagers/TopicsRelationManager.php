<?php

declare(strict_types=1);

namespace App\Filament\Resources\Episodes\RelationManagers;

use App\Filament\Resources\EpisodeTopics\EpisodeTopicResource;
use App\Models\EpisodeTopic;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TopicsRelationManager extends RelationManager
{
    protected static string $relationship = 'topics';

    protected static ?string $title = 'Tartışma başlıkları';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Başlık')->required()->maxLength(255),
            Textarea::make('description')->label('Açıklama')->rows(3)->maxLength(4000),
            Textarea::make('ai_context')->label('AI bağlamı')->rows(3)->maxLength(8000),
            Textarea::make('presenter_notes')->label('Sunucu notları')->rows(3)->maxLength(8000),
            TextInput::make('sort_order')->label('Sıra')->numeric()->minValue(0)->default(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('sort_order')->label('Sıra')->sortable(),
                TextColumn::make('title')->label('Başlık')->searchable()->wrap(),
                TextColumn::make('description')->label('Açıklama')->limit(60)->placeholder('—'),
                TextColumn::make('questions_count')->label('Soru')->counts('questions'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                CreateAction::make()->label('Başlık ekle'),
            ])
            ->recordActions([
                // Editing a topic opens its own page so its Questions can be
                // managed there (a nested relation manager is more reliable
                // than deeply nested forms).
                EditAction::make()
                    ->label('Sorularla düzenle')
                    ->url(fn (EpisodeTopic $record): string => EpisodeTopicResource::getUrl('edit', ['record' => $record])),
                DeleteAction::make()->label('Sil'),
            ]);
    }
}
