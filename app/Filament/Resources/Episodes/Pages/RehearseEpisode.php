<?php

declare(strict_types=1);

namespace App\Filament\Resources\Episodes\Pages;

use App\AI\Dtos\TextGenerationResponse;
use App\AI\Exceptions\AiConfigurationException;
use App\AI\Exceptions\ProviderException;
use App\AI\GenerateText;
use App\AI\Prompting\AssembleRehearsalPrompt;
use App\Filament\Resources\Episodes\EpisodeResource;
use App\Models\Episode;
use App\Models\EpisodeAiPersona;
use App\Models\EpisodeQuestion;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * "AI Provası" — a rehearsal / testing surface on the Episode preparation
 * workflow. The operator picks one of the episode's assigned AI personas,
 * optionally anchors on an existing topic and/or question, edits the presenter
 * question, and generates one response.
 *
 * This is NOT the live broadcast runtime:
 *  - nothing is persisted (no conversation history, no session);
 *  - it only reads existing editorial data and calls App\AI\GenerateText;
 *  - prompt assembly lives in App\AI\Prompting\AssembleRehearsalPrompt, vendor
 *    calls behind App\AI\GenerateText — this page orchestrates only.
 *
 * Safety: only personas in THIS episode's line-up are selectable and every
 * selected topic/question is re-validated against this episode server-side
 * before a call. Provider failures surface as a generic notification; the
 * detail goes to the log, never to the screen (no credentials, no raw vendor
 * error).
 */
class RehearseEpisode extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EpisodeResource::class;

    protected string $view = 'filament.episodes.rehearse-episode';

    protected static ?string $title = 'AI Provası';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?string $responseText = null;

    public ?string $responseNote = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->rehearsalForm()->fill();
    }

    public function getBreadcrumb(): string
    {
        return 'AI Provası';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToPreparation')
                ->label('Program Hazırlığına dön')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => EpisodeResource::getUrl('prepare', ['record' => $this->episode()])),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Prova ayarları')
                    ->columns(2)
                    ->schema([
                        Select::make('ai_persona_id')
                            ->label('AI persona')
                            ->options(fn (): array => $this->personaOptions())
                            ->required()
                            ->native(false)
                            ->helperText('Yalnızca bu bölümün kadrosundaki personalar.'),

                        Select::make('episode_topic_id')
                            ->label('Mevcut konu (opsiyonel)')
                            ->options(fn (): array => $this->episode()->topics()->pluck('title', 'id')->all())
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('episode_question_id', null)),

                        Select::make('episode_question_id')
                            ->label('Mevcut soru (opsiyonel)')
                            ->options(fn (Get $get): array => $this->questionOptions($get))
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $question = $this->findQuestionForEpisode($state);

                                if ($question !== null) {
                                    $set('presenter_question', $question->question);
                                }
                            })
                            ->helperText('Seçince sunucu sorusu alanına kopyalanır; göndermeden önce düzenlenebilir.')
                            ->columnSpanFull(),

                        Textarea::make('presenter_question')
                            ->label('Sunucu sorusu')
                            ->required()
                            ->rows(3)
                            ->maxLength(4000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function generate(): void
    {
        $data = $this->rehearsalForm()->getState();
        $episode = $this->episode();

        $slot = $episode->lineup()
            ->with('aiPersona')
            ->where('ai_persona_id', $data['ai_persona_id'] ?? null)
            ->first();

        if ($slot === null) {
            $this->rejectSelection('Seçilen persona bu bölümün kadrosunda değil.');

            return;
        }

        $topic = null;

        if (! empty($data['episode_topic_id'])) {
            $topic = $episode->topics()->whereKey($data['episode_topic_id'])->first();

            if ($topic === null) {
                $this->rejectSelection('Seçilen konu bu bölüme ait değil.');

                return;
            }
        }

        $question = null;

        if (! empty($data['episode_question_id'])) {
            if ($topic === null) {
                $this->rejectSelection('Soru seçmek için önce bu bölüme ait bir konu seçin.');

                return;
            }

            $question = EpisodeQuestion::query()
                ->whereKey($data['episode_question_id'])
                ->where('episode_topic_id', $topic->getKey())
                ->first();

            if ($question === null) {
                $this->rejectSelection('Seçilen soru, seçilen konuya ait değil.');

                return;
            }
        }

        $presenterQuestion = is_string($data['presenter_question'] ?? null) ? $data['presenter_question'] : '';

        $request = app(AssembleRehearsalPrompt::class)->assemble(
            episode: $episode->loadMissing('show'),
            persona: $slot->aiPersona,
            presenterQuestion: $presenterQuestion,
            topic: $topic,
            question: $question,
            lineupInstructions: $slot->episode_instructions,
        );

        try {
            $response = app(GenerateText::class)->generate($request);
        } catch (ProviderException|AiConfigurationException $e) {
            report($e);

            $this->responseText = null;
            $this->responseNote = null;

            Notification::make()
                ->danger()
                ->title('AI yanıtı üretilemedi')
                ->body('Sağlayıcıya ulaşılırken bir sorun oluştu. Ayrıntı sistem günlüğüne yazıldı.')
                ->persistent()
                ->send();

            return;
        }

        $this->responseText = $response->text;
        $this->responseNote = $this->describeResponse($response);

        Notification::make()->success()->title('AI yanıtı hazır')->send();
    }

    /**
     * @return array<int, string>
     */
    private function personaOptions(): array
    {
        return $this->episode()->lineup()
            ->with('aiPersona')
            ->get()
            ->mapWithKeys(fn (EpisodeAiPersona $slot): array => [
                $slot->ai_persona_id => $slot->aiPersona->name,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function questionOptions(Get $get): array
    {
        $topicId = $get('episode_topic_id');

        if (empty($topicId)) {
            return [];
        }

        return EpisodeQuestion::query()
            ->where('episode_topic_id', $topicId)
            ->whereHas('topic', fn ($query) => $query->where('episode_id', $this->episode()->getKey()))
            ->orderBy('sort_order')
            ->pluck('question', 'id')
            ->all();
    }

    private function findQuestionForEpisode(mixed $questionId): ?EpisodeQuestion
    {
        if (empty($questionId)) {
            return null;
        }

        return EpisodeQuestion::query()
            ->whereKey($questionId)
            ->whereHas('topic', fn ($query) => $query->where('episode_id', $this->episode()->getKey()))
            ->first();
    }

    private function rejectSelection(string $message): void
    {
        $this->responseText = null;
        $this->responseNote = null;

        Notification::make()
            ->danger()
            ->title('Geçersiz seçim')
            ->body($message)
            ->send();
    }

    private function describeResponse(TextGenerationResponse $response): string
    {
        $parts = [sprintf(
            'Mantıksal model: %s / %s',
            $response->metadata->logicalProvider,
            $response->metadata->logicalModel,
        )];

        if ($response->metadata->finishReason !== null) {
            $parts[] = 'Bitiş nedeni: '.$response->metadata->finishReason->value;
        }

        $total = $response->usage->totalTokens();

        if ($total !== null) {
            $parts[] = sprintf(
                'Token: %d girdi + %d çıktı = %d',
                $response->usage->inputTokens ?? 0,
                $response->usage->outputTokens ?? 0,
                $total,
            );
        }

        return implode(' · ', $parts);
    }

    private function episode(): Episode
    {
        $record = $this->getRecord();

        if (! $record instanceof Episode) {
            throw new \LogicException('RehearseEpisode is bound to a non-Episode record.');
        }

        return $record;
    }

    private function rehearsalForm(): Schema
    {
        $form = $this->getSchema('form');

        if ($form === null) {
            throw new \LogicException('The rehearsal form schema is not registered.');
        }

        return $form;
    }
}
