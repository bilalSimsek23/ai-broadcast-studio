<?php

declare(strict_types=1);

namespace App\Filament\Resources\Episodes\Schemas;

use App\Enums\EpisodeStatus;
use App\Models\Episode;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EpisodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bölüm')
                ->columns(2)
                ->schema([
                    Select::make('show_id')
                        ->label('Program')
                        ->relationship('show', 'name')
                        ->required()
                        ->searchable()
                        ->preload(),

                    TextInput::make('title')->label('Başlık')->required()->maxLength(255),

                    TextInput::make('episode_number')
                        ->label('Bölüm no')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100000),

                    DateTimePicker::make('broadcast_at')
                        ->label('Yayın zamanı')
                        ->seconds(false)
                        ->native(false),

                    Select::make('status')
                        ->label('Durum')
                        // "Yayına hazır" is NOT directly settable - it goes
                        // through "Program Hazırlığı → Yayına Hazırla", which
                        // runs the readiness check (the model also enforces
                        // this). Live/Completed transitions are out of scope.
                        // An episode already in one of those states still shows
                        // its current value.
                        ->options(function (?Episode $record): array {
                            $editable = [EpisodeStatus::Draft, EpisodeStatus::Preparing, EpisodeStatus::Archived];
                            if ($record !== null && ! in_array($record->status, $editable, true)) {
                                $editable[] = $record->status;
                            }

                            return collect($editable)->mapWithKeys(fn (EpisodeStatus $c): array => [$c->value => $c->label()])->all();
                        })
                        ->default(EpisodeStatus::Draft->value)
                        ->required()
                        ->native(false),
                ]),

            Section::make('Program hazırlığı')
                ->columns(1)
                ->schema([
                    Textarea::make('main_topic')->label('Ana konu')->rows(2)->maxLength(2000),
                    Textarea::make('purpose')->label('Amaç')->rows(3)->maxLength(4000),
                    Textarea::make('preparation_notes')->label('Hazırlık notları')->rows(4)->maxLength(8000),
                    Textarea::make('broadcast_instructions')->label('Yayın talimatları')->rows(4)->maxLength(8000),
                ]),
        ]);
    }
}
