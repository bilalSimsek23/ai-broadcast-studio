<?php

declare(strict_types=1);

namespace App\Application\Episodes\Readiness;

/**
 * The outcome of assessing whether an Episode is editorially ready to go
 * "Ready". Framework-agnostic - no Filament, no HTTP.
 */
final readonly class EpisodeReadiness
{
    /**
     * @param  list<ReadinessCheck>  $checks
     */
    public function __construct(public array $checks) {}

    /** True when every BLOCKING check passed. */
    public function isReady(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->blocking && ! $check->passed) {
                return false;
            }
        }

        return true;
    }

    /** @return list<ReadinessCheck> */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * Labels of the blocking checks that failed.
     *
     * @return list<string>
     */
    public function blockingIssues(): array
    {
        return array_values(array_map(
            static fn (ReadinessCheck $c): string => $c->label,
            array_filter(
                $this->checks,
                static fn (ReadinessCheck $c): bool => $c->blocking && ! $c->passed,
            ),
        ));
    }

    /**
     * @return array{is_ready: bool, checks: list<array{key: string, label: string, passed: bool, blocking: bool, hint: string|null}>, blocking_issues: list<string>}
     */
    public function toArray(): array
    {
        return [
            'is_ready' => $this->isReady(),
            'checks' => array_map(static fn (ReadinessCheck $c): array => $c->toArray(), $this->checks),
            'blocking_issues' => $this->blockingIssues(),
        ];
    }
}
