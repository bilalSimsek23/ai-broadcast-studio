<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\AI\Dtos\Message;
use App\AI\Dtos\MessageList;
use App\AI\Enums\Role;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_the_role_helpers_build_the_expected_roles(): void
    {
        $this->assertSame(Role::System, Message::system('x')->role);
        $this->assertSame(Role::User, Message::user('x')->role);
        $this->assertSame(Role::Assistant, Message::assistant('x')->role);
    }

    public function test_blank_content_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Message(Role::User, "  \n\t ");
    }

    public function test_it_serialises_to_a_neutral_array(): void
    {
        $this->assertSame(
            ['role' => 'user', 'content' => 'Merhaba'],
            Message::user('Merhaba')->toArray(),
        );
    }

    public function test_a_message_list_needs_at_least_one_message(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MessageList;
    }

    public function test_a_message_list_preserves_order(): void
    {
        $list = new MessageList(
            Message::system('sys'),
            Message::user('first'),
            Message::assistant('second'),
            Message::user('third'),
        );

        $this->assertSame(4, $list->count());
        $this->assertSame('sys', $list->first()->content);
        $this->assertSame('third', $list->last()->content);
        $this->assertSame(
            [
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user', 'content' => 'first'],
                ['role' => 'assistant', 'content' => 'second'],
                ['role' => 'user', 'content' => 'third'],
            ],
            $list->toArray(),
        );
    }

    public function test_a_message_list_can_be_built_from_any_iterable(): void
    {
        $gen = (function () {
            yield Message::user('a');
            yield Message::assistant('b');
        })();

        $list = MessageList::fromIterable($gen);

        $this->assertSame(['a', 'b'], array_map(static fn (Message $m): string => $m->content, $list->all()));
    }
}
