<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Prompting\AssembleImagePrompt;
use App\Models\Episode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AssembleImagePromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_frames_the_operator_brief_with_fixed_broadcast_rules(): void
    {
        $out = (new AssembleImagePrompt)->forOperatorBrief('bir pazar yeri, gün batımı');

        $this->assertStringContainsString('yayın ekranında', $out);
        $this->assertStringContainsString('OPERATÖR İSTEĞİ: bir pazar yeri, gün batımı', $out);
        $this->assertStringContainsString('KURALLAR:', $out);
        // The rules forbid on-image text so a caption cannot be faked.
        $this->assertStringContainsString('yazı', $out);
    }

    public function test_it_folds_in_episode_context_when_given(): void
    {
        $episode = Episode::factory()->create([
            'title' => 'Kadının Toplumdaki Yeri',
            'main_topic' => 'Cahiliye döneminden İslam\'a geçiş',
        ]);
        $episode->show()->update(['name' => 'Gerçeğin Peşinde']);
        $episode->load('show');

        $out = (new AssembleImagePrompt)->forOperatorBrief('bir pazar yeri', $episode);

        $this->assertStringContainsString('PROGRAM BAĞLAMI: Gerçeğin Peşinde — Kadının Toplumdaki Yeri', $out);
        $this->assertStringContainsString('BÖLÜMÜN ANA KONUSU: Cahiliye döneminden İslam\'a geçiş', $out);
        $this->assertStringContainsString('OPERATÖR İSTEĞİ: bir pazar yeri', $out);
    }

    public function test_a_blank_brief_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AssembleImagePrompt)->forOperatorBrief('   ');
    }
}
