<?php

declare(strict_types=1);

namespace Hkianim\Database\Repository;

use PDO;
use Hkianim\Database\Contracts\DataSourceInterface;
use Hkianim\Database\Exceptions\RepositoryException;
use Hkianim\Database\Exceptions\RepositoryValidationException;
use Hkianim\Database\Query\QueryBuilder;
use Hkianim\Database\Query\QueryExecutor;

class BaseRepository
{
    /**
     * Shared PDO connection.
     *
     * @var PDO
     */
    protected $pdo;

    /**
     * Default selected columns.
     *
     * An empty array means all columns.
     *
     * @var array
     */
    protected $select = [];

    /**
     * Maximum allowed page size.
     *
     * @var int
     */
    protected $maxPerPage = 100;

    /**
     * Cached data source.
     *
     * @var DataSourceInterface|null
     */
    private $resolvedDataSource;

    /**
     * Query executor.
     *
     * @var QueryExecutor
     */
    private $executor;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->executor = new QueryExecutor($pdo);
    }

    /**
     * Returns the data source used for reading.
     *
     * Must be overridden by concrete repositories.
     */
    protected function getDataSource(): DataSourceInterface
    {
        throw new RepositoryException(
            'Repository data source has not been configured.'
        );
    }

    /**
     * Returns the primary key columns.
     *
     * @return array
     */
    protected function getPrimaryKey(): array
    {
        return ['id'];
    }

    /**
     * Returns the allowed columns for selection,
     * filtering and sorting.
     *
     * @return array
     */
    protected function getAvailableColumns(): array
    {
        return [];
    }

    /**
     * Returns the table used for write operations.
     *
     * Reading and writing sources are independent.
     */
    protected function getWriteTable(): ?string
    {
        return null;
    }

    /**
     * Returns the cached data source.
     */
    private function resolveDataSource(): DataSourceInterface
    {
        if ($this->resolvedDataSource === null) {
            $dataSource = $this->getDataSource();

            if (!$dataSource instanceof DataSourceInterface) {
                throw new RepositoryException(
                    'Invalid repository data source.'
                );
            }

            $this->resolvedDataSource = $dataSource;
        }

        return $this->resolvedDataSource;
    }

    /**
     * Creates a query builder for this repository.
     */
    private function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder(
            $this->resolveDataSource(),
            $this->getAvailableColumns(),
            $this->getValidatedPrimaryKeys()
        );
    }

    /**
     * Returns records according to the supplied options.
     *
     * Supported options:
     * - select
     * - filter
     * - sorting
     * - pagination
     * - withCount
     * - withRowNumber
     *
     * @return array
     */
    public function getAll(array $options = []): array
    {
        $allowedOptions = [
            'select',
            'filter',
            'sorting',
            'pagination',
            'withCount',
            'withRowNumber',
        ];

        foreach (array_keys($options) as $option) {
            if (!in_array($option, $allowedOptions, true)) {
                throw new RepositoryValidationException(
                    'Unsupported repository option: ' . $option
                );
            }
        }

        $select = array_key_exists('select', $options)
            ? $options['select']
            : $this->select;

        $filter = $options['filter'] ?? [];
        $sorting = $options['sorting'] ?? [];

        $pagination = $options['pagination'] ?? [
            'page' => 1,
            'perPage' => 10,
        ];

        $withCount = $options['withCount'] ?? false;
        $withRowNumber = $options['withRowNumber'] ?? false;

        if (!is_array($select)) {
            throw new RepositoryValidationException(
                'Select option must be an array.'
            );
        }

        if (!is_array($filter)) {
            throw new RepositoryValidationException(
                'Filter option must be an array.'
            );
        }

        if (!is_array($sorting)) {
            throw new RepositoryValidationException(
                'Sorting option must be an array.'
            );
        }

        if (!is_array($pagination)) {
            throw new RepositoryValidationException(
                'Pagination option must be an array.'
            );
        }

        if (!is_bool($withCount) || !is_bool($withRowNumber)) {
            throw new RepositoryValidationException(
                'Count and row number options must be boolean.'
            );
        }

        foreach (array_keys($pagination) as $key) {
            if (!in_array($key, ['page', 'perPage'], true)) {
                throw new RepositoryValidationException(
                    'Unsupported pagination option: ' . $key
                );
            }
        }

        $page = $pagination['page'] ?? 1;
        $perPage = $pagination['perPage'] ?? 10;

        if (!is_int($page) || $page < 1) {
            throw new RepositoryValidationException(
                'Page must be an integer greater than or equal to 1.'
            );
        }

        if (!is_int($perPage) || $perPage < 0) {
            throw new RepositoryValidationException(
                'Per page must be a non-negative integer.'
            );
        }

        if ($perPage > $this->maxPerPage) {
            throw new RepositoryValidationException(
                'Per page exceeds the maximum allowed value.'
            );
        }

        if ($withRowNumber) {
            $availableColumns = $this->getAvailableColumns();

            $selectsAllColumns = $select === [];

            if (
                in_array('rowNumber', $select, true) ||
                (
                    $selectsAllColumns &&
                    in_array('rowNumber', $availableColumns, true)
                )
            ) {
                throw new RepositoryValidationException(
                    'Cannot add row numbers because the selected data already contains a rowNumber column.'
                );
            }
        }

        $queryBuilder = $this->createQueryBuilder();

        $queryBuilder->select($select);

        if ($filter !== []) {
            $queryBuilder->where($filter);
        }

        if ($sorting !== []) {
            $queryBuilder->orderBy($sorting);
        }

        $queryBuilder->paginate($page, $perPage);

        $queries = $queryBuilder->build();

        $rows = $this->executor->fetchAll(
            $queries['data']['sql'],
            $queries['data']['bindings']
        );

        if ($withRowNumber) {
            $offset = $perPage === 0
                ? 0
                : ($page - 1) * $perPage;

            foreach ($rows as $index => &$row) {
                if (array_key_exists('rowNumber', $row)) {
                    throw new RepositoryValidationException(
                        'Cannot add row numbers because the result already contains a rowNumber column.'
                    );
                }

                $row['rowNumber'] = $offset + $index + 1;
            }

            unset($row);
        }

        $count = null;

        if ($withCount) {
            $count = (int) $this->executor->fetchColumn(
                $queries['count']['sql'],
                $queries['count']['bindings']
            );
        }

        return [
            'data' => $rows,
            'count' => $count,
        ];
    }

    /**
     * Finds exactly one record using all primary key columns.
     *
     * @return array|null
     */
    public function find(
        array $primaryKeyValues,
        ?array $select = null
    ): ?array {
        $primaryKeys = $this->getValidatedPrimaryKeys();

        $this->validatePrimaryKeyValues(
            $primaryKeyValues,
            $primaryKeys
        );

        $conditions = [];

        foreach ($primaryKeys as $primaryKey) {
            $conditions[] = [
                'field' => $primaryKey,
                'operator' => 'eq',
                'value' => $primaryKeyValues[$primaryKey],
            ];
        }

        $filter = [
            'operator' => 'and',
            'conditions' => $conditions,
        ];

        $queryBuilder = $this->createQueryBuilder();

        $queryBuilder->select(
            $select ?? $this->select
        );

        $queryBuilder->where($filter);

        $queries = $queryBuilder->build();

        $rows = $this->executor->fetchAll(
            $queries['data']['sql'],
            $queries['data']['bindings']
        );

        if (count($rows) > 1) {
            throw new RepositoryException(
                'Primary key lookup returned multiple records.'
            );
        }

        return $rows[0] ?? null;
    }

    /**
     * Counts records matching the supplied filter.
     */
    public function count(?array $filter = null): int
    {
        $filter = $filter ?? [];

        $queryBuilder = $this->createQueryBuilder();

        if ($filter !== []) {
            $queryBuilder->where($filter);
        }

        $queries = $queryBuilder->build();

        return (int) $this->executor->fetchColumn(
            $queries['count']['sql'],
            $queries['count']['bindings']
        );
    }

    /**
     * Inserts a new record.
     *
     * Returns all primary key values.
     *
     * @return array
     */
    public function insert(array $data): array
    {
        $table = $this->getValidatedWriteTable();
        $primaryKeys = $this->getValidatedPrimaryKeys();

        $this->validateWriteData($data);

        $primaryKeyValues = [];
        $autoIncrementPrimaryKey = null;

        if (count($primaryKeys) === 1) {
            $primaryKey = $primaryKeys[0];

            if (
                array_key_exists($primaryKey, $data) &&
                $data[$primaryKey] === ''
            ) {
                throw new RepositoryValidationException(
                    'Primary key value cannot be an empty string.'
                );
            }

            if (
                !array_key_exists($primaryKey, $data) ||
                $data[$primaryKey] === null
            ) {
                $autoIncrementPrimaryKey = $primaryKey;
                unset($data[$primaryKey]);
            } else {
                $primaryKeyValues[$primaryKey] = $data[$primaryKey];
            }
        } else {
            foreach ($primaryKeys as $primaryKey) {
                if (
                    !array_key_exists($primaryKey, $data) ||
                    !is_scalar($data[$primaryKey]) ||
                    $data[$primaryKey] === ''
                ) {
                    throw new RepositoryValidationException(
                        'All composite primary key values must be provided.'
                    );
                }

                $primaryKeyValues[$primaryKey] = $data[$primaryKey];
            }
        }

        if ($data === []) {
            throw new RepositoryValidationException(
                'Insert data cannot be empty.'
            );
        }

        $columns = array_keys($data);

        $quotedColumns = array_map(
            function ($column) {
                return '`' . $column . '`';
            },
            $columns
        );

        $placeholders = implode(
            ', ',
            array_fill(0, count($columns), '?')
        );

        $sql = 'INSERT INTO `' . $table . '` (' .
            implode(', ', $quotedColumns) .
            ') VALUES (' . $placeholders . ')';

        $this->executor->execute(
            $sql,
            array_values($data)
        );

        if ($autoIncrementPrimaryKey !== null) {
            $lastInsertId = $this->pdo->lastInsertId();

            if ($lastInsertId === '') {
                throw new RepositoryException(
                    'Could not retrieve the generated primary key.'
                );
            }

            $primaryKeyValues[$autoIncrementPrimaryKey] = $lastInsertId;
        }

        $result = [];

        foreach ($primaryKeys as $primaryKey) {
            if (!array_key_exists($primaryKey, $primaryKeyValues)) {
                throw new RepositoryException(
                    'Primary key value could not be determined: ' .
                    $primaryKey
                );
            }

            $result[$primaryKey] = $primaryKeyValues[$primaryKey];
        }

        return $result;
    }

    /**
     * Updates a record using all primary key values.
     *
     * Primary key columns cannot be changed.
     */
    public function update(
        array $primaryKeyValues,
        array $data
    ): int {
        $table = $this->getValidatedWriteTable();
        $primaryKeys = $this->getValidatedPrimaryKeys();

        $this->validatePrimaryKeyValues(
            $primaryKeyValues,
            $primaryKeys
        );

        $this->validateWriteData(
            $data,
            $primaryKeys
        );

        if ($data === []) {
            throw new RepositoryValidationException(
                'Update data cannot be empty.'
            );
        }

        $setParts = [];

        foreach (array_keys($data) as $column) {
            $setParts[] = '`' . $column . '` = ?';
        }

        $whereParts = [];

        foreach ($primaryKeys as $primaryKey) {
            $whereParts[] = '`' . $primaryKey . '` = ?';
        }

        $sql = 'UPDATE `' . $table . '` SET ' .
            implode(', ', $setParts) .
            ' WHERE ' . implode(' AND ', $whereParts);

        $bindings = array_values($data);

        foreach ($primaryKeys as $primaryKey) {
            $bindings[] = $primaryKeyValues[$primaryKey];
        }

        return $this->executor->execute($sql, $bindings);
    }

    /**
     * Deletes a record using all primary key values.
     */
    public function delete(array $primaryKeyValues): int
    {
        $table = $this->getValidatedWriteTable();
        $primaryKeys = $this->getValidatedPrimaryKeys();

        $this->validatePrimaryKeyValues(
            $primaryKeyValues,
            $primaryKeys
        );

        $whereParts = [];

        foreach ($primaryKeys as $primaryKey) {
            $whereParts[] = '`' . $primaryKey . '` = ?';
        }

        $sql = 'DELETE FROM `' . $table . '` WHERE ' .
            implode(' AND ', $whereParts);

        $bindings = [];

        foreach ($primaryKeys as $primaryKey) {
            $bindings[] = $primaryKeyValues[$primaryKey];
        }

        return $this->executor->execute($sql, $bindings);
    }

    /**
     * Returns and validates the write table name.
     */
    private function getValidatedWriteTable(): string
    {
        $table = $this->getWriteTable();

        if (
            !is_string($table) ||
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)
        ) {
            throw new RepositoryException(
                'Repository write table is not configured correctly.'
            );
        }

        return $table;
    }

    /**
     * Validates insert/update data.
     *
     * Reserved columns cannot be included in the data.
     */
    private function validateWriteData(
        array $data,
        array $reservedColumns = []
    ): void {
        foreach ($data as $column => $value) {
            if (
                !is_string($column) ||
                !preg_match(
                    '/^[a-zA-Z_][a-zA-Z0-9_]*$/',
                    $column
                )
            ) {
                throw new RepositoryValidationException(
                    'Invalid write column name.'
                );
            }

            if (in_array($column, $reservedColumns, true)) {
                throw new RepositoryValidationException(
                    'This column cannot be modified: ' . $column
                );
            }

            if (!is_scalar($value) && $value !== null) {
                throw new RepositoryValidationException(
                    'Write values must be scalar or null.'
                );
            }
        }
    }

    /**
     * Returns and validates primary key columns.
     *
     * Every primary key column must be included in
     * the repository's available columns.
     */
    private function getValidatedPrimaryKeys(): array
    {
        $primaryKeys = $this->getPrimaryKey();

        if ($primaryKeys === []) {
            throw new RepositoryException(
                'Repository primary key is not configured.'
            );
        }

        foreach ($primaryKeys as $primaryKey) {
            if (
                !is_string($primaryKey) ||
                !preg_match(
                    '/^[a-zA-Z_][a-zA-Z0-9_]*$/',
                    $primaryKey
                )
            ) {
                throw new RepositoryException(
                    'Invalid repository primary key configuration.'
                );
            }
        }

        if (count(array_unique($primaryKeys)) !== count($primaryKeys)) {
            throw new RepositoryException(
                'Duplicate primary key columns are not allowed.'
            );
        }

        $primaryKeys = array_values($primaryKeys);
        $availableColumns = $this->getAvailableColumns();

        if (!is_array($availableColumns)) {
            throw new RepositoryException(
                'Repository available columns configuration is invalid.'
            );
        }

        foreach ($primaryKeys as $primaryKey) {
            if (!in_array($primaryKey, $availableColumns, true)) {
                throw new RepositoryException(
                    'Primary key column is missing from available columns: ' .
                    $primaryKey
                );
            }
        }

        return $primaryKeys;
    }

    /**
     * Validates associative primary key values.
     */
    private function validatePrimaryKeyValues(
        array $values,
        array $primaryKeys
    ): void {
        if (count($values) !== count($primaryKeys)) {
            throw new RepositoryValidationException(
                'All primary key values must be provided.'
            );
        }

        foreach ($primaryKeys as $primaryKey) {
            if (!array_key_exists($primaryKey, $values)) {
                throw new RepositoryValidationException(
                    'Missing primary key value: ' . $primaryKey
                );
            }

            $value = $values[$primaryKey];

            if (!is_scalar($value) || $value === '') {
                throw new RepositoryValidationException(
                    'Invalid primary key value: ' . $primaryKey
                );
            }
        }
    }
}
