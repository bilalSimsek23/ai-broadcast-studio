<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editorial preparation fields for an Episode. Deliberately SEPARATE
     * COLUMNS, not one JSON blob:
     *  - queryable (e.g. "episodes with no ai_objective"),
     *  - simple per-field validation,
     *  - clean to read for future runtime prompt assembly / API use,
     *  - no JSON-cast edge cases.
     *
     * Ownership: these belong to the EPISODE.
     *  - presenter_* : the human host's pre-broadcast brief
     *  - ai_*        : the episode-specific AI briefing (NOT the AiPersona
     *                  system prompt, NOT EpisodeTopic.ai_context, NOT
     *                  EpisodeAiPersona.episode_instructions)
     */
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            // Presenter (human host) brief.
            $table->text('opening_notes')->nullable()->after('broadcast_instructions');
            $table->text('key_points')->nullable()->after('opening_notes');
            $table->text('questions_to_push')->nullable()->after('key_points');
            $table->text('closing_notes')->nullable()->after('questions_to_push');

            // Episode-specific AI brief (consumed later by prompt assembly).
            $table->text('ai_objective')->nullable()->after('closing_notes');
            $table->string('ai_tone_override')->nullable()->after('ai_objective');
            $table->text('must_cover_points')->nullable()->after('ai_tone_override');
            $table->text('avoid_points')->nullable()->after('must_cover_points');
            $table->string('response_length_guidance')->nullable()->after('avoid_points');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropColumn([
                'opening_notes',
                'key_points',
                'questions_to_push',
                'closing_notes',
                'ai_objective',
                'ai_tone_override',
                'must_cover_points',
                'avoid_points',
                'response_length_guidance',
            ]);
        });
    }
};
