<?php

declare(strict_types=1);

namespace Toporia\Tabula\Concerns;

use Toporia\Tabula\Support\ExportResult;
use Toporia\Tabula\Tabula;

/**
 * Trait Exportable
 *
 * Add this trait to models for easy export support.
 *
 * @example
 * class UserModel extends Model
 * {
 *     use Exportable;
 * }
 *
 * UserModel::exportTo('/path/to/users.xlsx', ['id', 'name', 'email']);
 * UserModel::where('active', true)->exportTo('/path/to/active-users.xlsx');
 */
trait Exportable
{
    /**
     * Export to a file.
     *
     * @param string $filePath Destination file path
     * @param array<string>|null $columns Columns to export (null = all)
     * @param array<string>|null $headers Custom headers (null = use column names)
     * @return ExportResult
     */
    public static function exportTo(
        string $filePath,
        ?array $columns = null,
        ?array $headers = null
    ): ExportResult {
        $modelClass = static::class;

        // Create anonymous export class
        $export = new class($modelClass, $columns, $headers) extends \Toporia\Tabula\Exports\BaseExport {

            public function __construct(
                private string $modelClass,
                private ?array $columns,
                private ?array $exportHeaders,
            ) {
            }

            public function data(): iterable
            {
                // Use cursor for memory efficiency
                $query = ($this->modelClass)::query();

                // Select specific columns if provided
                if ($this->columns !== null) {
                    $query = $query->select($this->columns);
                }

                // Use cursor() if available, otherwise get()
                if (method_exists($query, 'cursor')) {
                    foreach ($query->cursor() as $model) {
                        yield $this->modelToArray($model);
                    }
                } else {
                    foreach ($query->get() as $model) {
                        yield $this->modelToArray($model);
                    }
                }
            }

            public function headers(): array
            {
                if ($this->exportHeaders !== null) {
                    return $this->exportHeaders;
                }

                if ($this->columns !== null) {
                    // Convert column names to title case
                    return array_map(function ($column) {
                        return ucwords(str_replace('_', ' ', $column));
                    }, $this->columns);
                }

                return [];
            }

            private function modelToArray(mixed $model): array
            {
                if (method_exists($model, 'toArray')) {
                    $data = $model->toArray();
                } else {
                    $data = (array) $model;
                }

                // Filter to specific columns if provided
                if ($this->columns !== null) {
                    $filtered = [];
                    foreach ($this->columns as $column) {
                        $filtered[$column] = $data[$column] ?? null;
                    }
                    return array_values($filtered);
                }

                return array_values($data);
            }
        };

        return Tabula::export($export, $filePath);
    }

    /**
     * Export and download.
     *
     * @param string $filename Download filename
     * @param array<string>|null $columns Columns to export
     * @param array<string>|null $headers Custom headers
     * @param string $format File format
     * @return void
     */
    public static function downloadAs(
        string $filename,
        ?array $columns = null,
        ?array $headers = null,
        string $format = 'xlsx'
    ): void {
        $modelClass = static::class;

        $export = new class($modelClass, $columns, $headers) extends \Toporia\Tabula\Exports\BaseExport {

            public function __construct(
                private string $modelClass,
                private ?array $columns,
                private ?array $exportHeaders,
            ) {
            }

            public function data(): iterable
            {
                $query = ($this->modelClass)::query();

                if ($this->columns !== null) {
                    $query = $query->select($this->columns);
                }

                if (method_exists($query, 'cursor')) {
                    foreach ($query->cursor() as $model) {
                        yield $this->modelToArray($model);
                    }
                } else {
                    foreach ($query->get() as $model) {
                        yield $this->modelToArray($model);
                    }
                }
            }

            public function headers(): array
            {
                if ($this->exportHeaders !== null) {
                    return $this->exportHeaders;
                }

                if ($this->columns !== null) {
                    return array_map(function ($column) {
                        return ucwords(str_replace('_', ' ', $column));
                    }, $this->columns);
                }

                return [];
            }

            private function modelToArray(mixed $model): array
            {
                if (method_exists($model, 'toArray')) {
                    $data = $model->toArray();
                } else {
                    $data = (array) $model;
                }

                if ($this->columns !== null) {
                    $filtered = [];
                    foreach ($this->columns as $column) {
                        $filtered[$column] = $data[$column] ?? null;
                    }
                    return array_values($filtered);
                }

                return array_values($data);
            }
        };

        Tabula::download($export, $filename, $format);
    }
}
