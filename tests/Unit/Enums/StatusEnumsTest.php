<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AiPersonaStatus;
use App\Enums\EpisodeStatus;
use App\Enums\ShowStatus;
use PHPUnit\Framework\TestCase;

class StatusEnumsTest extends TestCase
{
    public function test_show_status_cases(): void
    {
        $this->assertSame(
            ['draft', 'active', 'inactive', 'archived'],
            array_map(fn (ShowStatus $s) => $s->value, ShowStatus::cases()),
        );
        $this->assertTrue(ShowStatus::Active->isUsable());
        $this->assertFalse(ShowStatus::Draft->isUsable());
        $this->assertSame('Archived', ShowStatus::Archived->label());
        $this->assertSame(ShowStatus::Active, ShowStatus::from('active'));
    }

    public function test_ai_persona_status_cases(): void
    {
        $this->assertSame(
            ['draft', 'active', 'inactive', 'archived'],
            array_map(fn (AiPersonaStatus $s) => $s->value, AiPersonaStatus::cases()),
        );
        $this->assertTrue(AiPersonaStatus::Active->isAssignable());
        $this->assertFalse(AiPersonaStatus::Archived->isAssignable());
    }

    public function test_episode_status_cases_and_helpers(): void
    {
        $this->assertSame(
            ['draft', 'preparing', 'ready', 'live', 'completed', 'archived'],
            array_map(fn (EpisodeStatus $s) => $s->value, EpisodeStatus::cases()),
        );
        $this->assertTrue(EpisodeStatus::Live->isLive());
        $this->assertFalse(EpisodeStatus::Ready->isLive());
        $this->assertTrue(EpisodeStatus::Completed->isConcluded());
        $this->assertTrue(EpisodeStatus::Archived->isConcluded());
        $this->assertFalse(EpisodeStatus::Live->isConcluded());
        $this->assertNull(EpisodeStatus::tryFrom('bogus'));
    }
}
