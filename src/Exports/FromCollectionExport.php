<?php

declare(strict_types=1);

namespace Toporia\Tabula\Exports;

use Toporia\Tabula\Contracts\ExportableInterface;
use Toporia\Tabula\Contracts\FromCollectionInterface;
use Toporia\Tabula\Contracts\WithColumnFormattingInterface;
use Toporia\Tabula\Contracts\WithTitleInterface;

/**
 * Class FromCollectionExport
 *
 * Export class for exporting from a collection or array.
 *
 * @example
 * // From array
 * $export = FromCollectionExport::make($users)
 *     ->columns(['name', 'email'])
 *     ->headers(['Name', 'Email']);
 *
 * // From callback (lazy loading)
 * $export = FromCollectionExport::lazy(function () {
 *     foreach (User::cursor() as $user) {
 *         yield $user;
 *     }
 * });
 */
final class FromCollectionExport implements
    ExportableInterface,
    FromCollectionInterface,
    WithColumnFormattingInterface,
    WithTitleInterface
{
    /**
     * @var iterable<mixed>|callable Data source
     */
    private $source;

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

    /**
     * @var callable|null Row mapper
     */
    private $mapper = null;

    private string $titleValue = 'Sheet1';

    /**
     * @param iterable<mixed>|callable $source
     */
    public function __construct(iterable|callable $source)
    {
        $this->source = $source;
    }

    /**
     * Create from a collection or array.
     *
     * @param iterable<mixed> $collection
     * @return self
     */
    public static function make(iterable $collection): self
    {
        return new self($collection);
    }

    /**
     * Create from a lazy callback (generator).
     *
     * @param callable(): iterable<mixed> $callback
     * @return self
     */
    public static function lazy(callable $callback): self
    {
        return new self($callback);
    }

    /**
     * Create from raw data array.
     *
     * @param array<array<mixed>> $rows Raw row data
     * @return self
     */
    public static function fromArray(array $rows): self
    {
        return new self($rows);
    }

    /**
     * Set columns to export.
     *
     * @param array<string> $columns
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
     * @param array<string> $headers
     * @return self
     */
    public function headers(array $headers): self
    {
        $this->headerRow = $headers;
        return $this;
    }

    /**
     * Set row mapper.
     *
     * @param callable(mixed): array<mixed> $mapper
     * @return self
     */
    public function map(callable $mapper): self
    {
        $this->mapper = $mapper;
        return $this;
    }

    /**
     * Add a column formatter.
     *
     * @param string $column
     * @param callable(mixed): mixed $formatter
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
    public function collection(): iterable
    {
        if (is_callable($this->source)) {
            return ($this->source)();
        }
        return $this->source;
    }

    /**
     * {@inheritdoc}
     */
    public function data(): iterable
    {
        foreach ($this->collection() as $item) {
            yield $this->mapItem($item);
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
     * Map item to row array.
     *
     * @param mixed $item
     * @return array<mixed>
     */
    private function mapItem(mixed $item): array
    {
        // Use custom mapper if set
        if ($this->mapper !== null) {
            return ($this->mapper)($item);
        }

        // Get item data
        if (method_exists($item, 'toArray')) {
            $data = $item->toArray();
        } elseif (is_array($item)) {
            $data = $item;
        } else {
            $data = (array) $item;
        }

        // Filter columns if specified
        if ($this->columns !== null) {
            $result = [];
            foreach ($this->columns as $column) {
                $value = $data[$column] ?? null;

                if (isset($this->formatters[$column])) {
                    $value = ($this->formatters[$column])($value);
                }

                $result[] = $value;
            }
            return $result;
        }

        // Apply formatters
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
