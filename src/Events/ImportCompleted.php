<?php

declare(strict_types=1);

namespace Toporia\Tabula\Events;

use Toporia\Tabula\Support\ImportResult;

/**
 * Class ImportCompleted
 *
 * Event fired when an import completes.
 */
final class ImportCompleted
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $importClass,
        public readonly ImportResult $result,
    ) {
    }
}
