<?php

declare(strict_types=1);

namespace Toporia\Tabula\Exports;

use Toporia\Tabula\Contracts\ExportableInterface;
use Toporia\Tabula\Contracts\FromQueryInterface;
use Toporia\Tabula\Contracts\WithColumnFormattingInterface;
use Toporia\Tabula\Contracts\WithProgressInterface;
use Toporia\Tabula\Contracts\WithTitleInterface;
use Toporia\Tabula\Contracts\WithTotalCountInterface;

/**
 * Class FromQueryExport
 *
 * Export class for exporting from a database query.
 * Uses lazyById() for memory-efficient streaming with buffered queries.
 *
 * Why lazyById() instead of cursor():
 * - cursor() uses MySQL unbuffered queries which don't allow other queries
 *   on the same connection while iterating (breaks progress callbacks that update DB)
 * - lazyById() uses buffered chunk queries with indexed WHERE id > lastId
 * - Both are O(chunkSize) memory, but lazyById() is more compatible
 *
 * @example
 * // Simple usage
 * $export = FromQueryExport::make(User::query()->where('active', true))
 *     ->columns(['id', 'name', 'email', 'created_at'])
 *     ->headers(['ID', 'Name', 'Email', 'Created At']);
 *
 * Tabula::export($export, '/path/to/users.xlsx');
 *
 * // With formatting
 * $export = FromQueryExport::make(Product::query())
 *     ->columns(['sku', 'name', 'price', 'stock'])
 *     ->format('price', fn($v) => number_format($v, 2))
 *     ->format('stock', fn($v) => $v > 0 ? $v : 'Out of Stock');
 */
final class FromQueryExport implements
    ExportableInterface,
    FromQueryInterface,
    WithColumnFormattingInterface,
    WithProgressInterface,
    WithTitleInterface,
    WithTotalCountInterface
{
    private mixed $queryBuilder;

    /**
     * @var array<string>|null Columns to export
     */
    private ?array $columns = null;

    /**
     * @var array<string> Headers
     */
    private array $headerRow = [];

    /**
     * @var array<string, callable> Column formatters
     */
    private array $formatters = [];

    private string $titleValue = 'Sheet1';

    /**
     * @var callable|null Progress callback
     */
    private $progressCallback = null;

    /**
     * @var int Total row count for progress tracking
     */
    private int $totalRowCount = 0;

    public function __construct(mixed $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * Create a new instance from a query builder.
     *
     * @param mixed $queryBuilder Query builder instance
     * @return self
     */
    public static function make(mixed $queryBuilder): self
    {
        return new self($queryBuilder);
    }

    /**
     * Create from a model class.
     *
     * @param string $modelClass Model class name
     * @return self
     */
    public static function model(string $modelClass): self
    {
        return new self($modelClass::query());
    }

    /**
     * Set columns to export.
     *
     * @param array<string> $columns Column names
     * @return self
     */
    public function columns(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * Set headers.
     *
     * @param array<string> $headers Header labels
     * @return self
     */
    public function withHeaders(array $headers): self
    {
        $this->headerRow = $headers;
        return $this;
    }

    /**
     * Add a column formatter.
     *
     * @param string $column Column name
     * @param callable(mixed): mixed $formatter Formatter function
     * @return self
     */
    public function format(string $column, callable $formatter): self
    {
        $this->formatters[$column] = $formatter;
        return $this;
    }

    /**
     * Set sheet title.
     *
     * @param string $titleStr
     * @return self
     */
    public function withTitle(string $titleStr): self
    {
        $this->titleValue = substr($titleStr, 0, 31);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function title(): string
    {
        return $this->titleValue;
    }

    /**
     * Set total row count for progress tracking.
     *
     * @param int $count Total number of rows
     * @return self
     */
    public function withTotalCount(int $count): self
    {
        $this->totalRowCount = $count;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function totalCount(): int
    {
        return $this->totalRowCount;
    }

    /**
     * Set progress callback.
     *
     * @param callable(int, int): void $callback Callback receives (processed, total)
     * @return self
     *
     * @example
     *     ->withProgressCallback(function (int $processed, int $total) {
     *         echo "Exported {$processed}/{$total} rows\n";
     *     })
     */
    public function withProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function onProgress(int $current, int $total, float $percentage): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($current, $total);
        }
    }

    /**
     * Check if progress callback is set.
     *
     * @return bool
     */
    public function hasProgressCallback(): bool
    {
        return $this->progressCallback !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function query(): mixed
    {
        $query = $this->queryBuilder;

        // Select only specified columns
        if ($this->columns !== null && method_exists($query, 'select')) {
            $query = $query->select($this->columns);
        }

        return $query;
    }

    /**
     * {@inheritdoc}
     */
    public function data(): iterable
    {
        $query = $this->query();

        // Use lazyById for memory-efficient streaming with buffered queries.
        // Why not cursor()? cursor() uses MySQL unbuffered queries which don't allow
        // other queries on the same connection while iterating. This breaks progress
        // callbacks that need to update the database (e.g., job progress tracking).
        // lazyById() uses buffered chunk queries with indexed WHERE id > lastId,
        // which is both memory-efficient O(chunkSize) and allows DB operations during iteration.
        if (method_exists($query, 'lazyById')) {
            foreach ($query->lazyById(1000) as $model) {
                yield $this->mapModel($model);
            }
        } elseif (method_exists($query, 'lazy')) {
            // Fallback to lazy() if lazyById() not available
            foreach ($query->lazy(1000) as $model) {
                yield $this->mapModel($model);
            }
        } elseif (method_exists($query, 'cursor')) {
            // Last resort: cursor() - but progress callbacks with DB updates will fail
            foreach ($query->cursor() as $model) {
                yield $this->mapModel($model);
            }
        } elseif (method_exists($query, 'get')) {
            foreach ($query->get() as $model) {
                yield $this->mapModel($model);
            }
        } elseif (is_iterable($query)) {
            foreach ($query as $model) {
                yield $this->mapModel($model);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function headers(): array
    {
        if (!empty($this->headerRow)) {
            return $this->headerRow;
        }

        // Generate headers from columns
        if ($this->columns !== null) {
            return array_map(function ($column) {
                return ucwords(str_replace('_', ' ', $column));
            }, $this->columns);
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function columnFormats(): array
    {
        return $this->formatters;
    }

    /**
     * Map model to row array.
     *
     * @param mixed $model
     * @return array<mixed>
     */
    private function mapModel(mixed $model): array
    {
        // Get model data
        if (method_exists($model, 'toArray')) {
            $data = $model->toArray();
        } else {
            $data = (array) $model;
        }

        // Filter columns if specified
        if ($this->columns !== null) {
            $filtered = [];
            foreach ($this->columns as $column) {
                $value = $data[$column] ?? null;

                // Apply formatter if exists
                if (isset($this->formatters[$column])) {
                    $value = ($this->formatters[$column])($value);
                }

                $filtered[] = $value;
            }
            return $filtered;
        }

        // Apply formatters to all columns
        $result = [];
        foreach ($data as $column => $value) {
            if (isset($this->formatters[$column])) {
                $value = ($this->formatters[$column])($value);
            }
            $result[] = $value;
        }

        return $result;
    }
}
