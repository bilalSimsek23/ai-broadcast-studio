<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Episode <-> AiPersona line-up. Designed to also carry per-episode
     * ordering and instructions.
     */
    public function up(): void
    {
        Schema::create('episode_ai_persona', function (Blueprint $table): void {
            $table->id();

            // Deleting an Episode clears its line-up rows (Episode is the
            // aggregate root for its own composition).
            $table->foreignId('episode_id')
                ->constrained('episodes')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // An AiPersona that is still part of any episode line-up cannot be
            // deleted: restrict protects the historical cast. Retire a persona
            // via AiPersonaStatus::Archived instead.
            $table->foreignId('ai_persona_id')
                ->constrained('ai_personas')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedInteger('sort_order')->default(0);
            $table->text('episode_instructions')->nullable();
            $table->timestamps();

            // The same persona may not be assigned to the same episode twice.
            $table->unique(['episode_id', 'ai_persona_id']);
            $table->index(['episode_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_ai_persona');
    }
};
