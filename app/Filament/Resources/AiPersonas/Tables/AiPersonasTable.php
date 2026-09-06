<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPersonas\Tables;

use App\Enums\AiPersonaStatus;
use App\Filament\Support\AdminActions;
use App\Models\AiPersona;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AiPersonasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Ad')->searchable()->sortable(),

                TextColumn::make('title')->label('Unvan')->searchable()->toggleable(),

                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (AiPersonaStatus $state): string => $state->label())
                    ->color(fn (AiPersonaStatus $state): string => match ($state) {
                        AiPersonaStatus::Active => 'success',
                        AiPersonaStatus::Draft => 'info',
                        AiPersonaStatus::Inactive => 'warning',
                        AiPersonaStatus::Archived => 'gray',
                    }),

                TextColumn::make('episodes_count')
                    ->label('Atandığı bölüm')
                    ->counts('episodes')
                    ->sortable(),

                TextColumn::make('ai_provider')->label('Metin profili')->badge()->placeholder('—')->toggleable(),
                TextColumn::make('ai_model')->label('Model kademesi')->badge()->placeholder('—')->toggleable(),

                TextColumn::make('updated_at')->label('Güncellenme')->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Durum')
                    ->options(collect(AiPersonaStatus::cases())->mapWithKeys(fn (AiPersonaStatus $c): array => [$c->value => $c->label()])),
            ])
            ->recordActions([
                EditAction::make(),
                AdminActions::archive(AiPersonaStatus::Archived),
                AdminActions::guardedDelete(fn (Model $record): bool => $record instanceof AiPersona && $record->episodes()->exists()),
            ])
            ->defaultSort('name');
    }
}
