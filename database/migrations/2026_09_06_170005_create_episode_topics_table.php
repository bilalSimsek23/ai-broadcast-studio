<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episode_topics', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Topics are part of the Episode aggregate: cascade on delete.
            $table->foreignId('episode_id')
                ->constrained('episodes')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->string('title');
            $table->text('description')->nullable();
            $table->text('ai_context')->nullable();
            $table->text('presenter_notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['episode_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_topics');
    }
};
