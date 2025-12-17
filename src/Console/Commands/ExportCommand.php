<?php

declare(strict_types=1);

namespace Toporia\Tabula\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Tabula\Contracts\ExportableInterface;
use Toporia\Tabula\Exports\Exporter;
use Toporia\Tabula\Support\ExportResult;

/**
 * Class ExportCommand
 *
 * Console command for running exports.
 *
 * Usage:
 *   php console tabula:export App\\Exports\\UsersExport /path/to/output.xlsx
 *   php console tabula:export App\\Exports\\UsersExport /path/to/output.csv --format=csv
 */
final class ExportCommand extends Command
{
    protected string $signature = 'tabula:export
        {export : The export class (fully qualified)}
        {file : Output file path}
        {--format=xlsx : Output format (xlsx, csv, ods)}
        {--queue= : Queue name for background processing}';

    protected string $description = 'Run an export to a spreadsheet file';

    public function handle(Exporter $exporter): int
    {
        $exportClass = $this->argument('export');
        $filePath = $this->argument('file');

        // Validate export class
        if (!class_exists($exportClass)) {
            $this->error("Export class not found: {$exportClass}");
            return 1;
        }

        // Create export instance
        $export = new $exportClass();

        if (!($export instanceof ExportableInterface)) {
            $this->error("Class must implement ExportableInterface: {$exportClass}");
            return 1;
        }

        // Ensure output directory exists
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->info("Starting export: {$exportClass}");
        $this->info("Output: {$filePath}");
        $this->newLine();

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Run export
        try {
            $result = $exporter->export($export, $filePath);

            $this->displayResult($result, $startTime, $startMemory);

            return $result->isSuccessful() ? 0 : 1;

        } catch (\Throwable $e) {
            $this->error("Export failed: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Display export result.
     */
    private function displayResult(ExportResult $result, float $startTime, int $startMemory): void
    {
        $duration = microtime(true) - $startTime;
        $memoryUsed = memory_get_usage(true) - $startMemory;
        $peakMemory = memory_get_peak_usage(true);

        $this->newLine();

        if ($result->isSuccessful()) {
            $this->info('✓ Export completed successfully!');
        } else {
            $this->error('✗ Export failed: ' . $result->getErrorMessage());
            return;
        }

        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Rows', number_format($result->getTotalRows())],
                ['File Size', $result->getFileSizeFormatted()],
                ['Duration', round($duration, 2) . 's'],
                ['Rows/sec', number_format($result->getRowsPerSecond(), 0)],
                ['Memory Used', $this->formatBytes($memoryUsed)],
                ['Peak Memory', $this->formatBytes($peakMemory)],
                ['Output File', $result->getFilePath()],
            ]
        );
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
