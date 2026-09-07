<?php

declare(strict_types=1);

namespace App\Filament\Resources\Episodes\Pages;

use App\Application\Episodes\MakeEpisodeReady;
use App\Application\Episodes\Readiness\AssessEpisodeReadiness;
use App\Exceptions\InvalidEpisodeTransition;
use App\Filament\Resources\Episodes\EpisodeResource;
use App\Models\Episode;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * "Program Hazırlığı" - the single editorial workspace for preparing a weekly
 * episode. It is NOT a copy of the normal Episode form: it curates the summary,
 * the presenter brief, the episode AI brief, the AI line-up and the discussion
 * topics/questions (via the resource's relation managers), plus a live
 * readiness panel and a read-only summary.
 *
 * All readiness / transition logic lives in app/Application/Episodes - this
 * page only calls those services.
 */
class PrepareEpisode extends EditRecord
{
    protected static string $resource = EpisodeResource::class;

    protected static ?string $title = 'Program Hazırlığı';

    public function getBreadcrumb(): string
    {
        return 'Program Hazırlığı';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('makeReady')
                ->label('Yayına Hazırla')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Bölümü yayına hazırla')
                ->modalDescription('Editoryal hazırlık kontrol edilecek. Eksik madde varsa durum değişmez.')
                ->modalSubmitActionLabel('Kontrol et ve hazırla')
                ->visible(fn (): bool => $this->episode()->status->isPreparable())
                ->action(function (): void {
                    // Persist whatever the operator has typed BEFORE assessing:
                    // the readiness service reads the database, not Livewire form
                    // state. This validates the form too (required title etc.).
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    try {
                        $readiness = app(MakeEpisodeReady::class)($this->episode());
                    } catch (InvalidEpisodeTransition $e) {
                        Notification::make()
                            ->danger()
                            ->title('Durum değiştirilemedi')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();

                        $this->refreshFormData(['status']);

                        return;
                    }

                    if ($readiness->isReady()) {
                        Notification::make()
                            ->success()
                            ->title('Bölüm yayına hazır')
                            ->body('Durum "Yayına hazır" olarak güncellendi.')
                            ->send();

                        $this->refreshFormData(['status']);

                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('Bölüm henüz hazır değil')
                        ->body('Eksikler: '.implode(' · ', $readiness->blockingIssues()))
                        ->persistent()
                        ->send();
                }),

            Action::make('rehearse')
                ->label('AI Provası')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->url(fn (): string => EpisodeResource::getUrl('rehearse', ['record' => $this->episode()])),

            Action::make('normalEdit')
                ->label('Ayrıntılı düzenleme')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->url(fn (): string => EpisodeResource::getUrl('edit', ['record' => $this->episode()])),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Yayına hazırlık durumu')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->schema([
                    TextEntry::make('readiness')
                        ->hiddenLabel()
                        ->html()
                        ->state(fn (Get $get): string => view('filament.episodes.readiness-panel', [
                            'readiness' => app(AssessEpisodeReadiness::class)($this->readinessCandidate($get)),
                        ])->render()),
                ]),

            Section::make('Bölüm özeti')
                ->columns(2)
                ->schema([
                    TextEntry::make('show_name')
                        ->label('Program')
                        ->state(fn (): string => (string) ($this->episode()->show()->value('name') ?? '—')),
                    TextEntry::make('current_status')
                        ->label('Durum')
                        ->badge()
                        ->state(fn (): string => $this->episode()->status->label()),
                    TextInput::make('title')->label('Bölüm başlığı')->required()->maxLength(255)->live(onBlur: true),
                    TextInput::make('episode_number')->label('Bölüm no')->numeric()->minValue(0)->maxValue(100000),
                    DateTimePicker::make('broadcast_at')
                        ->label('Yayın zamanı')->seconds(false)->native(false)->live(onBlur: true),
                    Textarea::make('main_topic')->label('Ana konu')->rows(2)->maxLength(2000)->columnSpanFull()->live(onBlur: true),
                ]),

            Section::make('Program amacı')
                ->schema([
                    Textarea::make('purpose')->label('Amaç')->rows(3)->maxLength(4000)->hiddenLabel(),
                ]),

            Section::make('Genel yayın talimatları')
                ->schema([
                    Textarea::make('broadcast_instructions')->label('Yayın talimatları')->rows(4)->maxLength(8000)->hiddenLabel(),
                ]),

            Section::make('Sunucu Brifingi')
                ->description('Canlı sunucunun yayından hemen önce bakacağı kısa notlar. AI karakterine gitmez, bölüme aittir.')
                ->columns(2)
                ->schema([
                    Textarea::make('opening_notes')->label('Açılış notları')->rows(3)->maxLength(4000),
                    Textarea::make('key_points')->label('Ana noktalar')->rows(3)->maxLength(4000),
                    Textarea::make('questions_to_push')->label('Öne çıkarılacak sorular')->rows(3)->maxLength(4000),
                    Textarea::make('closing_notes')->label('Kapanış notları')->rows(3)->maxLength(4000),
                ]),

            Section::make('AI Brifingi')
                ->description('Bu bölüme özel AI yönlendirmesi. AiPersona sistem yönergesine YAZILMAZ; ileride runtime prompt birleştirmede kullanılır.')
                ->columns(2)
                ->schema([
                    Textarea::make('ai_objective')->label('AI hedefi')->rows(3)->maxLength(4000)->columnSpanFull()->live(onBlur: true),
                    TextInput::make('ai_tone_override')->label('Ton geçersiz kılma (opsiyonel)')->maxLength(120),
                    Select::make('response_length_guidance')
                        ->label('Yanıt uzunluğu rehberi')
                        ->options([
                            'brief' => 'Kısa (1-2 cümle)',
                            'moderate' => 'Orta (kısa paragraf)',
                            'detailed' => 'Ayrıntılı (uzun paragraf)',
                        ])
                        ->native(false),
                    Textarea::make('must_cover_points')->label('Mutlaka kapsanacak noktalar')->rows(3)->maxLength(4000)->live(onBlur: true),
                    Textarea::make('avoid_points')->label('Kaçınılacak noktalar')->rows(3)->maxLength(4000),
                ]),

            Section::make('Özet (salt-okunur)')
                ->icon(Heroicon::OutlinedEye)
                ->collapsible()
                ->schema([
                    TextEntry::make('summary')
                        ->hiddenLabel()
                        ->html()
                        ->state(fn (): string => view('filament.episodes.summary-panel', [
                            'episode' => $this->episode()->loadMissing(['show', 'lineup.aiPersona', 'topics.questions']),
                        ])->render()),
                ]),
        ]);
    }

    private function episode(): Episode
    {
        $record = $this->getRecord();

        if (! $record instanceof Episode) {
            throw new \LogicException('PrepareEpisode is bound to a non-Episode record.');
        }

        return $record;
    }

    /**
     * The episode as it WOULD be if the operator saved right now: the persisted
     * row with the unsaved scalar readiness fields from the live form applied on
     * top. Relation-based checks (topics, questions, line-up) still read the
     * database - those are edited through their own relation managers.
     */
    private function readinessCandidate(Get $get): Episode
    {
        $candidate = clone $this->episode();

        $candidate->forceFill([
            'title' => is_string($get('title')) ? $get('title') : null,
            'main_topic' => is_string($get('main_topic')) ? $get('main_topic') : null,
            'broadcast_at' => ($get('broadcast_at') ?: null),
            'ai_objective' => is_string($get('ai_objective')) ? $get('ai_objective') : null,
            'must_cover_points' => is_string($get('must_cover_points')) ? $get('must_cover_points') : null,
        ]);

        return $candidate;
    }
}
