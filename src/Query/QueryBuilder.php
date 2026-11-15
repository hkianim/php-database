<?php

declare(strict_types=1);

namespace Hkianim\Database\Query;

use Hkianim\Database\Contracts\DataSourceInterface;
use Hkianim\Database\Exceptions\RepositoryValidationException;

class QueryBuilder
{
    /**
     * @var DataSourceInterface
     */
    private $dataSource;

    /**
     * @var array
     */
    private $availableColumns;

    /**
     * @var array
     */
    private $primaryKeys;

    /**
     * @var array
     */
    private $selectedColumns = [];

    /**
     * @var bool
     */
    private $selectAll = true;

    /**
     * @var array
     */
    private $filter = [];

    /**
     * @var array
     */
    private $sorting = [];

    /**
     * @var bool
     */
    private $customSorting = false;

    /**
     * @var int|null
     */
    private $page;

    /**
     * @var int|null
     */
    private $perPage;

    public function __construct(
        DataSourceInterface $dataSource,
        array $availableColumns,
        array $primaryKeys = []
    ) {
        $this->dataSource = $dataSource;
        $this->availableColumns = $availableColumns;
        $this->primaryKeys = $primaryKeys;

        $this->validateColumns($availableColumns);
        $this->validatePrimaryKeys($primaryKeys);
    }

    /**
     * Sets the selected columns.
     */
    public function select(array $columns): self
    {
        if ($columns === []) {
            $this->selectedColumns = [];
            $this->selectAll = true;

            return $this;
        }

        $this->validateColumnList($columns);

        $this->selectedColumns = array_values(array_unique($columns));
        $this->selectAll = false;

        return $this;
    }

    /**
     * Sets the filter structure.
     */
    public function where(array $filter): self
    {
        $this->filter = $filter;

        return $this;
    }

    /**
     * Sets custom sorting.
     */
    public function orderBy(array $sorting): self
    {
        if ($sorting === []) {
            $this->sorting = [];
            $this->customSorting = false;

            return $this;
        }

        $normalized = [];
        $usedColumns = [];

        foreach ($sorting as $item) {
            if (
                !is_array($item) ||
                !isset($item['field']) ||
                !isset($item['direction']) ||
                !is_string($item['field']) ||
                !is_string($item['direction'])
            ) {
                throw new RepositoryValidationException(
                    'Invalid sorting structure.'
                );
            }

            $field = $item['field'];
            $direction = strtolower($item['direction']);

            $this->validateColumn($field);

            if (!in_array($direction, ['asc', 'desc'], true)) {
                throw new RepositoryValidationException(
                    'Invalid sorting direction: ' . $item['direction']
                );
            }

            if (in_array($field, $usedColumns, true)) {
                throw new RepositoryValidationException(
                    'Duplicate sorting column: ' . $field
                );
            }

            $usedColumns[] = $field;

            $normalized[] = [
                'field' => $field,
                'direction' => strtoupper($direction),
            ];
        }

        $this->sorting = $normalized;
        $this->customSorting = true;

        return $this;
    }

    /**
     * Sets pagination.
     */
    public function paginate(int $page, int $perPage): self
    {
        if ($page < 1) {
            throw new RepositoryValidationException(
                'Page must be greater than or equal to 1.'
            );
        }

        if ($perPage < 0) {
            throw new RepositoryValidationException(
                'Per-page value cannot be negative.'
            );
        }

        $this->page = $page;
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Builds data and count queries.
     *
     * @return array
     */
    public function build(): array
    {
        $compiledFilter = (new FilterCompiler(
            $this->availableColumns
        ))->compile($this->filter);

        $fromClause = $this->buildFromClause();

        $whereSql = $compiledFilter['sql'] !== ''
            ? ' WHERE ' . $compiledFilter['sql']
            : '';

        $dataSql = 'SELECT ' .
            $this->buildSelectClause() .
            ' FROM ' . $fromClause .
            $whereSql .
            $this->buildOrderByClause() .
            $this->buildPaginationClause();

        $countSql = 'SELECT COUNT(*) FROM ' .
            $fromClause .
            $whereSql;

        $sourceBindings = $this->dataSource->getBindings();
        $filterBindings = $compiledFilter['bindings'];

        $dataBindings = array_merge(
            $sourceBindings,
            $filterBindings,
            $this->buildPaginationBindings()
        );

        $countBindings = array_merge(
            $sourceBindings,
            $filterBindings
        );

        return [
            'data' => [
                'sql' => $dataSql,
                'bindings' => $dataBindings,
            ],
            'count' => [
                'sql' => $countSql,
                'bindings' => $countBindings,
            ],
        ];
    }

    /**
     * Builds the SELECT column list.
     */
    private function buildSelectClause(): string
    {
        if ($this->selectAll) {
            return '*';
        }

        $columns = [];

        foreach ($this->selectedColumns as $column) {
            $columns[] = '`' . $column . '`';
        }

        return implode(', ', $columns);
    }

    /**
     * Builds the FROM clause.
     */
    private function buildFromClause(): string
    {
        return $this->dataSource->getFromClause() .
            ' AS `source`';
    }

    /**
     * Builds the ORDER BY clause.
     */
    private function buildOrderByClause(): string
    {
        $sorting = $this->sorting;

        if (!$this->customSorting) {
            $sorting = [];

            foreach ($this->primaryKeys as $primaryKey) {
                $sorting[] = [
                    'field' => $primaryKey,
                    'direction' => 'ASC',
                ];
            }
        }

        if ($sorting === []) {
            return '';
        }

        $parts = [];

        foreach ($sorting as $item) {
            $parts[] = '`' . $item['field'] . '` ' .
                $item['direction'];
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * Builds the pagination SQL.
     */
    private function buildPaginationClause(): string
    {
        if ($this->perPage === null || $this->perPage === 0) {
            return '';
        }

        return ' LIMIT ? OFFSET ?';
    }

    /**
     * Returns pagination bindings.
     */
    private function buildPaginationBindings(): array
    {
        if ($this->perPage === null || $this->perPage === 0) {
            return [];
        }

        $offset = ($this->page - 1) * $this->perPage;

        return [
            $this->perPage,
            $offset,
        ];
    }

    /**
     * Validates available columns.
     */
    private function validateColumns(array $columns): void
    {
        foreach ($columns as $column) {
            if (
                !is_string($column) ||
                !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)
            ) {
                throw new RepositoryValidationException(
                    'Invalid available column.'
                );
            }
        }
    }

    /**
     * Validates primary keys.
     */
    private function validatePrimaryKeys(array $primaryKeys): void
    {
        $this->validateColumnList($primaryKeys);
    }

    /**
     * Validates a list of column names.
     */
    private function validateColumnList(array $columns): void
    {
        $usedColumns = [];

        foreach ($columns as $column) {
            $this->validateColumn($column);

            if (in_array($column, $usedColumns, true)) {
                throw new RepositoryValidationException(
                    'Duplicate column: ' . $column
                );
            }

            $usedColumns[] = $column;
        }
    }

    /**
     * Validates a single column name.
     */
    private function validateColumn($column): void
    {
        if (
            !is_string($column) ||
            !in_array($column, $this->availableColumns, true)
        ) {
            throw new RepositoryValidationException(
                'Column is not available: ' .
                (is_scalar($column) ? (string) $column : 'invalid')
            );
        }
    }
}
