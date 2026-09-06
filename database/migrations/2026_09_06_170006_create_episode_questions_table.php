<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episode_questions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Questions belong to a Topic (which belongs to an Episode):
            // cascade on delete all the way down.
            $table->foreignId('episode_topic_id')
                ->constrained('episode_topics')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->text('question');
            $table->text('ai_context')->nullable();
            $table->text('presenter_notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['episode_topic_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_questions');
    }
};
