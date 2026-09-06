<?php

declare(strict_types=1);

use App\Enums\EpisodeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // A Show that is still referenced by episodes cannot be deleted:
            // restrict protects historical broadcast data. Retire a Show via
            // ShowStatus::Archived instead.
            $table->foreignId('show_id')
                ->constrained('shows')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('title');
            $table->unsignedInteger('episode_number')->nullable();
            // DATETIME (not TIMESTAMP): portable across MySQL/Postgres and free
            // of the 2038 range limit for a forward-looking scheduling field.
            // Stored in UTC; the Eloquent `datetime` cast yields a Carbon.
            $table->dateTime('broadcast_at')->nullable()->index();

            $table->text('main_topic')->nullable();
            $table->text('purpose')->nullable();
            $table->text('preparation_notes')->nullable();
            $table->text('broadcast_instructions')->nullable();

            $table->string('status')->default(EpisodeStatus::Draft->value)->index();
            $table->timestamps();

            $table->index(['show_id', 'episode_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
