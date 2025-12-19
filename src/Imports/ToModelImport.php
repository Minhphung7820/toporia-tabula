<?php

declare(strict_types=1);

namespace Toporia\Tabula\Imports;

use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Contracts\ToModelInterface;
use Toporia\Tabula\Contracts\WithBatchInsertsInterface;
use Toporia\Tabula\Contracts\WithChunkReadingInterface;
use Toporia\Tabula\Contracts\WithHeadingRowInterface;

/**
 * Class ToModelImport
 *
 * Import class for importing directly to a model.
 * Supports batch inserts, upserts, and automatic chunking.
 *
 * Performance optimizations (v2.0):
 * - mapRow() method for batch mapping via array_map() in Importer
 * - bulkInsert() receives pre-mapped data (no double mapping)
 * - Direct Model::insert() without intermediate buffering
 *
 * @example
 * // Simple usage
 * $import = ToModelImport::make(User::class)
 *     ->map(fn($row) => [
 *         'name' => $row['name'],
 *         'email' => $row['email'],
 *     ]);
 *
 * Tabula::import($import, '/path/to/users.xlsx');
 *
 * // With upsert
 * $import = ToModelImport::make(Product::class)
 *     ->map(fn($row) => [
 *         'sku' => $row['sku'],
 *         'name' => $row['name'],
 *         'price' => $row['price'],
 *     ])
 *     ->upsertBy(['sku']);
 */
final class ToModelImport implements
    ImportableInterface,
    ToModelInterface,
    WithChunkReadingInterface,
    WithBatchInsertsInterface,
    WithHeadingRowInterface
{
    private string $modelClass;

    /**
     * @var callable|null Row mapper
     */
    private $mapper = null;

    /**
     * @var array<string>|null Unique columns for upsert
     */
    private ?array $uniqueBy = null;

    /**
     * @var array<string>|null Columns to update on upsert
     */
    private ?array $upsertColumns = null;

    private int $chunkSizeValue = 1000;
    private int $batchSizeValue = 500;
    private int $headingRowValue = 1;

    /**
     * @var array<array<string, mixed>> Batch buffer for inserts
     */
    private array $batchBuffer = [];

    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

    /**
     * Create a new instance.
     *
     * @param string $modelClass Model class name
     * @return self
     */
    public static function make(string $modelClass): self
    {
        return new self($modelClass);
    }

    /**
     * Set row mapper (fluent API).
     *
     * This method sets the mapper callable and returns self for chaining.
     * The actual mapping is done via WithMappingInterface::map().
     *
     * @param callable(array<string|int, mixed>): ?array<string, mixed> $mapper
     * @return self
     */
    public function map(callable $mapper): self
    {
        $this->mapper = $mapper;
        return $this;
    }

    /**
     * Map a single row using the configured mapper.
     *
     * Called by Importer::processChunkedOptimized() via array_map().
     * This enables batch mapping which is faster than mapping in bulkInsert().
     *
     * @param array<string|int, mixed> $row Raw CSV row
     * @return array<string, mixed> Mapped row for database insert
     */
    public function mapRow(array $row): array
    {
        if ($this->mapper !== null) {
            $result = ($this->mapper)($row);
            return $result ?? [];
        }
        return $row;
    }

    /**
     * Enable upsert mode.
     *
     * @param array<string> $uniqueBy Unique columns
     * @param array<string>|null $updateColumns Columns to update (null = all)
     * @return self
     */
    public function upsertBy(array $uniqueBy, ?array $updateColumns = null): self
    {
        $this->uniqueBy = $uniqueBy;
        $this->upsertColumns = $updateColumns;
        return $this;
    }

    /**
     * Set chunk size (rows read from file per batch).
     *
     * @param int $size
     * @return self
     */
    public function chunk(int $size): self
    {
        $this->chunkSizeValue = $size;
        return $this;
    }

    /**
     * Set batch size for database inserts.
     *
     * @param int $size
     * @return self
     */
    public function batch(int $size): self
    {
        $this->batchSizeValue = $size;
        return $this;
    }

    /**
     * Set heading row number.
     *
     * @param int $row
     * @return self
     */
    public function headingRowAt(int $row): self
    {
        $this->headingRowValue = $row;
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Process a single row (fallback for non-bulk imports).
     */
    public function row(array $row, int $rowNumber): void
    {
        $data = $this->modelData($row);

        if ($data === null) {
            return;
        }

        $this->batchBuffer[] = $data;

        // Flush batch when full
        if (count($this->batchBuffer) >= $this->batchSizeValue) {
            $this->flushBatch();
        }
    }

    /**
     * Bulk insert multiple rows at once.
     *
     * IMPORTANT: When called from Importer::processChunkedOptimized(),
     * rows are ALREADY MAPPED via WithMappingInterface::map().
     * We skip re-mapping to avoid double processing.
     *
     * @param array<array<string|int, mixed>> $rows Array of rows (pre-mapped from Importer)
     * @return int Number of rows inserted
     */
    public function bulkInsert(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        // Rows are already mapped by Importer::processChunkedOptimized()
        // via array_map([$import, 'mapRow'], $batch)
        // So we directly insert without re-mapping
        return $this->insertRows($rows);
    }

    /**
     * Insert rows into database in batches.
     *
     * @param array<array<string, mixed>> $rows Mapped rows
     * @return int Number of rows inserted
     */
    private function insertRows(array $rows): int
    {
        $model = $this->modelClass;
        $total = 0;

        // Split into batches for database insert
        $chunks = array_chunk($rows, $this->batchSizeValue);

        foreach ($chunks as $chunk) {
            if ($this->uniqueBy !== null && method_exists($model, 'upsert')) {
                $model::upsert($chunk, $this->uniqueBy, $this->upsertColumns);
            } elseif (method_exists($model, 'insert')) {
                $model::insert($chunk);
            } else {
                // Fallback: individual creates (slow)
                foreach ($chunk as $data) {
                    $model::create($data);
                }
            }
            $total += count($chunk);
        }

        return $total;
    }

    /**
     * {@inheritdoc}
     */
    public function model(): string
    {
        return $this->modelClass;
    }

    /**
     * Get model data from row (applies mapping if needed).
     *
     * Used by row() method for non-bulk processing.
     *
     * @param array<string|int, mixed> $row
     * @return array<string, mixed>|null
     */
    public function modelData(array $row): ?array
    {
        if ($this->mapper !== null) {
            return ($this->mapper)($row);
        }

        return $row;
    }

    /**
     * {@inheritdoc}
     */
    public function chunkSize(): int
    {
        return $this->chunkSizeValue;
    }

    /**
     * {@inheritdoc}
     */
    public function batchSize(): int
    {
        return $this->batchSizeValue;
    }

    /**
     * {@inheritdoc}
     */
    public function headingRow(): int
    {
        return $this->headingRowValue;
    }

    /**
     * Flush the batch buffer to database.
     *
     * @return void
     */
    public function flushBatch(): void
    {
        if (empty($this->batchBuffer)) {
            return;
        }

        $model = $this->modelClass;

        if ($this->uniqueBy !== null) {
            // Upsert mode
            if (method_exists($model, 'upsert')) {
                $model::upsert($this->batchBuffer, $this->uniqueBy, $this->upsertColumns);
            } else {
                // Fallback: individual upserts
                foreach ($this->batchBuffer as $data) {
                    $model::updateOrCreate(
                        array_intersect_key($data, array_flip($this->uniqueBy)),
                        $data
                    );
                }
            }
        } else {
            // Insert mode
            if (method_exists($model, 'insert')) {
                $model::insert($this->batchBuffer);
            } else {
                // Fallback: individual creates
                foreach ($this->batchBuffer as $data) {
                    $model::create($data);
                }
            }
        }

        $this->batchBuffer = [];
    }

    /**
     * Destructor - flush remaining batch.
     */
    public function __destruct()
    {
        $this->flushBatch();
    }
}
