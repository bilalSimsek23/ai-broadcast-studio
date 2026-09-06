<?php

declare(strict_types=1);

namespace App\Filament\Resources\EpisodeTopics\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $title = 'Sorular';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('question')->label('Soru')->required()->rows(2)->maxLength(2000),
            Textarea::make('ai_context')->label('AI bağlamı')->rows(3)->maxLength(8000),
            Textarea::make('presenter_notes')->label('Sunucu notları')->rows(3)->maxLength(8000),
            TextInput::make('sort_order')->label('Sıra')->numeric()->minValue(0)->default(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('question')
            ->columns([
                TextColumn::make('sort_order')->label('Sıra')->sortable(),
                TextColumn::make('question')->label('Soru')->wrap()->searchable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                CreateAction::make()->label('Soru ekle'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
