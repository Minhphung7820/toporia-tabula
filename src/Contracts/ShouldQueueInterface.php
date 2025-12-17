<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface ShouldQueueInterface
 *
 * Implement this interface to queue the import/export for background processing.
 * Essential for large files that would otherwise cause timeout issues.
 */
interface ShouldQueueInterface
{
    /**
     * Get the queue name to use.
     *
     * @return string Queue name (default: 'default')
     */
    public function queue(): string;
}
