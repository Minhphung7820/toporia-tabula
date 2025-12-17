<?php

declare(strict_types=1);

namespace Toporia\Tabula\Jobs;

use Toporia\Framework\Queue\Job;
use Toporia\Tabula\Contracts\ExportableInterface;
use Toporia\Tabula\Exports\Exporter;
use Toporia\Tabula\Support\ExportResult;

/**
 * Class ExportJob
 *
 * Queue job for background export processing.
 * Essential for large exports that would timeout in HTTP requests.
 *
 * Usage:
 * ```php
 * ExportJob::dispatch(UsersExport::class, '/path/to/output.xlsx');
 * ```
 */
final class ExportJob extends Job
{
    protected int $maxAttempts = 3;
    protected int $timeout = 3600; // 1 hour

    /**
     * @param string $exportClass Fully qualified export class name
     * @param string $filePath Destination file path
     * @param array<string, mixed> $options Export options
     */
    public function __construct(
        private string $exportClass,
        private string $filePath,
        private array $options = [],
    ) {
        parent::__construct();
    }

    /**
     * Handle the job.
     *
     * @param Exporter $exporter
     * @return ExportResult
     */
    public function handle(Exporter $exporter): ExportResult
    {
        // Create export instance
        $export = $this->createExportInstance();

        // Track progress
        if ($this->shouldTrackProgress()) {
            $this->reportProgress(0, 'Starting export...');
        }

        // Run export
        $result = $exporter->export($export, $this->filePath);

        // Report completion
        if ($this->shouldTrackProgress()) {
            if ($result->isSuccessful()) {
                $this->reportProgress(100, sprintf(
                    'Export completed: %d rows, %s',
                    $result->getTotalRows(),
                    $result->getFileSizeFormatted()
                ));
            } else {
                $this->reportProgress(100, 'Export failed: ' . $result->getErrorMessage());
            }
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
     * Create the export instance.
     *
     * @return ExportableInterface
     */
    private function createExportInstance(): ExportableInterface
    {
        if (!class_exists($this->exportClass)) {
            throw new \RuntimeException("Export class not found: {$this->exportClass}");
        }

        $export = new $this->exportClass();

        if (!($export instanceof ExportableInterface)) {
            throw new \RuntimeException("Export class must implement ExportableInterface: {$this->exportClass}");
        }

        return $export;
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
            'export',
            basename($this->filePath),
        ]);
    }
}
