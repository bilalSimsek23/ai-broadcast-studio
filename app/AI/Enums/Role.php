<?php

declare(strict_types=1);

namespace App\AI\Enums;

/**
 * The neutral role of a single conversation message. Vendor adapters map this
 * onto whatever their SDK expects; product code never sees a vendor role name.
 */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
