<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface ReaderInterface
 *
 * Contract for spreadsheet readers with streaming support.
 * Implementations must support O(1) memory usage for large files.
 */
interface ReaderInterface
{
    /**
     * Open a file for reading.
     *
     * @param string $filePath Path to the file
     * @return self
     */
    public function open(string $filePath): self;

    /**
     * Get row iterator for streaming reads.
     *
     * Performance: O(1) memory - yields rows one at a time.
     *
     * @return \Generator<int, array<string|int, mixed>>
     */
    public function rows(): \Generator;

    /**
     * Get total row count (if available).
     *
     * Note: May return 0 if count is expensive to calculate.
     *
     * @return int
     */
    public function count(): int;

    /**
     * Close the reader and release resources.
     *
     * @return void
     */
    public function close(): void;

    /**
     * Get supported file extensions.
     *
     * @return array<string>
     */
    public function getSupportedExtensions(): array;
}
