<?php

declare(strict_types=1);

use App\Enums\AiPersonaStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_personas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Persistent identity of the character.
            $table->string('name');
            $table->string('title')->nullable();
            $table->text('biography')->nullable();
            $table->text('expertise')->nullable();
            $table->text('personality')->nullable();
            $table->string('speaking_style')->nullable();
            $table->text('system_prompt')->nullable();

            // Provider bindings. These hold LOGICAL keys resolved through
            // config/ai.php (added in a later task) - e.g. "default", "fast",
            // "host_rebuttal" - never a vendor SDK name, base URL, or a raw
            // model id. The vendor-neutrality boundary (CLAUDE.md section 5)
            // still applies: domain code and this data reference logical names
            // only. Kept nullable/free-form until config/ai.php defines the
            // allowed set; see .agents/architecture.md section 2a.
            $table->string('ai_provider')->nullable()
                ->comment('logical text-generation profile key (config/ai.php); not a vendor name');
            $table->string('ai_model')->nullable()
                ->comment('optional logical model tier key (config/ai.php); not a raw model id');
            $table->string('voice_provider')->nullable()
                ->comment('logical voice profile key (config/ai.php); not a vendor name');
            $table->string('voice_id')->nullable()
                ->comment('logical voice key within the voice profile; not a vendor voice id');

            // On-screen presentation configuration.
            $table->json('screen_settings')->nullable();

            $table->string('status')->default(AiPersonaStatus::Draft->value)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_personas');
    }
};
