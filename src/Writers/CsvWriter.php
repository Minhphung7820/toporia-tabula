<?php

declare(strict_types=1);

namespace Toporia\Tabula\Writers;

use Toporia\Tabula\Contracts\WriterInterface;
use Toporia\Tabula\Exceptions\ExportException;

/**
 * Class CsvWriter
 *
 * Native PHP CSV writer with streaming support.
 * Zero dependencies - uses built-in PHP functions.
 *
 * Performance characteristics:
 * - Memory: O(1) - streams rows to file
 * - Time: O(n) where n = number of rows
 * - Optimal for large CSV exports
 */
final class CsvWriter implements WriterInterface
{
    /**
     * @var resource|null File handle
     */
    private $handle = null;

    private ?string $filePath = null;
    private int $rowCount = 0;

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
     * Output encoding.
     */
    private string $outputEncoding = 'UTF-8';

    /**
     * Whether to include BOM for UTF-8.
     */
    private bool $includeBom = false;

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
     * Set output encoding.
     *
     * @param string $encoding
     * @return self
     */
    public function setOutputEncoding(string $encoding): self
    {
        $this->outputEncoding = $encoding;
        return $this;
    }

    /**
     * Include UTF-8 BOM at beginning of file.
     * Useful for Excel to correctly detect UTF-8.
     *
     * @param bool $include
     * @return self
     */
    public function includeBom(bool $include = true): self
    {
        $this->includeBom = $include;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function open(string $filePath): self
    {
        $this->filePath = $filePath;
        $directory = dirname($filePath);

        // Ensure directory exists
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw ExportException::cannotCreateDirectory($directory);
            }
        }

        $this->handle = fopen($filePath, 'w');

        if ($this->handle === false) {
            throw ExportException::cannotWriteFile($filePath);
        }

        // Write UTF-8 BOM if requested
        if ($this->includeBom && $this->outputEncoding === 'UTF-8') {
            fwrite($this->handle, "\xEF\xBB\xBF");
        }

        $this->rowCount = 0;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function writeRow(array $row): self
    {
        if ($this->handle === null) {
            throw new ExportException('Writer not opened. Call open() first.');
        }

        // Convert encoding if needed
        if ($this->outputEncoding !== 'UTF-8') {
            $row = array_map(function ($value) {
                if ($value === null) {
                    return null;
                }
                return mb_convert_encoding((string) $value, $this->outputEncoding, 'UTF-8');
            }, $row);
        }

        // Convert values to strings
        $row = array_map(function ($value) {
            if ($value === null) {
                return '';
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            return (string) $value;
        }, $row);

        fputcsv($this->handle, $row, $this->delimiter, $this->enclosure, $this->escape);
        $this->rowCount++;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function writeRows(iterable $rows): self
    {
        foreach ($rows as $row) {
            $this->writeRow($row);
        }
        return $this;
    }

    /**
     * Write header row.
     *
     * @param array<string> $headers
     * @return self
     */
    public function writeHeaders(array $headers): self
    {
        return $this->writeRow($headers);
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
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['csv', 'tsv', 'txt'];
    }

    /**
     * Get the number of rows written.
     *
     * @return int
     */
    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    /**
     * Get the file path.
     *
     * @return string|null
     */
    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    /**
     * Destructor to ensure resources are released.
     */
    public function __destruct()
    {
        $this->close();
    }
}
