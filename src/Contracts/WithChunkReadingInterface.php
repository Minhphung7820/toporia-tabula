<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithChunkReadingInterface
 *
 * Implement this interface to process imports in chunks.
 * Essential for handling large files with millions of rows.
 */
interface WithChunkReadingInterface
{
    /**
     * Get the chunk size for processing.
     *
     * Recommended values:
     * - Small files (< 10K rows): 1000
     * - Medium files (< 100K rows): 5000
     * - Large files (> 100K rows): 10000
     *
     * @return int Number of rows per chunk
     */
    public function chunkSize(): int;
}
