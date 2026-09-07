<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use RuntimeException;

/**
 * Base type for every "the AI text layer is misconfigured or asked for
 * something unknown" failure.
 *
 * These exceptions can surface in logs, consoles and monitoring, so their
 * messages deliberately carry only LOGICAL identifiers (provider/model/driver
 * keys) — never a base URL, credential, or any value that might be a
 * mistakenly-pasted secret (CLAUDE.md §6).
 */
abstract class AiConfigurationException extends RuntimeException {}
