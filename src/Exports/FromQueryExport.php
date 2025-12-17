<?php

declare(strict_types=1);

namespace Toporia\Tabula\Exports;

use Toporia\Tabula\Contracts\ExportableInterface;
use Toporia\Tabula\Contracts\FromQueryInterface;
use Toporia\Tabula\Contracts\WithColumnFormattingInterface;
use Toporia\Tabula\Contracts\WithTitleInterface;

/**
 * Class FromQueryExport
 *
 * Export class for exporting from a database query.
 * Uses cursor() for memory-efficient streaming.
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
    WithTitleInterface
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
    public function headers(array $headers): self
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
     * @param string $title
     * @return self
     */
    public function title(string $title): self
    {
        $this->titleValue = substr($title, 0, 31);
        return $this;
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

        // Use cursor for memory efficiency
        if (method_exists($query, 'cursor')) {
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
