<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use App\AI\Enums\Role;
use InvalidArgumentException;

/**
 * One turn in a conversation, in vendor-neutral form: a {@see Role} plus its
 * text content. Typed on purpose — callers never hand the layer a loose
 * `['role' => ..., 'content' => ...]` array.
 */
final readonly class Message
{
    public function __construct(
        public Role $role,
        public string $content,
    ) {
        if (trim($content) === '') {
            throw new InvalidArgumentException('A conversation message must have non-empty content.');
        }
    }

    public static function system(string $content): self
    {
        return new self(Role::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(Role::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(Role::Assistant, $content);
    }

    /**
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'content' => $this->content,
        ];
    }
}
