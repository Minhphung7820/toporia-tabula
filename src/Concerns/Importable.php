<?php

declare(strict_types=1);

namespace Toporia\Tabula\Concerns;

use Toporia\Tabula\Support\ImportResult;
use Toporia\Tabula\Tabula;

/**
 * Trait Importable
 *
 * Add this trait to models for easy import support.
 *
 * @example
 * class UserModel extends Model
 * {
 *     use Importable;
 * }
 *
 * UserModel::importFrom('/path/to/users.xlsx', function ($row) {
 *     return [
 *         'name' => $row['name'],
 *         'email' => $row['email'],
 *     ];
 * });
 */
trait Importable
{
    /**
     * Import from a file.
     *
     * @param string $filePath Path to the file
     * @param callable|null $mapper Row mapper function
     * @param array<string, mixed> $options Import options
     * @return ImportResult
     */
    public static function importFrom(
        string $filePath,
        ?callable $mapper = null,
        array $options = []
    ): ImportResult {
        $modelClass = static::class;
        $chunkSize = $options['chunk_size'] ?? 1000;

        // Create anonymous import class
        $import = new class($modelClass, $mapper, $chunkSize) extends \Toporia\Tabula\Imports\BaseImport
            implements \Toporia\Tabula\Contracts\WithChunkReadingInterface,
                       \Toporia\Tabula\Contracts\WithHeadingRowInterface {

            public function __construct(
                private string $modelClass,
                private mixed $mapper,
                private int $importChunkSize,
            ) {
            }

            public function row(array $row, int $rowNumber): void
            {
                $data = $this->mapper !== null
                    ? ($this->mapper)($row, $rowNumber)
                    : $row;

                if (!empty($data)) {
                    ($this->modelClass)::create($data);
                }
            }

            public function chunkSize(): int
            {
                return $this->importChunkSize;
            }

            public function headingRow(): int
            {
                return 1;
            }
        };

        return Tabula::import($import, $filePath, $options);
    }
}
