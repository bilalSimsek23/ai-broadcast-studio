<?php

declare(strict_types=1);

namespace App\Filament\Resources\Episodes\Tables;

use App\Application\Episodes\DuplicateEpisode;
use App\Enums\EpisodeStatus;
use App\Filament\Resources\Episodes\EpisodeResource;
use App\Filament\Support\AdminActions;
use App\Models\Episode;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Component;

class EpisodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Başlık')->searchable()->sortable(),

                TextColumn::make('show.name')->label('Program')->searchable()->sortable(),

                TextColumn::make('episode_number')->label('No')->sortable()->placeholder('—'),

                TextColumn::make('broadcast_at')->label('Yayın zamanı')->dateTime('d.m.Y H:i')->sortable()->placeholder('—'),

                TextColumn::make('main_topic')->label('Ana konu')->limit(40)->placeholder('—')->toggleable(),

                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (EpisodeStatus $state): string => $state->label())
                    ->color(fn (EpisodeStatus $state): string => match ($state) {
                        EpisodeStatus::Live => 'danger',
                        EpisodeStatus::Ready => 'success',
                        EpisodeStatus::Preparing => 'warning',
                        EpisodeStatus::Completed => 'info',
                        EpisodeStatus::Draft => 'gray',
                        EpisodeStatus::Archived => 'gray',
                    }),

                TextColumn::make('ai_personas_count')
                    ->label('AI karakter')
                    ->counts('aiPersonas')
                    ->sortable(),

                TextColumn::make('updated_at')->label('Güncellenme')->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('show')
                    ->label('Program')
                    ->relationship('show', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('status')
                    ->label('Durum')
                    ->options(collect(EpisodeStatus::cases())->mapWithKeys(fn (EpisodeStatus $c): array => [$c->value => $c->label()])),

                Filter::make('broadcast_at')
                    ->schema([
                        DatePicker::make('broadcast_from')->label('Yayın (başlangıç)'),
                        DatePicker::make('broadcast_until')->label('Yayın (bitiş)'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['broadcast_from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('broadcast_at', '>=', $date))
                            ->when($data['broadcast_until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('broadcast_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('prepare')
                    ->label('Program Hazırlığı')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->url(fn (Episode $record): string => EpisodeResource::getUrl('prepare', ['record' => $record])),
                Action::make('duplicate')
                    ->label('Yeni bölüm oluştur (kopyala)')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->schema([
                        Toggle::make('copy_instructions')
                            ->label('Genel yayın talimatlarını da kopyala')
                            ->default(false),
                    ])
                    ->modalHeading('Bu bölümden yeni bölüm hazırlığı başlat')
                    ->modalDescription('Program ve AI kadrosu kopyalanır. Konu, sorular, brifingler, yayın tarihi ve durum kopyalanmaz.')
                    ->action(function (Episode $record, array $data, Component $livewire): void {
                        $copy = app(DuplicateEpisode::class)($record, (bool) ($data['copy_instructions'] ?? false));

                        Notification::make()
                            ->success()
                            ->title('Yeni bölüm oluşturuldu')
                            ->body('Program Hazırlığı ekranında düzenlemeye devam edin.')
                            ->send();

                        $livewire->redirect(EpisodeResource::getUrl('prepare', ['record' => $copy]));
                    }),
                EditAction::make(),
                AdminActions::archive(EpisodeStatus::Archived),
                AdminActions::guardedDelete(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('broadcast_at', 'desc');
    }
}
