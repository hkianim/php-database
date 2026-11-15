# PHP Database

A reusable PHP database package providing a query builder, data sources, and a repository pattern for MySQL and MariaDB applications.

## Features

- Repository-based database access
- PDO dependency injection
- Table, view, and custom query data sources
- Independent read and write sources
- Nested filters with `AND` and `OR` groups
- Multiple sorting fields
- Pagination
- Result counting
- Composite primary key support
- Prepared statements and parameter binding
- Extensible repositories for application-specific requirements
- PHP 7.4 compatibility

## Requirements

- PHP 7.4 or later
- PDO extension
- PDO MySQL driver
- MySQL or MariaDB

The package does not create database connections. A configured PDO instance must be provided by the application.

## Installation

Install the package using Composer:

```bash
composer require hkianim/php-database
```

For local development:

```bash
composer install
composer dump-autoload
```

## Package Structure

```text
src/
├── Contracts/
│   └── DataSourceInterface.php
├── DataSource/
│   ├── TableSource.php
│   ├── ViewSource.php
│   └── QuerySource.php
├── Query/
│   ├── FilterCompiler.php
│   ├── QueryBuilder.php
│   └── QueryExecutor.php
├── Repository/
│   └── BaseRepository.php
└── Exceptions/
    ├── RepositoryException.php
    ├── DataSourceException.php
    ├── RepositoryValidationException.php
    └── QueryException.php
```

## Architecture

The package separates database query construction, data source definitions, and repository operations.

### Data Sources

A data source defines where a repository reads its data from.

Three data source types are available:

| Class | Description |
|---|---|
| `TableSource` | Reads from a database table |
| `ViewSource` | Reads from a database view |
| `QuerySource` | Reads from a custom SQL query |

All data sources implement `DataSourceInterface`:

```php
interface DataSourceInterface
{
    public function getFromClause(): string;

    public function getBindings(): array;
}
```

Data sources are immutable. Their configuration is supplied through their constructors.

#### TableSource

```php
use Hkianim\Database\DataSource\TableSource;

$source = new TableSource('HsbNumbers');
```

#### ViewSource

```php
use Hkianim\Database\DataSource\ViewSource;

$source = new ViewSource('vwHsbNumbers');
```

#### QuerySource

A custom query can be used as a derived table:

```php
use Hkianim\Database\DataSource\QuerySource;

$source = new QuerySource(
    'SELECT id, hsbNo FROM HsbNumbers WHERE hsbType = ?',
    ['htBank']
);
```

`QuerySource` uses positional PDO placeholders (`?`).

Custom queries should:

- Contain a single SELECT statement.
- Return columns with explicit, unique names.
- Avoid unnecessary `ORDER BY` and `LIMIT` clauses when outer sorting and pagination are intended.
- Use bound parameters for values instead of interpolating them into SQL.

The package does not parse or rewrite the internal SQL query.

### QueryBuilder

`QueryBuilder` generates SELECT queries using a configured data source.

Its responsibilities include:

- Selecting columns
- Applying filters
- Applying sorting
- Applying pagination
- Generating a count query

The query builder does not execute SQL.

It generates two independent queries:

- `data`: the requested result set
- `count`: the total number of matching records, without sorting or pagination

### FilterCompiler

`FilterCompiler` converts structured filter definitions into SQL conditions and positional bindings.

It supports nested `AND` and `OR` groups and validates column names against the available columns supplied to its constructor.

### QueryExecutor

`QueryExecutor` executes prepared SQL statements using PDO.

It provides:

- `fetchAll()`
- `fetchOne()`
- `fetchColumn()`
- `execute()`

Scalar values are bound according to their PHP types.

### BaseRepository

`BaseRepository` provides common database operations.

Application-specific repositories extend this class and define their data source, primary key, available columns, and write table where applicable.

The repository uses the injected PDO connection and does not manage transactions.

## Database Connection

Create and configure the PDO connection in your application:

```php
$pdo = new PDO(
    'mysql:host=localhost;dbname=my_database;charset=utf8mb4',
    'username',
    'password',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
```

Pass the connection to your repository.

The application is responsible for connection configuration, lifecycle, and transaction management.

## Creating a Repository

A typical application repository extends `BaseRepository`:

```php
use Hkianim\Database\Repository\BaseRepository;
use Hkianim\Database\DataSource\TableSource;
use Hkianim\Database\Contracts\DataSourceInterface;

class HsbNumberRepository extends BaseRepository
{
    protected $select = [];

    protected $maxPerPage = 100;

    protected function getDataSource(): DataSourceInterface
    {
        return new TableSource('HsbNumbers');
    }

    protected function getPrimaryKey(): array
    {
        return ['id'];
    }

    protected function getWriteTable(): ?string
    {
        return 'HsbNumbers';
    }

    protected function getAvailableColumns(): array
    {
        return [
            'id',
            'hsbNo',
            'hsbType',
            'title',
            'balance',
        ];
    }
}
```

The example illustrates the repository configuration contract.

### Repository Configuration

| Property or method | Purpose |
|---|---|
| `$select` | Default columns selected by the repository |
| `$maxPerPage` | Maximum permitted page size |
| `getDataSource()` | Returns the repository's read source |
| `getPrimaryKey()` | Returns primary key column names as an array |
| `getWriteTable()` | Returns the table used for write operations |
| `getAvailableColumns()` | Defines columns available for selection, filtering, and sorting |

An empty `$select` array means all columns are selected by default.

The `getWriteTable()` configuration is independent of the read data source. This allows a repository to read from a view or custom query while writing to an underlying table.

If the default write behavior is insufficient for a particular repository, the relevant write method can be overridden.

## Reading Data

### getAll()

```php
$repository = new HsbNumberRepository($pdo);

$result = $repository->getAll();
```

The result always contains two keys:

```php
[
    'data' => [],
    'count' => null,
]
```

### Selecting Columns

```php
$result = $repository->getAll([
    'select' => ['id', 'hsbNo', 'title'],
]);
```

The `select` option overrides the repository's default `$select` property.

An empty array means all available columns.

### Filtering

Filters use a structured format consisting of logical groups and individual conditions.

Every non-empty filter must be a group containing:

- `operator`: either `and` or `or`
- `conditions`: an array of nested groups or individual conditions

#### Basic Filter

```php
$result = $repository->getAll([
    'filter' => [
        'operator' => 'and',
        'conditions' => [
            [
                'field' => 'hsbType',
                'operator' => 'eq',
                'value' => 'htBank',
            ],
        ],
    ],
]);
```

#### Multiple Conditions

Conditions inside a group are combined using the group's logical operator.

```php
$filter = [
    'operator' => 'and',
    'conditions' => [
        [
            'field' => 'hsbType',
            'operator' => 'eq',
            'value' => 'htBank',
        ],
        [
            'field' => 'balance',
            'operator' => 'gt',
            'value' => 0,
        ],
    ],
];
```

#### Nested AND/OR Groups

Groups can be nested to create complex conditions.

```php
$filter = [
    'operator' => 'and',
    'conditions' => [
        [
            'field' => 'hsbType',
            'operator' => 'eq',
            'value' => 'htBank',
        ],
        [
            'operator' => 'or',
            'conditions' => [
                [
                    'field' => 'balance',
                    'operator' => 'gt',
                    'value' => 1000,
                ],
                [
                    'field' => 'title',
                    'operator' => 'contains',
                    'value' => 'Main',
                ],
            ],
        ],
    ],
];
```

This represents:

```sql
hsbType = 'htBank'
AND
(
    balance > 1000
    OR title LIKE '%Main%'
)
```

#### NULL Conditions

`eq` and `neq` support `null` values:

```php
[
    'field' => 'title',
    'operator' => 'eq',
    'value' => null,
]
```

This is compiled into `IS NULL`.

Similarly, `neq` with a null value is compiled into `IS NOT NULL`.

Dedicated operators are also available:

```php
[
    'field' => 'title',
    'operator' => 'isNull',
]
```

```php
[
    'field' => 'title',
    'operator' => 'isNotNull',
]
```

The `isNull` and `isNotNull` operators do not require a `value` field.

NULL is not supported with comparison operators such as `gt`, `gte`, `lt`, and `lte`.

#### IN and NOT IN

```php
[
    'field' => 'hsbType',
    'operator' => 'in',
    'value' => ['htBank', 'htCash'],
]
```

```php
[
    'field' => 'hsbType',
    'operator' => 'notIn',
    'value' => ['htBank', 'htCash'],
]
```

Values must be provided as an array of non-null scalar values.

Empty arrays are handled explicitly:

- Empty `in` produces `1 = 0`.
- Empty `notIn` produces `1 = 1`.

#### LIKE Operators

```php
[
    'field' => 'title',
    'operator' => 'contains',
    'value' => 'Main',
]
```

Available operators:

| Operator | Description |
|---|---|
| `contains` | Contains a substring |
| `startsWith` | Starts with a substring |
| `endsWith` | Ends with a substring |

The supplied value must be a string.

The compiler escapes backslashes, `%`, and `_` so they are treated as literal characters rather than LIKE wildcards.

#### Supported Filter Operators

| Operator | Required value | Description |
|---|---|---|
| `eq` | Scalar or null | Equal |
| `neq` | Scalar or null | Not equal |
| `gt` | Scalar | Greater than |
| `gte` | Scalar | Greater than or equal |
| `lt` | Scalar | Less than |
| `lte` | Scalar | Less than or equal |
| `in` | Array of non-null scalars | Matches any value in a list |
| `notIn` | Array of non-null scalars | Does not match any value in a list |
| `contains` | String | Contains a substring |
| `startsWith` | String | Starts with a substring |
| `endsWith` | String | Ends with a substring |
| `isNull` | Not required | Is NULL |
| `isNotNull` | Not required | Is not NULL |

#### Filter Validation

The compiler validates:

- Filter group structure and logical operators.
- Condition structure and required values.
- Column names against the repository's available columns.
- Operator support and value types.
- Empty filter groups.

Invalid filter structures or values throw `RepositoryValidationException`.

An empty filter array means no filtering is applied.

### Sorting

Sorting supports multiple columns:

```php
$result = $repository->getAll([
    'sorting' => [
        ['field' => 'title', 'direction' => 'asc'],
        ['field' => 'id', 'direction' => 'desc'],
    ],
]);
```

Supported directions are `asc` and `desc`.

Sorting columns must be included in the repository's available columns.

### Pagination

```php
$result = $repository->getAll([
    'pagination' => [
        'page' => 1,
        'perPage' => 20,
    ],
    'withCount' => true,
]);
```

Pagination rules:

- `page` must be an integer greater than or equal to 1.
- `perPage` must be a non-negative integer.
- The default maximum page size is 100.
- `perPage = 0` disables pagination and returns all matching records.
- When pagination is disabled, the page number does not affect the result.

### Total Count

When `withCount` is enabled, the repository returns the total number of matching records:

```php
$result = $repository->getAll([
    'filter' => $filter,
    'withCount' => true,
]);

$records = $result['data'];
$total = $result['count'];
```

When disabled, `count` is `null`.

### Row Numbers

```php
$result = $repository->getAll([
    'pagination' => [
        'page' => 2,
        'perPage' => 20,
    ],
    'withRowNumber' => true,
]);
```

When enabled, each returned record receives a sequential `rowNumber` field.

The first row of the second page in this example receives row number 21.

The repository reserves this field name for generated row numbers. If the selected result already contains `rowNumber`, a `RepositoryValidationException` is thrown.

## Repository API Reference

`BaseRepository` provides the following public methods.

### Constructor

```php
public function __construct(PDO $pdo)
```

Receives an existing PDO connection.

### getAll()

```php
public function getAll(array $options = []): array
```

Retrieves records using the supplied query options.

Supported options:

| Option | Type | Default |
|---|---|---|
| `select` | array | Repository `$select` |
| `filter` | array | `[]` |
| `sorting` | array | `[]` |
| `pagination` | array | `['page' => 1, 'perPage' => 10]` |
| `withCount` | bool | `false` |
| `withRowNumber` | bool | `false` |

Returns:

```php
[
    'data' => [],
    'count' => null,
]
```

### find()

```php
public function find(
    array $primaryKeyValues,
    ?array $select = null
): ?array
```

Finds a single record using all primary key values.

Example:

```php
$record = $repository->find([
    'id' => 10,
]);
```

For composite primary keys:

```php
$record = $repository->find([
    'companyId' => 5,
    'numberId' => 10,
]);
```

Returns an associative array or `null` if no record is found.

### count()

```php
public function count(?array $filter = null): int
```

Returns the number of records matching the supplied filter.

Sorting and pagination are not applied.

### insert()

```php
public function insert(array $data): array
```

Inserts a new record into the configured write table.

Returns an associative array containing all primary key values.

For a single auto-increment primary key, the key can be omitted or supplied as `null`.

For composite primary keys, all key values must be provided.

Example:

```php
$primaryKeys = $repository->insert([
    'hsbNo' => '1001',
    'hsbType' => 'htBank',
    'title' => 'Main Account',
]);
```

### update()

```php
public function update(
    array $primaryKeyValues,
    array $data
): int
```

Updates a record identified by all primary key values.

Primary key columns cannot be modified through this method.

Returns the number of affected rows.

Example:

```php
$affectedRows = $repository->update(
    ['id' => 10],
    [
        'title' => 'Updated Account',
    ]
);
```

### delete()

```php
public function delete(array $primaryKeyValues): int
```

Deletes a record identified by all primary key values.

Returns the number of affected rows.

Example:

```php
$affectedRows = $repository->delete([
    'id' => 10,
]);
```

## Writing Data

Write operations use the configured write table and primary key.

The read data source and write table are independent.

For example, a repository can read from a view while writing to its underlying table:

```php
protected function getDataSource(): DataSourceInterface
{
    return new ViewSource('vwHsbNumbers');
}

protected function getWriteTable(): ?string
{
    return 'HsbNumbers';
}
```

If the default write behavior does not fit a particular repository, override the relevant write method.

## Exceptions

The package uses a common exception hierarchy:

```text
RepositoryException
├── DataSourceException
├── RepositoryValidationException
└── QueryException
```

- `RepositoryException`: Base exception for repository-related errors.
- `DataSourceException`: Invalid or unsupported data source configuration.
- `RepositoryValidationException`: Invalid repository arguments or query options.
- `QueryException`: Database query preparation or execution errors.

PDO exceptions are wrapped in `QueryException`, preserving the original exception as the previous exception.

## Transactions

Transactions belong to the application service layer, not the repository.

Repositories can share the same PDO connection, allowing multiple operations to participate in one transaction.

Example:

```php
try {
    $pdo->beginTransaction();

    $repositoryA->insert($dataA);
    $repositoryB->update($primaryKey, $dataB);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $exception;
}
```

This keeps transaction boundaries and business workflows under application control.

## Security Considerations

- Always use prepared statements for values.
- Never interpolate user-provided values into SQL.
- Table, view, and column names are SQL identifiers and cannot be bound as parameters.
- Data source names and available columns must come from trusted application configuration.
- Custom SQL queries must be authored and controlled by the application.

## Version Compatibility

The initial release targets PHP 7.4 and later, with MySQL and MariaDB as supported database engines.

## License

This project is licensed under the [MIT License](LICENSE).
