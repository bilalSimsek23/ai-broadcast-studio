<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use InvalidArgumentException;

/**
 * An ordered, non-empty list of {@see Message}s. Guarantees the ordering a
 * provider receives is exactly the ordering the caller built.
 */
final readonly class MessageList
{
    /** @var non-empty-list<Message> */
    public array $messages;

    public function __construct(Message ...$messages)
    {
        if ($messages === []) {
            throw new InvalidArgumentException('A text generation request needs at least one message.');
        }

        $this->messages = array_values($messages);
    }

    /**
     * @param  iterable<Message>  $messages
     */
    public static function fromIterable(iterable $messages): self
    {
        return new self(...[...$messages]);
    }

    /**
     * @return non-empty-list<Message>
     */
    public function all(): array
    {
        return $this->messages;
    }

    public function first(): Message
    {
        return $this->messages[0];
    }

    public function last(): Message
    {
        return $this->messages[array_key_last($this->messages)];
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * @return non-empty-list<array{role: string, content: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Message $message): array => $message->toArray(), $this->messages);
    }
}
