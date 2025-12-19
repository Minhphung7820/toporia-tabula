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
 *
 * v2.0 Optimizations for 1M+ rows:
 * - Pre-computed header index using array_combine (O(1) vs O(n) per row)
 * - Configurable read buffer size for I/O optimization
 * - Raw mode option to skip header mapping entirely
 * - Optimized empty row check
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
     * Raw mode - returns indexed arrays without header mapping.
     * Use when caller handles mapping for better performance.
     */
    private bool $rawMode = false;

    /**
     * Number of header columns (cached for performance).
     */
    private int $headerCount = 0;

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
     * Enable raw mode for maximum performance.
     *
     * In raw mode, rows are returned as indexed arrays (no header mapping).
     * The caller is responsible for accessing values by index.
     *
     * @param bool $rawMode
     * @return self
     */
    public function setRawMode(bool $rawMode): self
    {
        $this->rawMode = $rawMode;
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

        // Set larger read buffer for better I/O performance
        stream_set_read_buffer($this->handle, 65536); // 64KB buffer

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
        $this->headerCount = 0;
        $needsEncoding = $this->inputEncoding !== 'UTF-8';

        while (($row = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
            $rowNumber++;

            // Convert encoding if needed (rare case)
            if ($needsEncoding) {
                $row = $this->convertEncoding($row);
            }

            // Capture header row
            if ($this->hasHeaderRow && $rowNumber === 1) {
                $this->headers = array_map('trim', $row);
                $this->headerCount = count($this->headers);
                continue;
            }

            // Quick empty row check - just check first cell
            if ($row[0] === null || $row[0] === '') {
                // Full check only if first cell is empty
                if ($this->isEmptyRow($row)) {
                    continue;
                }
            }

            // Map row data to headers using array_combine (O(1) operation)
            // Only if not in raw mode and headers exist
            if (!$this->rawMode && $this->headerCount > 0) {
                // Ensure row has same number of elements as headers
                $rowCount = count($row);
                if ($rowCount < $this->headerCount) {
                    $row = array_pad($row, $this->headerCount, null);
                } elseif ($rowCount > $this->headerCount) {
                    $row = array_slice($row, 0, $this->headerCount);
                }

                $row = array_combine($this->headers, $row);
            }

            yield $rowNumber => $row;
        }
    }

    /**
     * Read rows in batches for better performance.
     *
     * This method reads multiple rows at once and returns them as an array.
     * More efficient than iterating row by row when processing in batches.
     *
     * @param int $batchSize Number of rows per batch
     * @return \Generator<int, array<array<string|int, mixed>>>
     */
    public function rowsBatched(int $batchSize = 1000): \Generator
    {
        if ($this->handle === null) {
            throw new ImportException('Reader not opened. Call open() first.');
        }

        rewind($this->handle);

        $rowNumber = 0;
        $batch = [];
        $this->headers = [];
        $this->headerCount = 0;
        $needsEncoding = $this->inputEncoding !== 'UTF-8';

        while (($row = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
            $rowNumber++;

            if ($needsEncoding) {
                $row = $this->convertEncoding($row);
            }

            // Capture header row
            if ($this->hasHeaderRow && $rowNumber === 1) {
                $this->headers = array_map('trim', $row);
                $this->headerCount = count($this->headers);
                continue;
            }

            // Quick empty check
            if ($row[0] === null || $row[0] === '') {
                if ($this->isEmptyRow($row)) {
                    continue;
                }
            }

            // Map headers if needed
            if (!$this->rawMode && $this->headerCount > 0) {
                $rowCount = count($row);
                if ($rowCount < $this->headerCount) {
                    $row = array_pad($row, $this->headerCount, null);
                } elseif ($rowCount > $this->headerCount) {
                    $row = array_slice($row, 0, $this->headerCount);
                }
                $row = array_combine($this->headers, $row);
            }

            $batch[] = $row;

            if (count($batch) >= $batchSize) {
                yield $batch;
                $batch = [];
            }
        }

        // Yield remaining rows
        if (!empty($batch)) {
            yield $batch;
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

        // Fast line count using larger buffer
        $count = 0;
        $handle = fopen($this->filePath, 'r');

        if ($handle === false) {
            return 0;
        }

        // Use larger buffer for faster counting
        while (!feof($handle)) {
            $buffer = fread($handle, 65536); // 64KB chunks
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
        $this->headerCount = 0;
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
     * Convert row encoding to UTF-8.
     *
     * @param array<mixed> $row
     * @return array<mixed>
     */
    private function convertEncoding(array $row): array
    {
        foreach ($row as $index => $value) {
            if ($value !== null) {
                $row[$index] = mb_convert_encoding($value, 'UTF-8', $this->inputEncoding);
            }
        }
        return $row;
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
