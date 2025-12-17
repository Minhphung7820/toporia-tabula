<?php

declare(strict_types=1);

namespace Toporia\Tabula\Imports;

use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Contracts\ToModelInterface;
use Toporia\Tabula\Contracts\WithBatchInsertsInterface;
use Toporia\Tabula\Contracts\WithChunkReadingInterface;
use Toporia\Tabula\Contracts\WithHeadingRowInterface;
use Toporia\Tabula\Contracts\WithUpsertInterface;

/**
 * Class ToModelImport
 *
 * Import class for importing directly to a model.
 * Supports batch inserts, upserts, and automatic chunking.
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
     * Set row mapper.
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
     * Set chunk size.
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
     * {@inheritdoc}
     */
    public function model(): string
    {
        return $this->modelClass;
    }

    /**
     * {@inheritdoc}
     */
    public function modelData(array $row): ?array
    {
        if ($this->mapper !== null) {
            return ($this->mapper)($row);
        }

        // Default: use row as-is
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
