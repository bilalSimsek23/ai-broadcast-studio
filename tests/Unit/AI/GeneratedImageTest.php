<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\AI\Dtos\GeneratedImage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GeneratedImageTest extends TestCase
{
    public function test_it_exposes_a_data_uri_and_a_neutral_array_with_no_credential(): void
    {
        $image = new GeneratedImage('image/png', 'aGVsbG8=', '1024x1024');

        $this->assertSame('data:image/png;base64,aGVsbG8=', $image->toDataUri());
        $this->assertSame(
            ['image' => 'data:image/png;base64,aGVsbG8=', 'mime_type' => 'image/png', 'size' => '1024x1024'],
            $image->toArray(),
        );
        $this->assertArrayNotHasKey('api_key', $image->toArray());
    }

    public function test_it_rejects_blank_base64(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeneratedImage('image/png', '   ', '1024x1024');
    }

    public function test_it_rejects_data_that_is_not_valid_base64(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeneratedImage('image/png', 'not valid base64 !!!', '1024x1024');
    }

    public function test_it_rejects_a_non_image_mime_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeneratedImage('application/json', 'aGVsbG8=', '1024x1024');
    }

    public function test_it_rejects_a_blank_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeneratedImage('image/png', 'aGVsbG8=', '   ');
    }
}
