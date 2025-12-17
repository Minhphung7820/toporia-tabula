<?php

declare(strict_types=1);

namespace Toporia\Tabula\Jobs;

use Toporia\Framework\Queue\Job;
use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Imports\Importer;
use Toporia\Tabula\Readers\SpoutReader;
use Toporia\Tabula\Support\ImportResult;

/**
 * Class ChunkedImportJob
 *
 * Queue job for processing a specific chunk of an import.
 * Used for parallel processing of large files.
 *
 * Usage:
 * ```php
 * // Spawn multiple jobs for parallel processing
 * for ($i = 0; $i < $totalChunks; $i++) {
 *     ChunkedImportJob::dispatch(
 *         UsersImport::class,
 *         '/path/to/file.xlsx',
 *         $i,
 *         1000, // chunk size
 *         $batchId
 *     );
 * }
 * ```
 */
final class ChunkedImportJob extends Job
{
    protected int $maxAttempts = 3;
    protected int $timeout = 1800; // 30 minutes per chunk

    /**
     * @param string $importClass Fully qualified import class name
     * @param string $filePath Path to the import file
     * @param int $chunkIndex Which chunk to process (0-indexed)
     * @param int $chunkSize Number of rows per chunk
     * @param string|null $batchId Optional batch ID for tracking
     */
    public function __construct(
        private string $importClass,
        private string $filePath,
        private int $chunkIndex,
        private int $chunkSize,
        private ?string $batchId = null,
    ) {
        parent::__construct();
    }

    /**
     * Handle the job.
     *
     * @return ImportResult
     */
    public function handle(): ImportResult
    {
        $import = $this->createImportInstance();
        $result = new ImportResult();

        $startRow = $this->chunkIndex * $this->chunkSize + 1;
        $endRow = $startRow + $this->chunkSize - 1;

        // Track progress
        if ($this->shouldTrackProgress()) {
            $this->reportProgress(0, "Processing chunk {$this->chunkIndex}: rows {$startRow} to {$endRow}");
        }

        // Create reader and process only our chunk
        $reader = new SpoutReader();
        $reader->open($this->filePath);

        $currentRow = 0;
        $processedInChunk = 0;

        foreach ($reader->rows() as $rowNumber => $row) {
            $currentRow++;

            // Skip rows before our chunk
            if ($currentRow < $startRow) {
                continue;
            }

            // Stop after our chunk
            if ($currentRow > $endRow) {
                break;
            }

            $result->incrementTotal();

            try {
                $import->row($row, $rowNumber);
                $result->incrementSuccess();
                $processedInChunk++;

                // Update progress within chunk
                if ($this->shouldTrackProgress() && $processedInChunk % 100 === 0) {
                    $percentage = ($processedInChunk / $this->chunkSize) * 100;
                    $this->reportProgress((int) $percentage, "Processed {$processedInChunk} rows");
                }

            } catch (\Throwable $e) {
                $result->incrementFailed();
                $result->addError($rowNumber, $e->getMessage(), $row);
            }
        }

        $reader->close();

        if ($this->shouldTrackProgress()) {
            $this->reportProgress(100, "Chunk {$this->chunkIndex} completed: {$result->getSuccessRows()} success, {$result->getFailedRows()} failed");
        }

        return $result;
    }

    /**
     * Create the import instance.
     *
     * @return ImportableInterface
     */
    private function createImportInstance(): ImportableInterface
    {
        if (!class_exists($this->importClass)) {
            throw new \RuntimeException("Import class not found: {$this->importClass}");
        }

        $import = new $this->importClass();

        if (!($import instanceof ImportableInterface)) {
            throw new \RuntimeException("Import class must implement ImportableInterface: {$this->importClass}");
        }

        return $import;
    }

    /**
     * Get the job tags.
     *
     * @return array<string>
     */
    public function getTags(): array
    {
        $tags = [
            'tabula',
            'chunked-import',
            "chunk-{$this->chunkIndex}",
        ];

        if ($this->batchId !== null) {
            $tags[] = "batch-{$this->batchId}";
        }

        return array_merge(parent::getTags(), $tags);
    }
}
