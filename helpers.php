<?php

declare(strict_types=1);

use Toporia\Tabula\Contracts\ExportableInterface;
use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Support\ExportResult;
use Toporia\Tabula\Support\ImportResult;
use Toporia\Tabula\Tabula;

if (!function_exists('tabula_import')) {
    /**
     * Import a spreadsheet file.
     *
     * @param ImportableInterface $import Import definition
     * @param string $filePath Path to the file
     * @param array<string, mixed> $options Import options
     * @return ImportResult
     *
     * @example
     * $result = tabula_import(new UsersImport(), '/path/to/users.xlsx');
     */
    function tabula_import(
        ImportableInterface $import,
        string $filePath,
        array $options = []
    ): ImportResult {
        return Tabula::import($import, $filePath, $options);
    }
}

if (!function_exists('tabula_export')) {
    /**
     * Export data to a spreadsheet file.
     *
     * @param ExportableInterface $export Export definition
     * @param string $filePath Destination file path
     * @return ExportResult
     *
     * @example
     * $result = tabula_export(new UsersExport(), '/path/to/users.xlsx');
     */
    function tabula_export(ExportableInterface $export, string $filePath): ExportResult
    {
        return Tabula::export($export, $filePath);
    }
}

if (!function_exists('tabula_download')) {
    /**
     * Export and stream to browser for download.
     *
     * @param ExportableInterface $export Export definition
     * @param string $filename Download filename
     * @param string $format File format (xlsx, csv, ods)
     * @return void
     *
     * @example
     * tabula_download(new UsersExport(), 'users.xlsx');
     */
    function tabula_download(
        ExportableInterface $export,
        string $filename,
        string $format = 'xlsx'
    ): void {
        Tabula::download($export, $filename, $format);
    }
}

if (!function_exists('tabula_queue_import')) {
    /**
     * Queue an import for background processing.
     *
     * @param ImportableInterface|string $import Import instance or class name
     * @param string $filePath Path to the file
     * @param array<string, mixed> $options Queue options
     * @return void
     *
     * @example
     * tabula_queue_import(UsersImport::class, '/path/to/users.xlsx');
     */
    function tabula_queue_import(
        ImportableInterface|string $import,
        string $filePath,
        array $options = []
    ): void {
        Tabula::queueImport($import, $filePath, $options);
    }
}

if (!function_exists('tabula_queue_export')) {
    /**
     * Queue an export for background processing.
     *
     * @param ExportableInterface|string $export Export instance or class name
     * @param string $filePath Destination file path
     * @param array<string, mixed> $options Queue options
     * @return void
     *
     * @example
     * tabula_queue_export(UsersExport::class, '/path/to/users.xlsx');
     */
    function tabula_queue_export(
        ExportableInterface|string $export,
        string $filePath,
        array $options = []
    ): void {
        Tabula::queueExport($export, $filePath, $options);
    }
}
