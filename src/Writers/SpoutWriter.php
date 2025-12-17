<?php

declare(strict_types=1);

namespace Toporia\Tabula\Writers;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\WriterInterface as SpoutWriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Toporia\Tabula\Contracts\WriterInterface;
use Toporia\Tabula\Exceptions\ExportException;

/**
 * Class SpoutWriter
 *
 * High-performance spreadsheet writer using OpenSpout library.
 * Supports streaming writes for O(1) memory usage.
 *
 * Performance characteristics:
 * - Memory: O(1) - streams rows to file
 * - Time: O(n) where n = number of rows
 * - Supports exporting millions of rows
 */
final class SpoutWriter implements WriterInterface
{
    private ?SpoutWriterInterface $writer = null;
    private ?string $filePath = null;
    private int $rowCount = 0;

    /**
     * @var string|null Current sheet name
     */
    private ?string $currentSheet = null;

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

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $this->writer = match ($extension) {
            'xlsx' => new XlsxWriter(),
            'csv' => new CsvWriter(),
            'ods' => new OdsWriter(),
            default => throw ExportException::unsupportedFileType($extension),
        };

        $this->writer->openToFile($filePath);
        $this->rowCount = 0;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function writeRow(array $row): self
    {
        if ($this->writer === null) {
            throw new ExportException('Writer not opened. Call open() first.');
        }

        $cells = [];
        foreach ($row as $value) {
            $cells[] = $this->createCell($value);
        }

        $this->writer->addRow(new Row($cells));
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
     * Add a new sheet.
     *
     * @param string $name Sheet name (max 31 characters)
     * @return self
     */
    public function addSheet(string $name): self
    {
        if ($this->writer === null) {
            throw new ExportException('Writer not opened. Call open() first.');
        }

        // Truncate name to 31 characters (Excel limit)
        $name = substr($name, 0, 31);

        if ($this->writer instanceof XlsxWriter || $this->writer instanceof OdsWriter) {
            $sheet = $this->writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
        }

        $this->currentSheet = $name;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->writer !== null) {
            $this->writer->close();
            $this->writer = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['xlsx', 'csv', 'ods'];
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
     * Create a cell from a value.
     *
     * @param mixed $value
     * @return Cell
     */
    private function createCell(mixed $value): Cell
    {
        if ($value === null) {
            return Cell\StringCell::fromValue('');
        }

        if (is_bool($value)) {
            return Cell\BooleanCell::fromValue($value);
        }

        if (is_int($value) || is_float($value)) {
            return Cell\NumericCell::fromValue($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return Cell\DateTimeCell::fromValue($value);
        }

        return Cell\StringCell::fromValue((string) $value);
    }

    /**
     * Destructor to ensure resources are released.
     */
    public function __destruct()
    {
        $this->close();
    }
}
