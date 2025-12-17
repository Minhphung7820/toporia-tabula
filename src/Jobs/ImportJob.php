<?php

declare(strict_types=1);

namespace Toporia\Tabula\Jobs;

use Toporia\Framework\Queue\Job;
use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Contracts\WithChunkReadingInterface;
use Toporia\Tabula\Imports\Importer;
use Toporia\Tabula\Support\ImportResult;

/**
 * Class ImportJob
 *
 * Queue job for background import processing.
 * Essential for large files that would timeout in HTTP requests.
 *
 * Usage:
 * ```php
 * ImportJob::dispatch(UsersImport::class, '/path/to/file.xlsx');
 * ```
 */
final class ImportJob extends Job
{
    protected int $maxAttempts = 3;
    protected int $timeout = 3600; // 1 hour

    /**
     * @param string $importClass Fully qualified import class name
     * @param string $filePath Path to the import file
     * @param array<string, mixed> $options Import options
     */
    public function __construct(
        private string $importClass,
        private string $filePath,
        private array $options = [],
    ) {
        parent::__construct();
    }

    /**
     * Handle the job.
     *
     * @param Importer $importer
     * @return ImportResult
     */
    public function handle(Importer $importer): ImportResult
    {
        // Create import instance
        $import = $this->createImportInstance();

        // Configure importer
        if (isset($this->options['skip_invalid_rows'])) {
            $importer->skipInvalidRows($this->options['skip_invalid_rows']);
        }

        if (isset($this->options['max_errors'])) {
            $importer->maxErrors($this->options['max_errors']);
        }

        // Enable transaction if callback provided
        if (isset($this->options['transaction_callback']) && is_callable($this->options['transaction_callback'])) {
            $importer->withTransaction($this->options['transaction_callback']);
        }

        // Track progress
        if ($this->shouldTrackProgress()) {
            $this->reportProgress(0, 'Starting import...');
        }

        // Run import
        if ($import instanceof WithChunkReadingInterface) {
            $result = $importer->importChunked($import, $this->filePath);
        } else {
            $result = $importer->import($import, $this->filePath);
        }

        // Report completion
        if ($this->shouldTrackProgress()) {
            $this->reportProgress(100, sprintf(
                'Import completed: %d rows, %d success, %d failed',
                $result->getTotalRows(),
                $result->getSuccessRows(),
                $result->getFailedRows()
            ));
        }

        // Fire completion callback if provided
        if (isset($this->options['on_complete']) && is_callable($this->options['on_complete'])) {
            ($this->options['on_complete'])($result);
        }

        return $result;
    }

    /**
     * Handle job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        parent::failed($exception);

        // Fire failure callback if provided
        if (isset($this->options['on_failure']) && is_callable($this->options['on_failure'])) {
            ($this->options['on_failure'])($exception);
        }
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
        return array_merge(parent::getTags(), [
            'tabula',
            'import',
            basename($this->filePath),
        ]);
    }
}
