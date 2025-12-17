<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithProgressInterface
 *
 * Implement this interface to track import/export progress.
 */
interface WithProgressInterface
{
    /**
     * Called when progress updates.
     *
     * @param int $current Current row number
     * @param int $total Total rows (0 if unknown)
     * @param float $percentage Progress percentage (0-100)
     * @return void
     */
    public function onProgress(int $current, int $total, float $percentage): void;
}
