<?php

declare(strict_types=1);

namespace Toporia\Tabula\Readers;

use Toporia\Tabula\Contracts\ReaderInterface;
use Toporia\Tabula\Exceptions\ImportException;

/**
 * Class CsvReader
 *
 * Native PHP CSV reader with streaming support.
 * Zero dependencies - uses built-in PHP functions.
 *
 * Performance characteristics:
 * - Memory: O(1) - streams rows one at a time
 * - Time: O(n) where n = number of rows
 * - Optimal for large CSV files
 */
final class CsvReader implements ReaderInterface
{
    /**
     * @var resource|null File handle
     */
    private $handle = null;

    private ?string $filePath = null;
    private bool $hasHeaderRow = true;

    /**
     * @var array<string|int> Header row values
     */
    private array $headers = [];

    /**
     * CSV delimiter character.
     */
    private string $delimiter = ',';

    /**
     * CSV enclosure character.
     */
    private string $enclosure = '"';

    /**
     * CSV escape character.
     */
    private string $escape = '\\';

    /**
     * Input encoding.
     */
    private string $inputEncoding = 'UTF-8';

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
     * Set CSV delimiter.
     *
     * @param string $delimiter
     * @return self
     */
    public function setDelimiter(string $delimiter): self
    {
        $this->delimiter = $delimiter;
        return $this;
    }

    /**
     * Set CSV enclosure.
     *
     * @param string $enclosure
     * @return self
     */
    public function setEnclosure(string $enclosure): self
    {
        $this->enclosure = $enclosure;
        return $this;
    }

    /**
     * Set CSV escape character.
     *
     * @param string $escape
     * @return self
     */
    public function setEscape(string $escape): self
    {
        $this->escape = $escape;
        return $this;
    }

    /**
     * Set input encoding (will be converted to UTF-8).
     *
     * @param string $encoding
     * @return self
     */
    public function setInputEncoding(string $encoding): self
    {
        $this->inputEncoding = $encoding;
        return $this;
    }

    /**
     * Auto-detect delimiter from file.
     *
     * @param string $filePath
     * @return string Detected delimiter
     */
    public static function detectDelimiter(string $filePath): string
    {
        $delimiters = [',', ';', "\t", '|'];
        $counts = [];

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ',';
        }

        // Read first 5 lines to detect delimiter
        $lines = 0;
        while (($line = fgets($handle)) !== false && $lines < 5) {
            foreach ($delimiters as $delimiter) {
                $count = substr_count($line, $delimiter);
                $counts[$delimiter] = ($counts[$delimiter] ?? 0) + $count;
            }
            $lines++;
        }

        fclose($handle);

        // Return delimiter with highest count
        arsort($counts);
        return array_key_first($counts) ?? ',';
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
        $this->handle = fopen($filePath, 'r');

        if ($this->handle === false) {
            throw new ImportException("Cannot open file: {$filePath}");
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function rows(): \Generator
    {
        if ($this->handle === null) {
            throw new ImportException('Reader not opened. Call open() first.');
        }

        // Reset file pointer
        rewind($this->handle);

        $rowNumber = 0;
        $this->headers = [];

        while (($row = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
            $rowNumber++;

            // Convert encoding if needed
            if ($this->inputEncoding !== 'UTF-8') {
                $row = array_map(function ($value) {
                    if ($value === null) {
                        return null;
                    }
                    return mb_convert_encoding($value, 'UTF-8', $this->inputEncoding);
                }, $row);
            }

            // Capture header row
            if ($this->hasHeaderRow && $rowNumber === 1) {
                $this->headers = array_map(function ($value) {
                    return $value !== null ? trim($value) : '';
                }, $row);
                continue;
            }

            // Skip empty rows
            if ($this->isEmptyRow($row)) {
                continue;
            }

            // Map row data to headers if available
            if (!empty($this->headers)) {
                $mappedRow = [];
                foreach ($this->headers as $colIndex => $header) {
                    $key = $header !== '' ? $header : $colIndex;
                    $mappedRow[$key] = $row[$colIndex] ?? null;
                }
                $row = $mappedRow;
            }

            yield $rowNumber => $row;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        if ($this->filePath === null) {
            return 0;
        }

        // Fast line count using wc -l equivalent
        $count = 0;
        $handle = fopen($this->filePath, 'r');

        if ($handle === false) {
            return 0;
        }

        while (!feof($handle)) {
            $buffer = fread($handle, 8192);
            if ($buffer === false) {
                break;
            }
            $count += substr_count($buffer, "\n");
        }

        fclose($handle);

        // Subtract header row if present
        if ($this->hasHeaderRow && $count > 0) {
            $count--;
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
        $this->headers = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['csv', 'tsv', 'txt'];
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
     * Destructor to ensure resources are released.
     */
    public function __destruct()
    {
        $this->close();
    }
}
