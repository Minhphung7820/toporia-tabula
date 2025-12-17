<?php

declare(strict_types=1);

namespace Toporia\Tabula\Imports;

use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Contracts\ReaderInterface;
use Toporia\Tabula\Contracts\ShouldQueueInterface;
use Toporia\Tabula\Contracts\WithBatchInsertsInterface;
use Toporia\Tabula\Contracts\WithChunkReadingInterface;
use Toporia\Tabula\Contracts\WithEventsInterface;
use Toporia\Tabula\Contracts\WithHeadingRowInterface;
use Toporia\Tabula\Contracts\WithMappingInterface;
use Toporia\Tabula\Contracts\WithProgressInterface;
use Toporia\Tabula\Contracts\WithValidationInterface;
use Toporia\Tabula\Exceptions\ImportException;
use Toporia\Tabula\Readers\CsvReader;
use Toporia\Tabula\Readers\SpoutReader;
use Toporia\Tabula\Support\ChunkIterator;
use Toporia\Tabula\Support\ImportResult;

/**
 * Class Importer
 *
 * Main class for handling imports.
 * Supports streaming, chunking, validation, and batch processing.
 *
 * Performance optimizations:
 * - Streaming reads: O(1) memory
 * - Chunk processing: configurable batch sizes
 * - Batch database inserts: reduces queries
 * - Progress tracking: optional overhead
 */
final class Importer
{
    /**
     * @var callable|null Transaction callback
     */
    private $transactionCallback = null;

    /**
     * @var callable|null Validation callback
     */
    private $validationCallback = null;

    /**
     * @var bool Skip invalid rows instead of failing
     */
    private bool $skipInvalidRows = false;

    /**
     * @var int|null Maximum number of errors before stopping
     */
    private ?int $maxErrors = null;

    /**
     * Import a file using an import class.
     *
     * @param ImportableInterface $import Import definition
     * @param string $filePath Path to the file
     * @return ImportResult
     */
    public function import(ImportableInterface $import, string $filePath): ImportResult
    {
        $startTime = microtime(true);

        // Validate file exists
        if (!file_exists($filePath)) {
            throw ImportException::fileNotFound($filePath);
        }

        // Create reader
        $reader = $this->createReader($filePath);

        // Configure reader
        if ($import instanceof WithHeadingRowInterface) {
            if ($reader instanceof SpoutReader) {
                $reader->setHasHeaderRow(true);
                $reader->setHeaderRowNumber($import->headingRow());
            } elseif ($reader instanceof CsvReader) {
                $reader->setHasHeaderRow(true);
            }
        }

        $reader->open($filePath);

        // Fire beforeImport event
        $this->fireEvent($import, 'beforeImport');

        // Process rows
        $result = $this->processRows($import, $reader);

        $reader->close();

        // Set duration
        $result->setDuration(microtime(true) - $startTime);

        // Fire afterImport event
        $this->fireEvent($import, 'afterImport', [$result]);

        return $result;
    }

    /**
     * Import a file with chunked processing.
     *
     * Optimal for large files - processes in batches.
     *
     * @param ImportableInterface&WithChunkReadingInterface $import
     * @param string $filePath
     * @return ImportResult
     */
    public function importChunked(
        ImportableInterface&WithChunkReadingInterface $import,
        string $filePath
    ): ImportResult {
        $startTime = microtime(true);

        if (!file_exists($filePath)) {
            throw ImportException::fileNotFound($filePath);
        }

        $reader = $this->createReader($filePath);

        if ($import instanceof WithHeadingRowInterface) {
            if ($reader instanceof SpoutReader) {
                $reader->setHasHeaderRow(true);
                $reader->setHeaderRowNumber($import->headingRow());
            } elseif ($reader instanceof CsvReader) {
                $reader->setHasHeaderRow(true);
            }
        }

        $reader->open($filePath);

        $this->fireEvent($import, 'beforeImport');

        $result = $this->processChunked($import, $reader);

        $reader->close();

        $result->setDuration(microtime(true) - $startTime);

        $this->fireEvent($import, 'afterImport', [$result]);

        return $result;
    }

    /**
     * Set transaction callback.
     *
     * @param callable(callable): mixed $callback
     * @return self
     */
    public function withTransaction(callable $callback): self
    {
        $this->transactionCallback = $callback;
        return $this;
    }

    /**
     * Set validation callback.
     *
     * @param callable(array, array): array $callback
     * @return self
     */
    public function withValidation(callable $callback): self
    {
        $this->validationCallback = $callback;
        return $this;
    }

    /**
     * Skip invalid rows instead of failing.
     *
     * @param bool $skip
     * @return self
     */
    public function skipInvalidRows(bool $skip = true): self
    {
        $this->skipInvalidRows = $skip;
        return $this;
    }

    /**
     * Set maximum errors before stopping.
     *
     * @param int|null $max
     * @return self
     */
    public function maxErrors(?int $max): self
    {
        $this->maxErrors = $max;
        return $this;
    }

    /**
     * Process rows one at a time.
     *
     * @param ImportableInterface $import
     * @param ReaderInterface $reader
     * @return ImportResult
     */
    private function processRows(ImportableInterface $import, ReaderInterface $reader): ImportResult
    {
        $result = new ImportResult();
        $totalRows = $reader->count();

        foreach ($reader->rows() as $rowNumber => $row) {
            $result->incrementTotal();

            try {
                // Apply mapping
                if ($import instanceof WithMappingInterface) {
                    $row = $import->map($row);
                }

                // Validate
                if ($import instanceof WithValidationInterface) {
                    $errors = $this->validateRow($row, $import->rules(), $import->customValidationMessages());

                    if (!empty($errors)) {
                        if ($this->skipInvalidRows) {
                            $result->incrementSkipped();
                            $result->addError($rowNumber, implode(', ', $errors), $row);
                            continue;
                        }
                        throw ImportException::validationFailed($rowNumber, $errors);
                    }
                }

                // Process row
                $import->row($row, $rowNumber);
                $result->incrementSuccess();

                // Progress callback
                if ($import instanceof WithProgressInterface) {
                    $percentage = $totalRows > 0 ? ($result->getTotalRows() / $totalRows) * 100 : 0;
                    $import->onProgress($result->getTotalRows(), $totalRows, $percentage);
                }

            } catch (ImportException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $result->incrementFailed();
                $result->addError($rowNumber, $e->getMessage(), $row);

                $this->fireEvent($import, 'onError', [$e, $row, $rowNumber]);

                if ($this->maxErrors !== null && $result->getFailedRows() >= $this->maxErrors) {
                    $result->addWarning("Import stopped: Maximum errors ({$this->maxErrors}) reached");
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Process rows in chunks.
     *
     * @param ImportableInterface&WithChunkReadingInterface $import
     * @param ReaderInterface $reader
     * @return ImportResult
     */
    private function processChunked(
        ImportableInterface&WithChunkReadingInterface $import,
        ReaderInterface $reader
    ): ImportResult {
        $result = new ImportResult();
        $chunkSize = $import->chunkSize();
        $batchSize = $import instanceof WithBatchInsertsInterface ? $import->batchSize() : $chunkSize;

        $chunk = [];
        $chunkIndex = 0;
        $rowNumbers = [];
        $totalRows = $reader->count();

        foreach ($reader->rows() as $rowNumber => $row) {
            $result->incrementTotal();

            try {
                // Apply mapping
                if ($import instanceof WithMappingInterface) {
                    $row = $import->map($row);
                }

                // Validate
                if ($import instanceof WithValidationInterface) {
                    $errors = $this->validateRow($row, $import->rules(), $import->customValidationMessages());

                    if (!empty($errors)) {
                        if ($this->skipInvalidRows) {
                            $result->incrementSkipped();
                            $result->addError($rowNumber, implode(', ', $errors), $row);
                            continue;
                        }
                        throw ImportException::validationFailed($rowNumber, $errors);
                    }
                }

                $chunk[] = $row;
                $rowNumbers[] = $rowNumber;

                // Process chunk when full
                if (count($chunk) >= $chunkSize) {
                    $this->processChunk($import, $chunk, $rowNumbers, $chunkIndex, $result);
                    $chunk = [];
                    $rowNumbers = [];
                    $chunkIndex++;

                    // Progress callback
                    if ($import instanceof WithProgressInterface) {
                        $percentage = $totalRows > 0 ? ($result->getTotalRows() / $totalRows) * 100 : 0;
                        $import->onProgress($result->getTotalRows(), $totalRows, $percentage);
                    }
                }

            } catch (ImportException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $result->incrementFailed();
                $result->addError($rowNumber, $e->getMessage(), $row);

                $this->fireEvent($import, 'onError', [$e, $row, $rowNumber]);

                if ($this->maxErrors !== null && $result->getFailedRows() >= $this->maxErrors) {
                    $result->addWarning("Import stopped: Maximum errors ({$this->maxErrors}) reached");
                    return $result;
                }
            }
        }

        // Process remaining chunk
        if (!empty($chunk)) {
            $this->processChunk($import, $chunk, $rowNumbers, $chunkIndex, $result);
        }

        return $result;
    }

    /**
     * Process a single chunk.
     *
     * @param ImportableInterface $import
     * @param array<array<string|int, mixed>> $chunk
     * @param array<int> $rowNumbers
     * @param int $chunkIndex
     * @param ImportResult $result
     * @return void
     */
    private function processChunk(
        ImportableInterface $import,
        array $chunk,
        array $rowNumbers,
        int $chunkIndex,
        ImportResult $result
    ): void {
        $this->fireEvent($import, 'beforeChunk', [$chunkIndex]);

        $processRow = function () use ($import, $chunk, $rowNumbers, $result): void {
            foreach ($chunk as $index => $row) {
                try {
                    $import->row($row, $rowNumbers[$index]);
                    $result->incrementSuccess();
                } catch (\Throwable $e) {
                    $result->incrementFailed();
                    $result->addError($rowNumbers[$index], $e->getMessage(), $row);

                    if ($this->maxErrors !== null && $result->getFailedRows() >= $this->maxErrors) {
                        return;
                    }
                }
            }
        };

        // Wrap in transaction if callback provided
        if ($this->transactionCallback !== null) {
            ($this->transactionCallback)($processRow);
        } else {
            $processRow();
        }

        $this->fireEvent($import, 'afterChunk', [$chunkIndex, count($chunk)]);
    }

    /**
     * Validate a row.
     *
     * @param array<string|int, mixed> $row
     * @param array<string, string|array<string>> $rules
     * @param array<string, string> $messages
     * @return array<string> Validation errors
     */
    private function validateRow(array $row, array $rules, array $messages = []): array
    {
        if ($this->validationCallback !== null) {
            return ($this->validationCallback)($row, $rules);
        }

        // Simple built-in validation
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $ruleList = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $value = $row[$field] ?? null;

            foreach ($ruleList as $rule) {
                $error = $this->validateField($field, $value, $rule, $messages);
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * Validate a single field.
     *
     * @param string $field
     * @param mixed $value
     * @param string $rule
     * @param array<string, string> $messages
     * @return string|null Error message or null if valid
     */
    private function validateField(string $field, mixed $value, string $rule, array $messages = []): ?string
    {
        $ruleName = $rule;
        $ruleParam = null;

        if (str_contains($rule, ':')) {
            [$ruleName, $ruleParam] = explode(':', $rule, 2);
        }

        $isValid = match ($ruleName) {
            'required' => $value !== null && $value !== '',
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'numeric' => is_numeric($value),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'min' => is_numeric($value) && $value >= (float) $ruleParam,
            'max' => is_numeric($value) && $value <= (float) $ruleParam,
            'min_length' => strlen((string) $value) >= (int) $ruleParam,
            'max_length' => strlen((string) $value) <= (int) $ruleParam,
            'in' => in_array($value, explode(',', $ruleParam ?? ''), true),
            'regex' => preg_match($ruleParam ?? '', (string) $value) === 1,
            'date' => strtotime((string) $value) !== false,
            default => true,
        };

        if (!$isValid) {
            $messageKey = "{$field}.{$ruleName}";
            return $messages[$messageKey] ?? "The {$field} field failed the {$ruleName} validation.";
        }

        return null;
    }

    /**
     * Create appropriate reader for file type.
     *
     * @param string $filePath
     * @return ReaderInterface
     */
    private function createReader(string $filePath): ReaderInterface
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Use native CSV reader for CSV files (faster)
        if (in_array($extension, ['csv', 'tsv', 'txt'], true)) {
            $reader = new CsvReader();

            if ($extension === 'tsv') {
                $reader->setDelimiter("\t");
            }

            return $reader;
        }

        // Use OpenSpout for Excel files
        return new SpoutReader();
    }

    /**
     * Fire an event on the import.
     *
     * @param ImportableInterface $import
     * @param string $event
     * @param array<mixed> $args
     * @return void
     */
    private function fireEvent(ImportableInterface $import, string $event, array $args = []): void
    {
        if (!($import instanceof WithEventsInterface)) {
            return;
        }

        $events = $import->registerEvents();

        if (isset($events[$event]) && is_callable($events[$event])) {
            ($events[$event])(...$args);
        }
    }
}
