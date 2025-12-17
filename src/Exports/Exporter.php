<?php

declare(strict_types=1);

namespace Toporia\Tabula\Exports;

use Toporia\Tabula\Contracts\ExportableInterface;
use Toporia\Tabula\Contracts\WithEventsInterface;
use Toporia\Tabula\Contracts\WithMultipleSheetsInterface;
use Toporia\Tabula\Contracts\WithProgressInterface;
use Toporia\Tabula\Contracts\WithTitleInterface;
use Toporia\Tabula\Contracts\WriterInterface;
use Toporia\Tabula\Exceptions\ExportException;
use Toporia\Tabula\Support\ExportResult;
use Toporia\Tabula\Writers\CsvWriter;
use Toporia\Tabula\Writers\SpoutWriter;

/**
 * Class Exporter
 *
 * Main class for handling exports.
 * Supports streaming writes for O(1) memory usage.
 *
 * Performance optimizations:
 * - Streaming writes: O(1) memory
 * - Generator support: process one row at a time
 * - Chunk flushing: periodic file writes
 */
final class Exporter
{
    /**
     * Export to file.
     *
     * @param ExportableInterface $export Export definition
     * @param string $filePath Destination file path
     * @return ExportResult
     */
    public function export(ExportableInterface $export, string $filePath): ExportResult
    {
        $startTime = microtime(true);

        try {
            // Handle multiple sheets
            if ($export instanceof WithMultipleSheetsInterface) {
                return $this->exportMultipleSheets($export, $filePath, $startTime);
            }

            // Single sheet export
            return $this->exportSingleSheet($export, $filePath, $startTime);

        } catch (\Throwable $e) {
            return ExportResult::failed($filePath, $e->getMessage());
        }
    }

    /**
     * Export and stream to browser for download.
     *
     * @param ExportableInterface $export
     * @param string $filename Download filename
     * @param string $format File format (xlsx, csv, ods)
     * @return void
     */
    public function download(ExportableInterface $export, string $filename, string $format = 'xlsx'): void
    {
        // Sanitize filename
        $filename = $this->sanitizeFilename($filename);

        // Add extension if not present
        if (!str_ends_with(strtolower($filename), ".{$format}")) {
            $filename .= ".{$format}";
        }

        // Create temp file
        $tempFile = sys_get_temp_dir() . '/' . uniqid('tabula_export_', true) . ".{$format}";

        // Export to temp file
        $result = $this->export($export, $tempFile);

        if (!$result->isSuccessful()) {
            throw new ExportException($result->getErrorMessage() ?? 'Export failed');
        }

        // Set headers
        $contentType = $this->getContentType($format);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tempFile));
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        // Stream file
        readfile($tempFile);

        // Cleanup
        unlink($tempFile);
        exit;
    }

    /**
     * Export and return raw content.
     *
     * @param ExportableInterface $export
     * @param string $format File format
     * @return string File content
     */
    public function raw(ExportableInterface $export, string $format = 'xlsx'): string
    {
        $tempFile = sys_get_temp_dir() . '/' . uniqid('tabula_export_', true) . ".{$format}";

        $result = $this->export($export, $tempFile);

        if (!$result->isSuccessful()) {
            throw new ExportException($result->getErrorMessage() ?? 'Export failed');
        }

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content !== false ? $content : '';
    }

    /**
     * Export single sheet.
     *
     * @param ExportableInterface $export
     * @param string $filePath
     * @param float $startTime
     * @return ExportResult
     */
    private function exportSingleSheet(
        ExportableInterface $export,
        string $filePath,
        float $startTime
    ): ExportResult {
        $writer = $this->createWriter($filePath);
        $writer->open($filePath);

        $this->fireEvent($export, 'beforeExport');

        // Write headers
        $headers = $export->headers();
        if (!empty($headers)) {
            $writer->writeRow($headers);
        }

        // Write data
        $rowCount = 0;
        $data = $export->data();

        // Estimate total for progress (if countable)
        $total = $data instanceof \Countable ? count($data) : 0;

        foreach ($data as $item) {
            // Map item to row
            $row = $export instanceof BaseExport ? $export->map($item) : $this->mapItem($item);
            $writer->writeRow($row);
            $rowCount++;

            // Progress callback
            if ($export instanceof WithProgressInterface) {
                $percentage = $total > 0 ? ($rowCount / $total) * 100 : 0;
                $export->onProgress($rowCount, $total, $percentage);
            }
        }

        $writer->close();

        $this->fireEvent($export, 'afterExport');

        return ExportResult::success($filePath, $rowCount, microtime(true) - $startTime);
    }

    /**
     * Export multiple sheets.
     *
     * @param ExportableInterface&WithMultipleSheetsInterface $export
     * @param string $filePath
     * @param float $startTime
     * @return ExportResult
     */
    private function exportMultipleSheets(
        ExportableInterface&WithMultipleSheetsInterface $export,
        string $filePath,
        float $startTime
    ): ExportResult {
        $writer = $this->createWriter($filePath);

        if (!($writer instanceof SpoutWriter)) {
            throw new ExportException('Multiple sheets only supported for Excel/ODS formats');
        }

        $writer->open($filePath);

        $this->fireEvent($export, 'beforeExport');

        $totalRows = 0;
        $sheetIndex = 0;

        foreach ($export->sheets() as $sheet) {
            // Add new sheet (skip for first sheet)
            if ($sheetIndex > 0) {
                $sheetTitle = $sheet instanceof WithTitleInterface
                    ? $sheet->title()
                    : 'Sheet' . ($sheetIndex + 1);
                $writer->addSheet($sheetTitle);
            }

            // Write headers
            $headers = $sheet->headers();
            if (!empty($headers)) {
                $writer->writeRow($headers);
            }

            // Write data
            foreach ($sheet->data() as $item) {
                $row = $sheet instanceof BaseExport ? $sheet->map($item) : $this->mapItem($item);
                $writer->writeRow($row);
                $totalRows++;
            }

            $sheetIndex++;
        }

        $writer->close();

        $this->fireEvent($export, 'afterExport');

        return ExportResult::success($filePath, $totalRows, microtime(true) - $startTime);
    }

    /**
     * Create appropriate writer for file type.
     *
     * @param string $filePath
     * @return WriterInterface
     */
    private function createWriter(string $filePath): WriterInterface
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Use native CSV writer for CSV (faster)
        if (in_array($extension, ['csv', 'tsv', 'txt'], true)) {
            $writer = new CsvWriter();

            if ($extension === 'tsv') {
                $writer->setDelimiter("\t");
            }

            return $writer;
        }

        // Use OpenSpout for Excel/ODS
        return new SpoutWriter();
    }

    /**
     * Map an item to a row array.
     *
     * @param mixed $item
     * @return array<mixed>
     */
    private function mapItem(mixed $item): array
    {
        if (is_array($item)) {
            return array_values($item);
        }

        if (is_object($item)) {
            if (method_exists($item, 'toArray')) {
                return array_values($item->toArray());
            }
            return array_values((array) $item);
        }

        return [$item];
    }

    /**
     * Fire an event on the export.
     *
     * @param ExportableInterface $export
     * @param string $event
     * @param array<mixed> $args
     * @return void
     */
    private function fireEvent(ExportableInterface $export, string $event, array $args = []): void
    {
        if (!($export instanceof WithEventsInterface)) {
            return;
        }

        $events = $export->registerEvents();

        if (isset($events[$event]) && is_callable($events[$event])) {
            ($events[$event])(...$args);
        }
    }

    /**
     * Get content type for format.
     *
     * @param string $format
     * @return string
     */
    private function getContentType(string $format): string
    {
        return match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            default => 'application/octet-stream',
        };
    }

    /**
     * Sanitize filename for download.
     *
     * @param string $filename
     * @return string
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove control characters and newlines
        $filename = preg_replace('/[\r\n\x00-\x1f\x7f]/', '', $filename) ?? '';

        // Remove directory traversal
        $filename = basename($filename);

        // Remove problematic characters
        $filename = str_replace(['"', '\\', '/', ':', '*', '?', '<', '>', '|'], '', $filename);

        // Ensure not empty
        if (empty($filename)) {
            $filename = 'export';
        }

        // Limit length
        if (strlen($filename) > 200) {
            $filename = substr($filename, 0, 200);
        }

        return $filename;
    }
}
