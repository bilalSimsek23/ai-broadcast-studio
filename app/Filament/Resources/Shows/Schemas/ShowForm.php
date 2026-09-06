<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shows\Schemas;

use App\Enums\ShowStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ShowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Ad')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (string $operation, ?string $state, Get $get, Set $set): void {
                    // Convenience only: pre-fill the slug while creating and
                    // only while it is still empty. Never clobber a slug the
                    // user typed, and never touch it on edit.
                    if ($operation === 'create' && blank($get('slug'))) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),

            TextInput::make('slug')
                ->label('Kısa ad (slug)')
                ->required()
                ->maxLength(255)
                // Must already be canonical: lowercase, digits, single hyphens,
                // no leading/trailing/double hyphen. The value validated for
                // uniqueness is exactly the value persisted (no late rewrite),
                // so a canonical collision surfaces as a form error, not a DB
                // exception.
                ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->validationMessages([
                    'regex' => 'Yalnızca küçük harf, rakam ve tek tire kullanın (örn. "gece-tartismasi").',
                ])
                ->unique(ignoreRecord: true)
                ->helperText('Yalnızca küçük harf, rakam ve tire. Dışa açık bağlantılarda kullanılır.'),

            Textarea::make('description')
                ->label('Açıklama')
                ->rows(4)
                ->maxLength(2000),

            Select::make('status')
                ->label('Durum')
                ->options(collect(ShowStatus::cases())->mapWithKeys(fn (ShowStatus $c): array => [$c->value => $c->label()]))
                ->default(ShowStatus::Draft->value)
                ->required()
                ->native(false),
        ]);
    }
}
