<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithParallelInterface
 *
 * Implement this interface to enable parallel/multi-process import.
 * Uses Framework's Concurrency system (pcntl_fork) for true parallelism.
 *
 * How it works:
 * 1. File is split into N chunks by byte offset
 * 2. Each chunk is processed by a separate PHP process
 * 3. Each process has its own database connection
 * 4. Results are aggregated in the main process
 *
 * Requirements:
 * - CLI environment only (not HTTP)
 * - ext-pcntl extension
 * - Unix/Linux/macOS (Windows not supported)
 *
 * @example
 * $import = ToModelImport::make(Post::class)
 *     ->map(fn($row) => [...])
 *     ->parallel(4);  // 4 workers
 *
 * Performance gains (1M rows):
 * - 1 worker: ~2.5 min
 * - 4 workers: ~40s (6x faster)
 */
interface WithParallelInterface
{
    /**
     * Get number of parallel workers.
     *
     * @return int Number of workers (1 = sequential, 2+ = parallel)
     */
    public function workers(): int;

    /**
     * Check if parallel import is enabled.
     *
     * @return bool
     */
    public function isParallel(): bool;

    /**
     * Get concurrency driver name.
     *
     * @return string Driver name (process, fork, sync)
     */
    public function getDriver(): string;
}
