<?php

declare(strict_types=1);

namespace Toporia\Tabula\Readers;

use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface as SpoutReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Toporia\Tabula\Contracts\ReaderInterface;
use Toporia\Tabula\Exceptions\ImportException;

/**
 * Class SpoutReader
 *
 * High-performance spreadsheet reader using OpenSpout library.
 * Supports streaming reads for O(1) memory usage.
 *
 * Performance characteristics:
 * - Memory: O(1) - streams rows, doesn't load entire file
 * - Time: O(n) where n = number of rows
 * - Supports files with millions of rows
 */
final class SpoutReader implements ReaderInterface
{
    private ?SpoutReaderInterface $reader = null;
    private ?string $filePath = null;
    private bool $hasHeaderRow = true;

    /**
     * @var array<string|int> Header row values
     */
    private array $headers = [];

    /**
     * @var int Header row number (1-indexed)
     */
    private int $headerRowNumber = 1;

    /**
     * @var int|null Sheet index to read (null = first sheet)
     */
    private ?int $sheetIndex = null;

    /**
     * @var string|null Sheet name to read (null = first sheet)
     */
    private ?string $sheetName = null;

    /**
     * Set whether file has header row.
     *
     * @param bool $hasHeaderRow
     * @return self
     */
    public function setHasHeaderRow(bool $hasHeaderRow): self
    {
        $this->hasHeaderRow = $hasHeaderRow;
        return $this;
    }

    /**
     * Set the header row number.
     *
     * @param int $rowNumber Row number (1-indexed)
     * @return self
     */
    public function setHeaderRowNumber(int $rowNumber): self
    {
        $this->headerRowNumber = max(1, $rowNumber);
        return $this;
    }

    /**
     * Set the sheet to read by index.
     *
     * @param int $index Sheet index (0-indexed)
     * @return self
     */
    public function setSheetIndex(int $index): self
    {
        $this->sheetIndex = $index;
        $this->sheetName = null;
        return $this;
    }

    /**
     * Set the sheet to read by name.
     *
     * @param string $name Sheet name
     * @return self
     */
    public function setSheetName(string $name): self
    {
        $this->sheetName = $name;
        $this->sheetIndex = null;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function open(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw ImportException::fileNotFound($filePath);
        }

        $this->filePath = $filePath;
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $this->reader = match ($extension) {
            'xlsx' => new XlsxReader(),
            'csv' => new CsvReader(),
            'ods' => new OdsReader(),
            default => throw ImportException::unsupportedFileType($extension),
        };

        $this->reader->open($filePath);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function rows(): \Generator
    {
        if ($this->reader === null) {
            throw new ImportException('Reader not opened. Call open() first.');
        }

        $rowNumber = 0;
        $this->headers = [];
        $targetSheet = $this->getTargetSheet();

        foreach ($this->reader->getSheetIterator() as $sheetIndex => $sheet) {
            // Skip if not target sheet
            if ($targetSheet !== null) {
                if ($this->sheetIndex !== null && ($sheetIndex - 1) !== $this->sheetIndex) {
                    continue;
                }
                if ($this->sheetName !== null && $sheet->getName() !== $this->sheetName) {
                    continue;
                }
            }

            foreach ($sheet->getRowIterator() as $row) {
                $rowNumber++;
                $cells = $row->getCells();
                $rowData = [];

                foreach ($cells as $cell) {
                    $rowData[] = $cell->getValue();
                }

                // Skip rows before header
                if ($this->hasHeaderRow && $rowNumber < $this->headerRowNumber) {
                    continue;
                }

                // Capture header row
                if ($this->hasHeaderRow && $rowNumber === $this->headerRowNumber) {
                    $this->headers = array_map(function ($value) {
                        return $value !== null ? trim((string) $value) : '';
                    }, $rowData);
                    continue;
                }

                // Skip empty rows
                if ($this->isEmptyRow($rowData)) {
                    continue;
                }

                // Map row data to headers if available
                if (!empty($this->headers)) {
                    $mappedRow = [];
                    foreach ($this->headers as $colIndex => $header) {
                        $key = $header !== '' ? $header : $colIndex;
                        $mappedRow[$key] = $rowData[$colIndex] ?? null;
                    }
                    $rowData = $mappedRow;
                }

                yield $rowNumber => $rowData;
            }

            // Only read first matching sheet unless specified
            if ($targetSheet === null || $this->sheetIndex !== null || $this->sheetName !== null) {
                break;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        // OpenSpout doesn't support getting total count efficiently
        // Return 0 to indicate unknown count
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->reader !== null) {
            $this->reader->close();
            $this->reader = null;
        }
        $this->headers = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['xlsx', 'csv', 'ods'];
    }

    /**
     * Get the headers from the file.
     *
     * @return array<string|int>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Check if a row is empty.
     *
     * @param array<mixed> $row
     * @return bool
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Get target sheet identifier.
     *
     * @return int|string|null
     */
    private function getTargetSheet(): int|string|null
    {
        if ($this->sheetIndex !== null) {
            return $this->sheetIndex;
        }
        if ($this->sheetName !== null) {
            return $this->sheetName;
        }
        return null;
    }

    /**
     * Destructor to ensure resources are released.
     */
    public function __destruct()
    {
        $this->close();
    }
}
