<?php

declare(strict_types=1);

namespace App\Application\Episodes\Readiness;

use App\Models\Episode;

/**
 * Computes an Episode's editorial readiness. Pure application logic: it reads
 * the model graph and returns a value object. No Filament, no side effects.
 * This is the single source of truth for "is this episode ready to broadcast?".
 *
 * Relationship checks are made with fresh `exists()` queries (NOT loaded
 * collections) so that an assessment taken at transition time reflects the
 * current persisted state - stale relations loaded earlier cannot bypass the
 * model-level Ready gate. Scalar fields are read from the given instance's
 * attributes (which, during the model's `updating` hook, are the values about
 * to be persisted).
 */
final class AssessEpisodeReadiness
{
    public function __invoke(Episode $episode): EpisodeReadiness
    {
        $blank = static fn (?string $value): bool => trim((string) $value) === '';

        return new EpisodeReadiness([
            new ReadinessCheck('show', 'Program atanmış', $episode->show()->exists()),
            new ReadinessCheck('title', 'Bölüm başlığı girilmiş', ! $blank($episode->title)),
            new ReadinessCheck('main_topic', 'Ana konu girilmiş', ! $blank($episode->main_topic)),
            new ReadinessCheck('broadcast_at', 'Yayın zamanı girilmiş', $episode->broadcast_at !== null),
            new ReadinessCheck('personas', 'En az bir AI karakteri atanmış', $episode->lineup()->exists()),
            new ReadinessCheck('topics', 'En az bir tartışma başlığı var', $episode->topics()->exists()),
            new ReadinessCheck('questions', 'En az bir soru var', $episode->topics()->whereHas('questions')->exists()),
            new ReadinessCheck(
                'ai_brief',
                'AI brifingi yeterli (hedef + mutlaka kapsanacaklar)',
                ! $blank($episode->ai_objective) && ! $blank($episode->must_cover_points),
                hint: 'AI Brifingi bölümündeki "AI hedefi" ve "Mutlaka kapsanacak noktalar" alanlarını doldurun.',
            ),
        ]);
    }
}
