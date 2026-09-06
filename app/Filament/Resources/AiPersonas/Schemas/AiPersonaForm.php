<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPersonas\Schemas;

use App\Enums\AiPersonaStatus;
use App\Filament\Support\AdminActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiPersonaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Kimlik')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Ad')->required()->maxLength(255),
                    TextInput::make('title')->label('Unvan')->maxLength(255),
                    Textarea::make('biography')->label('Biyografi')->rows(4)->maxLength(4000)->columnSpanFull(),
                ]),

            Section::make('Uzmanlık ve davranış')
                ->columns(2)
                ->schema([
                    Textarea::make('expertise')->label('Uzmanlık')->rows(3)->maxLength(2000),
                    Textarea::make('personality')->label('Kişilik')->rows(3)->maxLength(2000),
                    TextInput::make('speaking_style')->label('Konuşma stili')->maxLength(255),
                    Textarea::make('system_prompt')->label('Sistem yönergesi (system prompt)')->rows(5)->maxLength(8000)->columnSpanFull(),
                ]),

            Section::make('AI yapılandırması')
                ->description('Yalnızca config/ai.php içindeki mantıksal anahtarlar. Sağlayıcı adı / ham model kimliği girilemez.')
                ->columns(2)
                ->schema([
                    Select::make('ai_provider')
                        ->label('Metin sağlayıcı profili')
                        ->options(AdminActions::logicalKeyOptions('ai_provider'))
                        ->native(false)
                        ->searchable(),
                    Select::make('ai_model')
                        ->label('Model kademesi')
                        ->options(AdminActions::logicalKeyOptions('ai_model'))
                        ->native(false)
                        ->searchable(),
                    Select::make('voice_provider')
                        ->label('Ses profili')
                        ->options(AdminActions::logicalKeyOptions('voice_provider'))
                        ->native(false)
                        ->searchable(),
                    Select::make('voice_id')
                        ->label('Ses anahtarı')
                        ->options(AdminActions::logicalKeyOptions('voice_id'))
                        ->native(false)
                        ->searchable(),
                ]),

            Section::make('Görsel / ekran ayarları')
                ->description('Yayın ekranındaki temel yerleşim. Ayrıntılı studio-screen tasarımı bu görevin dışındadır.')
                ->columns(2)
                ->statePath('screen_settings')
                ->schema([
                    TextInput::make('display_name')->label('Ekranda görünen ad')->maxLength(120),
                    TextInput::make('display_title')->label('Ekranda görünen unvan')->maxLength(120),
                    Select::make('avatar_position')
                        ->label('Avatar konumu')
                        ->options(['left' => 'Sol', 'center' => 'Orta', 'right' => 'Sağ'])
                        ->default('right')
                        ->native(false)
                        ->required(),
                    TextInput::make('avatar_size')
                        ->label('Avatar boyutu (%)')
                        ->numeric()
                        ->minValue(25)
                        ->maxValue(200)
                        ->default(100)
                        ->required()
                        ->suffix('%'),
                    Toggle::make('lower_third_enabled')
                        ->label('Alt bant (lower third) göster')
                        ->default(true),
                ]),

            Section::make('Durum')
                ->schema([
                    Select::make('status')
                        ->label('Durum')
                        ->options(collect(AiPersonaStatus::cases())->mapWithKeys(fn (AiPersonaStatus $c): array => [$c->value => $c->label()]))
                        ->default(AiPersonaStatus::Draft->value)
                        ->required()
                        ->native(false),
                ]),
        ]);
    }
}
