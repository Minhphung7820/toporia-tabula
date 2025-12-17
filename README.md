# Toporia Tabula

High-performance Excel/CSV import & export for Toporia Framework. Handles millions of rows with O(1) memory usage.

## Features

- **Streaming I/O**: O(1) memory - process millions of rows without memory issues
- **Chunk Processing**: Configurable batch sizes for optimal performance
- **Queue Support**: Background processing for large files
- **Validation**: Built-in row validation with custom rules
- **Multiple Formats**: XLSX, CSV, ODS support
- **Multiple Sheets**: Export to multiple sheets in one file
- **Progress Tracking**: Monitor import/export progress
- **Event System**: Hook into lifecycle events
- **Clean Architecture**: Interface-based design, SOLID principles

## Installation

```bash
composer require toporia/tabula
```

## Quick Start

### Import

```php
use Toporia\Tabula\Tabula;
use Toporia\Tabula\Imports\BaseImport;
use Toporia\Tabula\Contracts\WithChunkReadingInterface;
use Toporia\Tabula\Contracts\WithHeadingRowInterface;

class UsersImport extends BaseImport implements WithChunkReadingInterface, WithHeadingRowInterface
{
    public function row(array $row, int $rowNumber): void
    {
        User::create([
            'name' => $row['name'],
            'email' => $row['email'],
        ]);
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function headingRow(): int
    {
        return 1;
    }
}

// Run import
$result = Tabula::import(new UsersImport(), '/path/to/users.xlsx');

echo "Imported: {$result->getSuccessRows()} rows";
echo "Failed: {$result->getFailedRows()} rows";
echo "Duration: {$result->getDuration()} seconds";
```

### Export

```php
use Toporia\Tabula\Tabula;
use Toporia\Tabula\Exports\BaseExport;

class UsersExport extends BaseExport
{
    public function data(): iterable
    {
        // Use generator for large datasets
        foreach (User::query()->cursor() as $user) {
            yield [
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at,
            ];
        }
    }

    public function headers(): array
    {
        return ['Name', 'Email', 'Created At'];
    }
}

// Export to file
$result = Tabula::export(new UsersExport(), '/path/to/users.xlsx');

// Or download directly
Tabula::download(new UsersExport(), 'users.xlsx');
```

### Queue Large Files

```php
// Queue import for background processing
Tabula::queueImport(UsersImport::class, '/path/to/large-file.xlsx');

// Queue export
Tabula::queueExport(UsersExport::class, '/path/to/output.xlsx');
```

## Advanced Usage

### Validation

```php
use Toporia\Tabula\Contracts\WithValidationInterface;

class UsersImport extends BaseImport implements WithValidationInterface
{
    public function row(array $row, int $rowNumber): void
    {
        User::create($row);
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'name' => 'required|min_length:2',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'email.required' => 'Email is required',
            'email.email' => 'Invalid email format',
        ];
    }
}
```

### Row Mapping

```php
use Toporia\Tabula\Contracts\WithMappingInterface;

class UsersImport extends BaseImport implements WithMappingInterface
{
    public function row(array $row, int $rowNumber): void
    {
        User::create($row);
    }

    public function map(array $row): array
    {
        return [
            'name' => $row['Full Name'] ?? $row[0],
            'email' => strtolower($row['Email Address'] ?? $row[1]),
            'age' => (int) ($row['Age'] ?? $row[2]),
        ];
    }
}
```

### Multiple Sheets Export

```php
use Toporia\Tabula\Contracts\WithMultipleSheetsInterface;
use Toporia\Tabula\Contracts\WithTitleInterface;

class ReportExport extends BaseExport implements WithMultipleSheetsInterface
{
    public function sheets(): array
    {
        return [
            new UsersSheet(),
            new OrdersSheet(),
            new ProductsSheet(),
        ];
    }

    public function data(): iterable
    {
        return [];
    }

    public function headers(): array
    {
        return [];
    }
}

class UsersSheet extends BaseExport implements WithTitleInterface
{
    public function title(): string
    {
        return 'Users';
    }

    public function data(): iterable
    {
        return User::all();
    }

    public function headers(): array
    {
        return ['ID', 'Name', 'Email'];
    }
}
```

### Progress Tracking

```php
use Toporia\Tabula\Contracts\WithProgressInterface;

class UsersImport extends BaseImport implements WithProgressInterface
{
    public function row(array $row, int $rowNumber): void
    {
        User::create($row);
    }

    public function onProgress(int $current, int $total, float $percentage): void
    {
        echo "Progress: {$percentage}% ({$current}/{$total})\n";
    }
}
```

### Events

```php
use Toporia\Tabula\Contracts\WithEventsInterface;

class UsersImport extends BaseImport implements WithEventsInterface
{
    public function row(array $row, int $rowNumber): void
    {
        User::create($row);
    }

    public function registerEvents(): array
    {
        return [
            'beforeImport' => fn() => Log::info('Starting import'),
            'afterImport' => fn($result) => Log::info("Imported {$result->getTotalRows()} rows"),
            'beforeChunk' => fn($index) => Log::info("Processing chunk {$index}"),
            'afterChunk' => fn($index, $count) => Log::info("Processed {$count} rows"),
            'onError' => fn($e, $row, $rowNum) => Log::error("Error on row {$rowNum}"),
        ];
    }
}
```

### Model Traits

```php
use Toporia\Tabula\Concerns\Importable;
use Toporia\Tabula\Concerns\Exportable;

class User extends Model
{
    use Importable, Exportable;
}

// Import with mapping
User::importFrom('/path/to/users.xlsx', function ($row) {
    return [
        'name' => $row['name'],
        'email' => $row['email'],
    ];
});

// Export specific columns
User::exportTo('/path/to/users.xlsx', ['id', 'name', 'email']);

// Download
User::downloadAs('users.xlsx', ['id', 'name', 'email']);
```

## Performance

Tested with files containing millions of rows:

| Operation | Rows | Memory | Time |
|-----------|------|--------|------|
| Import | 1,000,000 | ~50MB | ~120s |
| Export | 1,000,000 | ~30MB | ~90s |
| CSV Import | 1,000,000 | ~20MB | ~45s |
| CSV Export | 1,000,000 | ~15MB | ~30s |

## License

MIT
