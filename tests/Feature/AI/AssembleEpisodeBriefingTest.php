<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Prompting\AssembleEpisodeBriefing;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssembleEpisodeBriefingTest extends TestCase
{
    use RefreshDatabase;

    private function briefing(): AssembleEpisodeBriefing
    {
        return $this->app->make(AssembleEpisodeBriefing::class);
    }

    private function episode(array $attributes = []): Episode
    {
        return Episode::factory()->create(array_merge([
            'title' => 'Kadının Toplumdaki Yeri',
            'episode_number' => 1,
            'main_topic' => 'Cahiliye döneminden İslam\'a geçiş',
            'purpose' => 'İzleyiciyi tarihsel bağlamla buluşturmak',
            'preparation_notes' => 'Tartışma formatında ilerleyecek.',
            'broadcast_instructions' => 'Saygılı ve kanıta dayalı bir tartışma yürüt.',
            'opening_notes' => 'PRESENTER-ONLY-OPENING',
            'key_points' => 'PRESENTER-ONLY-KEYPOINTS',
            'questions_to_push' => 'PRESENTER-ONLY-PUSH',
            'closing_notes' => 'PRESENTER-ONLY-CLOSING',
            'ai_objective' => 'Karşı argümanları nazikçe zorla.',
            'ai_tone_override' => 'ölçülü',
            'must_cover_points' => 'Miras hakları. Evlilik.',
            'avoid_points' => 'Siyasi polemik.',
            'response_length_guidance' => 'moderate',
        ], $attributes));
    }

    private function persona(array $attributes = []): AiPersona
    {
        return AiPersona::factory()->create(array_merge([
            'name' => 'Hikmet',
            'title' => 'Tarih ve Medeniyet Araştırmacısı',
            'expertise' => 'İslam tarihi ve toplumsal cinsiyet',
            'personality' => 'Sakin, meraklı, kanıt odaklı',
            'speaking_style' => 'Kısa cümleler, sade dil',
            'system_prompt' => 'PERSONA-SYSTEM-PROMPT: Her zaman kaynak belirt.',
        ], $attributes));
    }

    public function test_it_carries_every_prepared_episode_and_persona_field(): void
    {
        $episode = $this->episode();
        $episode->show()->update(['name' => 'Gerçeğin Peşinde', 'description' => 'Haftalık müzakere programı.']);

        $out = $this->briefing()->forEpisode($episode, $this->persona(), 'Bu bölümde daha çok dinle.');

        foreach ([
            'PROGRAM: Gerçeğin Peşinde',
            'PROGRAM AÇIKLAMASI: Haftalık müzakere programı.',
            'BÖLÜM BAŞLIĞI: Kadının Toplumdaki Yeri',
            'BÖLÜM NUMARASI: 1',
            'ANA KONU: Cahiliye döneminden İslam\'a geçiş',
            'PROGRAMIN AMACI: İzleyiciyi tarihsel bağlamla buluşturmak',
            'HAZIRLIK NOTLARI: Tartışma formatında ilerleyecek.',
            'GENEL YAYIN TALİMATLARI: Saygılı ve kanıta dayalı bir tartışma yürüt.',
            'AI KARAKTER KİMLİĞİ: Hikmet — Tarih ve Medeniyet Araştırmacısı',
            'UZMANLIK: İslam tarihi ve toplumsal cinsiyet',
            'KİŞİLİK: Sakin, meraklı, kanıt odaklı',
            'KONUŞMA TARZI: Kısa cümleler, sade dil',
            'KARAKTER SİSTEM YÖNERGESİ: PERSONA-SYSTEM-PROMPT: Her zaman kaynak belirt.',
            'BU BÖLÜME ÖZEL KARAKTER TALİMATLARI: Bu bölümde daha çok dinle.',
            'BU BÖLÜMÜN AI HEDEFİ: Karşı argümanları nazikçe zorla.',
            'TON: ölçülü',
            'MUTLAKA KAPSANACAK NOKTALAR: Miras hakları. Evlilik.',
            'KAÇINILACAK NOKTALAR: Siyasi polemik.',
            'YANIT UZUNLUĞU: Orta uzunlukta yanıt ver: kısa bir paragraf.',
        ] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    public function test_presenter_only_fields_never_reach_the_briefing(): void
    {
        $episode = $this->episode();
        $topic = EpisodeTopic::factory()->forEpisode($episode)->create([
            'title' => 'Konu A',
            'presenter_notes' => 'PRESENTER-ONLY-TOPIC-NOTE',
        ]);
        EpisodeQuestion::factory()->forTopic($topic)->create([
            'question' => 'Soru A?',
            'presenter_notes' => 'PRESENTER-ONLY-QUESTION-NOTE',
        ]);

        $out = $this->briefing()->forEpisode($episode, $this->persona());

        $this->assertStringNotContainsString('PRESENTER-ONLY-OPENING', $out);
        $this->assertStringNotContainsString('PRESENTER-ONLY-KEYPOINTS', $out);
        $this->assertStringNotContainsString('PRESENTER-ONLY-PUSH', $out);
        $this->assertStringNotContainsString('PRESENTER-ONLY-CLOSING', $out);
        $this->assertStringNotContainsString('PRESENTER-ONLY-TOPIC-NOTE', $out);
        $this->assertStringNotContainsString('PRESENTER-ONLY-QUESTION-NOTE', $out);
        // ...but the AI-facing parts of the same topic/question ARE present.
        $this->assertStringContainsString('TARTIŞMA BAŞLIĞI: Konu A', $out);
        $this->assertStringContainsString('SORU: Soru A?', $out);
    }

    public function test_all_topics_and_all_questions_are_included_in_sort_order(): void
    {
        $episode = $this->episode();

        foreach (['Birinci Başlık', 'İkinci Başlık', 'Üçüncü Başlık'] as $i => $title) {
            $topic = EpisodeTopic::factory()->forEpisode($episode)->create([
                'title' => $title,
                'description' => $title.' açıklaması',
                'ai_context' => $title.' AI bağlamı',
                'sort_order' => $i + 1,
            ]);
            foreach (['Soru bir', 'Soru iki'] as $qi => $q) {
                EpisodeQuestion::factory()->forTopic($topic)->create([
                    'question' => $title.' — '.$q.'?',
                    'ai_context' => $title.' '.$q.' bağlam',
                    'sort_order' => $qi + 1,
                ]);
            }
        }

        $out = $this->briefing()->forEpisode($episode, $this->persona());

        foreach (['Birinci Başlık', 'İkinci Başlık', 'Üçüncü Başlık'] as $title) {
            $this->assertStringContainsString('TARTIŞMA BAŞLIĞI: '.$title, $out);
            $this->assertStringContainsString('SORU: '.$title.' — Soru bir?', $out);
            $this->assertStringContainsString('SORU: '.$title.' — Soru iki?', $out);
        }

        // Ordered.
        $this->assertLessThan(mb_strpos($out, 'İkinci Başlık'), mb_strpos($out, 'Birinci Başlık'));
        $this->assertLessThan(mb_strpos($out, 'Üçüncü Başlık'), mb_strpos($out, 'İkinci Başlık'));
    }

    public function test_blank_editorial_and_persona_fields_are_skipped(): void
    {
        $episode = $this->episode([
            'purpose' => null,
            'preparation_notes' => '   ',
            'ai_tone_override' => null,
            'avoid_points' => null,
        ]);

        $out = $this->briefing()->forEpisode(
            $episode,
            $this->persona(['title' => null, 'system_prompt' => null, 'expertise' => null]),
            null,
        );

        $this->assertStringNotContainsString('PROGRAMIN AMACI:', $out);
        $this->assertStringNotContainsString('HAZIRLIK NOTLARI:', $out);
        $this->assertStringNotContainsString('TON:', $out);
        $this->assertStringNotContainsString('KAÇINILACAK NOKTALAR:', $out);
        $this->assertStringNotContainsString('KARAKTER SİSTEM YÖNERGESİ:', $out);
        $this->assertStringNotContainsString('UZMANLIK:', $out);
        $this->assertStringNotContainsString('BU BÖLÜME ÖZEL KARAKTER TALİMATLARI:', $out);
        $this->assertStringNotContainsString('  —  ', $out);
        $this->assertStringContainsString('AI KARAKTER KİMLİĞİ: Hikmet', $out);
    }

    public function test_the_real_demo_episode_briefing_stays_within_a_practical_size(): void
    {
        $this->artisan('demo:seed-gercegin-pesinde')->assertSuccessful();

        /** @var Episode $episode */
        $episode = Episode::query()->with(['show', 'lineup.aiPersona', 'topics.questions'])->firstOrFail();
        $slot = $episode->lineup->first();

        $out = $this->briefing()->forEpisode($episode, $slot->aiPersona, $slot->episode_instructions);

        // Diagnostic figure for the delivery report (not a hard gate).
        fwrite(STDERR, "\n[diagnostic] Gerçeğin Peşinde / Episode 1 briefing: "
            .mb_strlen($out).' characters ('.str_word_count($out).' words)'."\n");

        // Sanity: complete but not runaway. No blind truncation is applied.
        $this->assertGreaterThan(2_000, mb_strlen($out));
        $this->assertLessThan(20_000, mb_strlen($out));
        $this->assertStringContainsString('Hikmet', $out);
        $this->assertStringContainsString('Gerçeğin Peşinde', $out);
    }

    public function test_response_length_guidance_keyword_prose_and_stray_token(): void
    {
        $prose = 'Default response: approximately 80-140 spoken Turkish words.';

        $this->assertStringContainsString(
            'YANIT UZUNLUĞU: '.$prose,
            $this->briefing()->forEpisode($this->episode(['response_length_guidance' => $prose]), $this->persona()),
        );

        $this->assertStringNotContainsString(
            'YANIT UZUNLUĞU:',
            $this->briefing()->forEpisode($this->episode(['response_length_guidance' => 'sledgehammer']), $this->persona()),
        );
    }
}
