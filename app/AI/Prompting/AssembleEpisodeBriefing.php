<?php

declare(strict_types=1);

namespace App\AI\Prompting;

use App\Models\AiPersona;
use App\Models\Episode;

/**
 * The single, provider-independent "everything the AI needs to know about this
 * prepared episode" builder.
 *
 * It turns existing editorial data — Show/Episode context, the persona that
 * will speak, that persona's per-episode slot, the episode AI brief, and the
 * FULL list of discussion topics + prepared questions (with their AI context)
 * — into one labelled Turkish string. It performs no vendor call, reads no
 * config, and appends no task/behaviour directive: callers add their own
 * (rehearsal = a single-question directive; realtime = a live-broadcast
 * directive).
 *
 * Presenter-only fields are DELIBERATELY excluded so they never reach the
 * model: Episode.opening_notes / key_points / questions_to_push /
 * closing_notes, EpisodeTopic.presenter_notes, EpisodeQuestion.presenter_notes.
 * (Episode.preparation_notes IS included — it is background context the AI
 * should have, not a host cue sheet.)
 */
final readonly class AssembleEpisodeBriefing
{
    /** @var array<string, string> */
    private const RESPONSE_LENGTH_KEYWORDS = [
        'brief' => 'Yanıtı kısa tut: en fazla birkaç cümle.',
        'moderate' => 'Orta uzunlukta yanıt ver: kısa bir paragraf.',
        'detailed' => 'Ayrıntılı yanıt ver: gerekirse birkaç paragraf.',
    ];

    /**
     * @param  string|null  $lineupInstructions  EpisodeAiPersona.episode_instructions
     *                                           for the speaking persona's slot in this episode
     */
    public function forEpisode(Episode $episode, AiPersona $persona, ?string $lineupInstructions = null): string
    {
        $episode->loadMissing(['show', 'topics.questions']);

        /** @var list<array{string, string|null}> $sections */
        $sections = [
            ['PROGRAM', $episode->show?->name],
            ['PROGRAM AÇIKLAMASI', $episode->show?->description],
            ['BÖLÜM BAŞLIĞI', $episode->title],
            ['BÖLÜM NUMARASI', $episode->episode_number === null ? null : (string) $episode->episode_number],
            ['ANA KONU', $episode->main_topic],
            ['PROGRAMIN AMACI', $episode->purpose],
            ['HAZIRLIK NOTLARI', $episode->preparation_notes],
            ['GENEL YAYIN TALİMATLARI', $episode->broadcast_instructions],

            ['AI KARAKTER KİMLİĞİ', $this->personaIdentity($persona)],
            ['UZMANLIK', $persona->expertise],
            ['KİŞİLİK', $persona->personality],
            ['KONUŞMA TARZI', $persona->speaking_style],
            ['KARAKTER SİSTEM YÖNERGESİ', $persona->system_prompt],
            ['BU BÖLÜME ÖZEL KARAKTER TALİMATLARI', $lineupInstructions],

            ['BU BÖLÜMÜN AI HEDEFİ', $episode->ai_objective],
            ['TON', $episode->ai_tone_override],
            ['MUTLAKA KAPSANACAK NOKTALAR', $episode->must_cover_points],
            ['KAÇINILACAK NOKTALAR', $episode->avoid_points],
            ['YANIT UZUNLUĞU', $this->responseLength($episode->response_length_guidance)],
        ];

        $rendered = [];

        foreach ($sections as [$label, $value]) {
            $value = $value === null ? '' : trim($value);

            if ($value !== '') {
                $rendered[] = $label.': '.$value;
            }
        }

        $flow = $this->topicFlow($episode);

        if ($flow !== '') {
            $rendered[] = $flow;
        }

        return trim(implode("\n\n", $rendered));
    }

    /**
     * All topics (sort_order) with their descriptions, AI context and every
     * prepared question (sort_order) + question AI context. Presenter notes are
     * not touched.
     */
    private function topicFlow(Episode $episode): string
    {
        $topics = $episode->topics;

        if ($topics->isEmpty()) {
            return '';
        }

        $lines = ['BÖLÜM AKIŞI — TARTIŞMA BAŞLIKLARI VE HAZIRLANMIŞ SORULAR:'];

        foreach ($topics as $index => $topic) {
            $lines[] = sprintf('%d. TARTIŞMA BAŞLIĞI: %s', $index + 1, trim($topic->title));

            $description = trim((string) $topic->description);
            if ($description !== '') {
                $lines[] = '   AÇIKLAMA: '.$description;
            }

            $topicContext = trim((string) $topic->ai_context);
            if ($topicContext !== '') {
                $lines[] = '   AI BAĞLAMI: '.$topicContext;
            }

            foreach ($topic->questions as $qIndex => $question) {
                $lines[] = sprintf('   %d.%d SORU: %s', $index + 1, $qIndex + 1, trim($question->question));

                $questionContext = trim((string) $question->ai_context);
                if ($questionContext !== '') {
                    $lines[] = '       SORU AI BAĞLAMI: '.$questionContext;
                }
            }
        }

        return implode("\n", $lines);
    }

    private function personaIdentity(AiPersona $persona): string
    {
        $title = is_string($persona->title) ? trim($persona->title) : '';

        return $title === '' ? $persona->name : $persona->name.' — '.$title;
    }

    /**
     * A known keyword maps to a fixed Turkish phrase; free-text guidance (i.e.
     * anything with whitespace) is passed through as-is; a stray unknown
     * single-token value is omitted.
     */
    private function responseLength(?string $guidance): ?string
    {
        if ($guidance === null) {
            return null;
        }

        $guidance = trim($guidance);

        if ($guidance === '') {
            return null;
        }

        if (isset(self::RESPONSE_LENGTH_KEYWORDS[$guidance])) {
            return self::RESPONSE_LENGTH_KEYWORDS[$guidance];
        }

        return str_contains($guidance, ' ') ? $guidance : null;
    }
}
