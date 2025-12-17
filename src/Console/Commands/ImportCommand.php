<?php

declare(strict_types=1);

namespace Toporia\Tabula\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Contracts\WithChunkReadingInterface;
use Toporia\Tabula\Imports\Importer;
use Toporia\Tabula\Support\ImportResult;

/**
 * Class ImportCommand
 *
 * Console command for running imports.
 *
 * Usage:
 *   php console tabula:import App\\Imports\\UsersImport /path/to/file.xlsx
 *   php console tabula:import App\\Imports\\UsersImport /path/to/file.xlsx --chunk=5000
 *   php console tabula:import App\\Imports\\UsersImport /path/to/file.xlsx --skip-errors
 */
final class ImportCommand extends Command
{
    protected string $signature = 'tabula:import
        {import : The import class (fully qualified)}
        {file : Path to the file to import}
        {--chunk=1000 : Chunk size for processing}
        {--skip-errors : Skip invalid rows instead of failing}
        {--max-errors= : Maximum errors before stopping}
        {--queue= : Queue name for background processing}';

    protected string $description = 'Run an import from a spreadsheet file';

    public function handle(Importer $importer): int
    {
        $importClass = $this->argument('import');
        $filePath = $this->argument('file');

        // Validate import class
        if (!class_exists($importClass)) {
            $this->error("Import class not found: {$importClass}");
            return 1;
        }

        // Validate file
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        // Create import instance
        $import = new $importClass();

        if (!($import instanceof ImportableInterface)) {
            $this->error("Class must implement ImportableInterface: {$importClass}");
            return 1;
        }

        // Configure importer
        if ($this->option('skip-errors')) {
            $importer->skipInvalidRows(true);
        }

        if ($maxErrors = $this->option('max-errors')) {
            $importer->maxErrors((int) $maxErrors);
        }

        $this->info("Starting import: {$importClass}");
        $this->info("File: {$filePath}");
        $this->newLine();

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Run import
        try {
            if ($import instanceof WithChunkReadingInterface) {
                $result = $importer->importChunked($import, $filePath);
            } else {
                $result = $importer->import($import, $filePath);
            }

            $this->displayResult($result, $startTime, $startMemory);

            return $result->isSuccessful() ? 0 : 1;

        } catch (\Throwable $e) {
            $this->error("Import failed: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Display import result.
     */
    private function displayResult(ImportResult $result, float $startTime, int $startMemory): void
    {
        $duration = microtime(true) - $startTime;
        $memoryUsed = memory_get_usage(true) - $startMemory;
        $peakMemory = memory_get_peak_usage(true);

        $this->newLine();

        if ($result->isSuccessful()) {
            $this->info('✓ Import completed successfully!');
        } else {
            $this->warn('⚠ Import completed with errors');
        }

        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Rows', number_format($result->getTotalRows())],
                ['Success', number_format($result->getSuccessRows())],
                ['Failed', number_format($result->getFailedRows())],
                ['Skipped', number_format($result->getSkippedRows())],
                ['Duration', round($duration, 2) . 's'],
                ['Rows/sec', number_format($result->getRowsPerSecond(), 0)],
                ['Memory Used', $this->formatBytes($memoryUsed)],
                ['Peak Memory', $this->formatBytes($peakMemory)],
            ]
        );

        // Show errors if any
        $errors = $result->getErrors();
        if (!empty($errors)) {
            $this->newLine();
            $this->warn('Errors:');

            $displayErrors = array_slice($errors, 0, 10);
            foreach ($displayErrors as $error) {
                $this->line("  Row {$error['row']}: {$error['message']}");
            }

            if (count($errors) > 10) {
                $this->line("  ... and " . (count($errors) - 10) . " more errors");
            }
        }
    }

    /**
     * Format bytes to human readable.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}
