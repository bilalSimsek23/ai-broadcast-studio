<?php

declare(strict_types=1);

namespace App\Filament\Resources\Episodes\RelationManagers;

use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeAiPersona;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Manages the episode line-up as first-class {@see EpisodeAiPersona} rows
 * (relation: Episode::lineup()). Ordering, per-episode instructions and the
 * (episode_id, ai_persona_id) uniqueness are all plain columns on
 * episode_ai_persona - no pivot indirection.
 */
class AiPersonasRelationManager extends RelationManager
{
    protected static string $relationship = 'lineup';

    protected static ?string $title = 'AI Karakterleri';

    /** Pivot fields, edited on an existing slot. The persona is fixed once assigned. */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sort_order')->label('Sıra')->numeric()->minValue(0)->default(0)->required(),
            Textarea::make('episode_instructions')->label('Bu bölüme özel talimatlar')->rows(3)->maxLength(4000),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (EpisodeAiPersona $record): string => $record->aiPersona->name)
            ->columns([
                TextColumn::make('sort_order')->label('Sıra')->sortable(),
                TextColumn::make('aiPersona.name')->label('Ad')->searchable(),
                TextColumn::make('aiPersona.title')->label('Unvan')->placeholder('—'),
                TextColumn::make('episode_instructions')->label('Bölüm talimatı')->limit(50)->placeholder('—'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('Karakter ata')
                    ->modalHeading('Bölüme AI karakteri ata')
                    ->schema([
                        Select::make('ai_persona_id')
                            ->label('AI Karakteri')
                            ->options(fn (): array => $this->unassignedPersonaOptions())
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('sort_order')->label('Sıra')->numeric()->minValue(0)->default(0)->required(),
                        Textarea::make('episode_instructions')->label('Bu bölüme özel talimatlar')->rows(3)->maxLength(4000),
                    ])
                    // The select already hides assigned personas; the unique
                    // index is the final guard. Wrap in a transaction so the
                    // race (two operators, same persona) surfaces as the
                    // friendly notification, not a raw 500.
                    ->action(function (array $data, CreateAction $action): void {
                        $episode = $this->getOwnerRecord();

                        if (! $episode instanceof Episode) {
                            return;
                        }

                        try {
                            DB::transaction(fn () => $episode->lineup()->create([
                                'ai_persona_id' => $data['ai_persona_id'],
                                'sort_order' => $data['sort_order'] ?? 0,
                                'episode_instructions' => $data['episode_instructions'] ?? null,
                            ]));
                        } catch (UniqueConstraintViolationException) {
                            Notification::make()
                                ->danger()
                                ->title('Karakter atanamadı')
                                ->body('Bu AI karakteri bu bölüme zaten atanmış.')
                                ->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()->success()->title('Karakter atandı')->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()->label('Düzenle'),
                DeleteAction::make()->label('Çıkar'),
            ]);
    }

    /**
     * Active-and-inactive personas not already in this episode's line-up.
     *
     * @return array<int, string>
     */
    private function unassignedPersonaOptions(): array
    {
        $episode = $this->getOwnerRecord();

        $assigned = $episode instanceof Episode
            ? $episode->lineup()->pluck('ai_persona_id')->all()
            : [];

        return AiPersona::query()
            ->whereNotIn('id', $assigned)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
