<?php

declare(strict_types=1);

namespace App\AI\Dtos;

use InvalidArgumentException;

/**
 * The vendor-neutral result of one image generation: the raw image bytes
 * (base64-encoded), the MIME type, and the pixel size that was requested.
 *
 * It never carries a credential. The bytes are handed to the browser as a
 * data: URI ({@see toDataUri()}) — the studio control page previews it and, on
 * the operator's command, pushes it to the broadcast screen over a same-origin
 * BroadcastChannel. Nothing is persisted server-side.
 */
final readonly class GeneratedImage
{
    public function __construct(
        public string $mimeType,
        public string $base64,
        public string $size,
    ) {
        if (preg_match('#^image/[a-z0-9.+-]+$#i', $mimeType) !== 1) {
            throw new InvalidArgumentException('A generated image needs a valid image/* MIME type.');
        }

        if (trim($base64) === '' || base64_decode($base64, true) === false) {
            throw new InvalidArgumentException('A generated image needs non-empty, valid base64 data.');
        }

        if (trim($size) === '') {
            throw new InvalidArgumentException('A generated image needs a non-empty size.');
        }
    }

    public function toDataUri(): string
    {
        return sprintf('data:%s;base64,%s', $this->mimeType, $this->base64);
    }

    /**
     * The shape handed to the browser. No credential appears here.
     *
     * @return array{image: string, mime_type: string, size: string}
     */
    public function toArray(): array
    {
        return [
            'image' => $this->toDataUri(),
            'mime_type' => $this->mimeType,
            'size' => $this->size,
        ];
    }
}
