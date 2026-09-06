<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shows\Tables;

use App\Enums\ShowStatus;
use App\Filament\Support\AdminActions;
use App\Models\Show;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ShowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Ad')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (ShowStatus $state): string => $state->label())
                    ->color(fn (ShowStatus $state): string => match ($state) {
                        ShowStatus::Active => 'success',
                        ShowStatus::Draft => 'info',
                        ShowStatus::Inactive => 'warning',
                        ShowStatus::Archived => 'gray',
                    }),

                TextColumn::make('episodes_count')
                    ->label('Bölüm sayısı')
                    ->counts('episodes')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Oluşturulma')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Durum')
                    ->options(collect(ShowStatus::cases())->mapWithKeys(fn (ShowStatus $c): array => [$c->value => $c->label()])),
            ])
            ->recordActions([
                EditAction::make(),
                AdminActions::archive(ShowStatus::Archived),
                AdminActions::guardedDelete(fn (Model $record): bool => $record instanceof Show && $record->episodes()->exists()),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
