<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Enums\Role;
use App\AI\Prompting\AssembleRehearsalPrompt;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\EpisodeTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AssembleRehearsalPromptTest extends TestCase
{
    use RefreshDatabase;

    private function assembler(): AssembleRehearsalPrompt
    {
        return $this->app->make(AssembleRehearsalPrompt::class);
    }

    private function episode(array $attributes = []): Episode
    {
        return Episode::factory()->create(array_merge([
            'title' => 'Kadının Toplumdaki Yeri',
            'main_topic' => 'Cahiliye döneminden İslam\'a geçiş',
            'purpose' => 'İzleyiciyi tarihsel bağlamla buluşturmak',
            'broadcast_instructions' => 'Saygılı ve kanıta dayalı bir tartışma yürüt.',
            'ai_objective' => 'Karşı argümanları nazikçe zorla.',
            'ai_tone_override' => 'ölçülü',
            'must_cover_points' => 'Miras hakları. Evlilik.',
            'avoid_points' => 'Siyasi polemik.',
            'response_length_guidance' => 'moderate',
        ], $attributes))->loadMissing('show');
    }

    private function persona(array $attributes = []): AiPersona
    {
        return AiPersona::factory()->create(array_merge([
            'name' => 'Hikmet',
            'title' => 'Tarih araştırmacısı',
            'expertise' => 'İslam tarihi ve toplumsal cinsiyet',
            'personality' => 'Sakin, meraklı, kanıt odaklı',
            'speaking_style' => 'Kısa cümleler, sade dil',
            'system_prompt' => 'Her zaman kaynak belirt.',
            'ai_model' => 'large',
        ], $attributes));
    }

    public function test_it_builds_one_user_message_from_the_trimmed_presenter_question(): void
    {
        $request = $this->assembler()->assemble(
            episode: $this->episode(),
            persona: $this->persona(),
            presenterQuestion: "  Miras hukuku nasıl değişti?  \n",
        );

        $this->assertCount(1, $request->messages->all());
        $this->assertSame(Role::User, $request->messages->first()->role);
        $this->assertSame('Miras hukuku nasıl değişti?', $request->messages->first()->content);
    }

    public function test_the_logical_model_key_comes_from_the_persona_then_falls_back_to_default(): void
    {
        $withModel = $this->assembler()->assemble(
            episode: $this->episode(),
            persona: $this->persona(['ai_model' => 'small']),
            presenterQuestion: 'Soru?',
        );
        $this->assertSame('small', $withModel->model);

        $withoutModel = $this->assembler()->assemble(
            episode: $this->episode(),
            persona: $this->persona(['ai_model' => null]),
            presenterQuestion: 'Soru?',
        );
        $this->assertSame('default', $withoutModel->model);
    }

    public function test_the_system_instructions_carry_every_populated_editorial_section(): void
    {
        $episode = $this->episode();
        $topic = EpisodeTopic::factory()->forEpisode($episode)->create([
            'title' => 'Miras ve mülkiyet',
            'description' => 'Kadının mülk edinme hakkı',
            'ai_context' => 'Bölgesel farklara dikkat çek.',
        ]);
        $question = EpisodeQuestion::factory()->forTopic($topic)->create([
            'question' => 'Miras payları nasıl belirlendi?',
            'ai_context' => 'Kabile geleneği ile ayet hükmünü karşılaştır.',
        ]);

        $request = $this->assembler()->assemble(
            episode: $episode,
            persona: $this->persona(),
            presenterQuestion: 'Anlatır mısın?',
            topic: $topic,
            question: $question,
            lineupInstructions: 'Bu bölümde daha çok dinle, az konuş.',
        );

        $system = $request->systemInstructions ?? '';

        foreach ([
            'PROGRAM: ',
            'BÖLÜM BAŞLIĞI: Kadının Toplumdaki Yeri',
            'ANA KONU: Cahiliye döneminden İslam\'a geçiş',
            'PROGRAMIN AMACI: İzleyiciyi tarihsel bağlamla buluşturmak',
            'GENEL YAYIN TALİMATLARI: Saygılı ve kanıta dayalı bir tartışma yürüt.',
            'AI KARAKTER KİMLİĞİ: Hikmet — Tarih araştırmacısı',
            'UZMANLIK: İslam tarihi ve toplumsal cinsiyet',
            'KİŞİLİK: Sakin, meraklı, kanıt odaklı',
            'KONUŞMA TARZI: Kısa cümleler, sade dil',
            'KARAKTER SİSTEM YÖNERGESİ: Her zaman kaynak belirt.',
            'BU BÖLÜME ÖZEL KARAKTER TALİMATLARI: Bu bölümde daha çok dinle, az konuş.',
            'BU BÖLÜMÜN AI HEDEFİ: Karşı argümanları nazikçe zorla.',
            'TON: ölçülü',
            'MUTLAKA KAPSANACAK NOKTALAR: Miras hakları. Evlilik.',
            'KAÇINILACAK NOKTALAR: Siyasi polemik.',
            'YANIT UZUNLUĞU: Orta uzunlukta yanıt ver: kısa bir paragraf.',
            'SEÇİLİ KONU: Miras ve mülkiyet',
            'SEÇİLİ KONU AÇIKLAMASI: Kadının mülk edinme hakkı',
            'SEÇİLİ KONU AI BAĞLAMI: Bölgesel farklara dikkat çek.',
            'SEÇİLİ SORU AI BAĞLAMI: Kabile geleneği ile ayet hükmünü karşılaştır.',
            'GÖREV: ',
        ] as $needle) {
            $this->assertStringContainsString($needle, $system);
        }
    }

    public function test_blank_editorial_fields_produce_no_empty_labelled_sections(): void
    {
        $request = $this->assembler()->assemble(
            episode: $this->episode([
                'purpose' => null,
                'broadcast_instructions' => '   ',
                'ai_tone_override' => null,
                'avoid_points' => null,
                'response_length_guidance' => null,
            ]),
            persona: $this->persona(['title' => null, 'system_prompt' => null]),
            presenterQuestion: 'Soru?',
        );

        $system = $request->systemInstructions ?? '';

        $this->assertStringNotContainsString('PROGRAMIN AMACI:', $system);
        $this->assertStringNotContainsString('GENEL YAYIN TALİMATLARI:', $system);
        $this->assertStringNotContainsString('TON:', $system);
        $this->assertStringNotContainsString('KAÇINILACAK NOKTALAR:', $system);
        $this->assertStringNotContainsString('YANIT UZUNLUĞU:', $system);
        $this->assertStringNotContainsString('KARAKTER SİSTEM YÖNERGESİ:', $system);
        $this->assertStringNotContainsString('  —  ', $system);
        $this->assertStringContainsString('AI KARAKTER KİMLİĞİ: Hikmet', $system);
    }

    public function test_an_unknown_response_length_guidance_value_is_omitted(): void
    {
        $request = $this->assembler()->assemble(
            episode: $this->episode(['response_length_guidance' => 'sledgehammer']),
            persona: $this->persona(),
            presenterQuestion: 'Soru?',
        );

        $this->assertStringNotContainsString('YANIT UZUNLUĞU:', $request->systemInstructions ?? '');
    }

    public function test_topic_and_question_sections_are_absent_when_not_selected(): void
    {
        $request = $this->assembler()->assemble(
            episode: $this->episode(),
            persona: $this->persona(),
            presenterQuestion: 'Soru?',
        );

        $system = $request->systemInstructions ?? '';

        $this->assertStringNotContainsString('SEÇİLİ KONU', $system);
        $this->assertStringNotContainsString('SEÇİLİ SORU', $system);
    }

    public function test_a_blank_presenter_question_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->assembler()->assemble(
            episode: $this->episode(),
            persona: $this->persona(),
            presenterQuestion: "   \n\t",
        );
    }
}
