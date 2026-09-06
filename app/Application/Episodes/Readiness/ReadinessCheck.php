<?php

declare(strict_types=1);

namespace App\Application\Episodes\Readiness;

/**
 * One editorial-readiness check result. Framework-agnostic value object.
 */
final readonly class ReadinessCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $passed,
        public bool $blocking = true,
        public ?string $hint = null,
    ) {}

    /**
     * @return array{key: string, label: string, passed: bool, blocking: bool, hint: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'passed' => $this->passed,
            'blocking' => $this->blocking,
            'hint' => $this->hint,
        ];
    }
}
