<?php

declare(strict_types=1);

namespace Toporia\Tabula;

use Toporia\Tabula\Contracts\ExportableInterface;
use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Contracts\ShouldQueueInterface;
use Toporia\Tabula\Contracts\WithChunkReadingInterface;
use Toporia\Tabula\Exports\Exporter;
use Toporia\Tabula\Imports\Importer;
use Toporia\Tabula\Jobs\ExportJob;
use Toporia\Tabula\Jobs\ImportJob;
use Toporia\Tabula\Support\ExportResult;
use Toporia\Tabula\Support\ImportResult;

/**
 * Class Tabula
 *
 * Main facade for Excel/CSV import & export operations.
 * Provides a simple, fluent API for handling spreadsheet files.
 *
 * Performance optimizations:
 * - Streaming I/O: O(1) memory for large files
 * - Chunk processing: configurable batch sizes
 * - Queue support: background processing for large files
 * - Generator support: lazy evaluation
 *
 * @example
 * // Simple import
 * Tabula::import(new UsersImport(), '/path/to/users.xlsx');
 *
 * // Export with download
 * Tabula::download(new UsersExport(), 'users.xlsx');
 *
 * // Queue large import
 * Tabula::queue(new LargeImport(), '/path/to/large.xlsx');
 */
final class Tabula
{
    private static ?Importer $importer = null;
    private static ?Exporter $exporter = null;

    /**
     * Import a file.
     *
     * @param ImportableInterface $import Import definition
     * @param string $filePath Path to the file
     * @param array<string, mixed> $options Import options
     * @return ImportResult
     */
    public static function import(
        ImportableInterface $import,
        string $filePath,
        array $options = []
    ): ImportResult {
        $importer = self::getImporter();

        // Configure importer
        if (isset($options['skip_invalid_rows'])) {
            $importer->skipInvalidRows($options['skip_invalid_rows']);
        }

        if (isset($options['max_errors'])) {
            $importer->maxErrors($options['max_errors']);
        }

        if (isset($options['transaction'])) {
            $importer->withTransaction($options['transaction']);
        }

        if (isset($options['validation'])) {
            $importer->withValidation($options['validation']);
        }

        // Use chunked import if interface implemented
        if ($import instanceof WithChunkReadingInterface) {
            return $importer->importChunked($import, $filePath);
        }

        return $importer->import($import, $filePath);
    }

    /**
     * Export to file.
     *
     * @param ExportableInterface $export Export definition
     * @param string $filePath Destination file path
     * @return ExportResult
     */
    public static function export(ExportableInterface $export, string $filePath): ExportResult
    {
        return self::getExporter()->export($export, $filePath);
    }

    /**
     * Export and stream to browser for download.
     *
     * @param ExportableInterface $export Export definition
     * @param string $filename Download filename
     * @param string $format File format (xlsx, csv, ods)
     * @return void
     */
    public static function download(
        ExportableInterface $export,
        string $filename,
        string $format = 'xlsx'
    ): void {
        self::getExporter()->download($export, $filename, $format);
    }

    /**
     * Export and return raw content.
     *
     * @param ExportableInterface $export Export definition
     * @param string $format File format
     * @return string
     */
    public static function raw(ExportableInterface $export, string $format = 'xlsx'): string
    {
        return self::getExporter()->raw($export, $format);
    }

    /**
     * Queue an import for background processing.
     *
     * @param ImportableInterface|string $import Import instance or class name
     * @param string $filePath Path to the file
     * @param array<string, mixed> $options Queue options
     * @return void
     */
    public static function queueImport(
        ImportableInterface|string $import,
        string $filePath,
        array $options = []
    ): void {
        $importClass = is_string($import) ? $import : get_class($import);

        // Determine queue name
        $queue = 'default';
        if ($import instanceof ShouldQueueInterface) {
            $queue = $import->queue();
        } elseif (isset($options['queue'])) {
            $queue = $options['queue'];
        }

        ImportJob::dispatch($importClass, $filePath, $options)
            ->onQueue($queue);
    }

    /**
     * Queue an export for background processing.
     *
     * @param ExportableInterface|string $export Export instance or class name
     * @param string $filePath Destination file path
     * @param array<string, mixed> $options Queue options
     * @return void
     */
    public static function queueExport(
        ExportableInterface|string $export,
        string $filePath,
        array $options = []
    ): void {
        $exportClass = is_string($export) ? $export : get_class($export);

        // Determine queue name
        $queue = 'default';
        if ($export instanceof ShouldQueueInterface) {
            $queue = $export->queue();
        } elseif (isset($options['queue'])) {
            $queue = $options['queue'];
        }

        ExportJob::dispatch($exportClass, $filePath, $options)
            ->onQueue($queue);
    }

    /**
     * Create a new importer instance.
     *
     * @return Importer
     */
    public static function importer(): Importer
    {
        return new Importer();
    }

    /**
     * Create a new exporter instance.
     *
     * @return Exporter
     */
    public static function exporter(): Exporter
    {
        return new Exporter();
    }

    /**
     * Get the shared importer instance.
     *
     * @return Importer
     */
    private static function getImporter(): Importer
    {
        if (self::$importer === null) {
            self::$importer = new Importer();
        }
        return self::$importer;
    }

    /**
     * Get the shared exporter instance.
     *
     * @return Exporter
     */
    private static function getExporter(): Exporter
    {
        if (self::$exporter === null) {
            self::$exporter = new Exporter();
        }
        return self::$exporter;
    }

    /**
     * Reset shared instances (useful for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$importer = null;
        self::$exporter = null;
    }
}
