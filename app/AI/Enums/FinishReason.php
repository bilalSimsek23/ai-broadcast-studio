<?php

declare(strict_types=1);

namespace App\AI\Enums;

/**
 * Why a generation stopped, in vendor-neutral terms. Adapters translate their
 * own finish/stop reasons into one of these; `Other` covers anything that does
 * not map cleanly.
 */
enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ContentFilter = 'content_filter';
    case Other = 'other';
}
