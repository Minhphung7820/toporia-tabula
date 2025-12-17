<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WriterInterface
 *
 * Contract for spreadsheet writers with streaming support.
 * Implementations must support O(1) memory usage for large exports.
 */
interface WriterInterface
{
    /**
     * Open a file for writing.
     *
     * @param string $filePath Path to the file
     * @return self
     */
    public function open(string $filePath): self;

    /**
     * Write a single row.
     *
     * @param array<mixed> $row Row data
     * @return self
     */
    public function writeRow(array $row): self;

    /**
     * Write multiple rows.
     *
     * @param iterable<array<mixed>> $rows Rows data
     * @return self
     */
    public function writeRows(iterable $rows): self;

    /**
     * Close the writer and finalize the file.
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
