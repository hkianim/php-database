<?php

declare(strict_types=1);

namespace Hkianim\Database\Query;

use Hkianim\Database\Exceptions\RepositoryValidationException;

class FilterCompiler
{
    /**
     * @var array
     */
    private $availableColumns;

    public function __construct(array $availableColumns)
    {
        $this->availableColumns = $availableColumns;

        foreach ($availableColumns as $column) {
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
     * Compiles a filter structure into SQL and positional bindings.
     *
     * @return array
     */
    public function compile(array $filter): array
    {
        if ($filter === []) {
            return [
                'sql' => '',
                'bindings' => [],
            ];
        }

        $result = $this->compileGroup($filter);

        return [
            'sql' => $result['sql'],
            'bindings' => $result['bindings'],
        ];
    }

    /**
     * Compiles a filter group.
     *
     * @return array
     */
    private function compileGroup(array $group): array
    {
        if (
            !isset($group['operator']) ||
            !isset($group['conditions']) ||
            !is_string($group['operator']) ||
            !is_array($group['conditions'])
        ) {
            throw new RepositoryValidationException(
                'Invalid filter group structure.'
            );
        }

        $operator = strtolower($group['operator']);

        if (!in_array($operator, ['and', 'or'], true)) {
            throw new RepositoryValidationException(
                'Invalid filter group operator.'
            );
        }

        if ($group['conditions'] === []) {
            throw new RepositoryValidationException(
                'Filter group cannot be empty.'
            );
        }

        $parts = [];
        $bindings = [];

        foreach ($group['conditions'] as $condition) {
            if (!is_array($condition)) {
                throw new RepositoryValidationException(
                    'Invalid filter condition.'
                );
            }

            if (
                isset($condition['conditions']) ||
                (
                    isset($condition['operator']) &&
                    !isset($condition['field'])
                )
            ) {
                $compiled = $this->compileGroup($condition);
            } else {
                $compiled = $this->compileCondition($condition);
            }

            $parts[] = $compiled['sql'];
            $bindings = array_merge(
                $bindings,
                $compiled['bindings']
            );
        }

        return [
            'sql' => '(' . implode(
                $operator === 'and' ? ' AND ' : ' OR ',
                $parts
            ) . ')',
            'bindings' => $bindings,
        ];
    }

    /**
     * Compiles a single filter condition.
     *
     * @return array
     */
    private function compileCondition(array $condition): array
    {
        if (
            !isset($condition['field']) ||
            !isset($condition['operator']) ||
            !is_string($condition['field']) ||
            !is_string($condition['operator'])
        ) {
            throw new RepositoryValidationException(
                'Invalid filter condition structure.'
            );
        }

        $field = $condition['field'];
        $operator = $condition['operator'];

        if (!in_array($field, $this->availableColumns, true)) {
            throw new RepositoryValidationException(
                'Filtering by this column is not allowed: ' . $field
            );
        }

        if (
            !in_array($operator, ['isNull', 'isNotNull'], true) &&
            !array_key_exists('value', $condition)
        ) {
            throw new RepositoryValidationException(
                'Filter condition value is required.'
            );
        }

        $column = '`' . $field . '`';

        switch ($operator) {
            case 'eq':
                return $this->compileComparison(
                    $column,
                    '=',
                    $condition['value']
                );

            case 'neq':
                return $this->compileComparison(
                    $column,
                    '<>',
                    $condition['value']
                );

            case 'gt':
                return $this->compileComparison(
                    $column,
                    '>',
                    $condition['value']
                );

            case 'gte':
                return $this->compileComparison(
                    $column,
                    '>=',
                    $condition['value']
                );

            case 'lt':
                return $this->compileComparison(
                    $column,
                    '<',
                    $condition['value']
                );

            case 'lte':
                return $this->compileComparison(
                    $column,
                    '<=',
                    $condition['value']
                );

            case 'in':
            case 'notIn':
                return $this->compileIn(
                    $column,
                    $operator,
                    $condition['value']
                );

            case 'contains':
            case 'startsWith':
            case 'endsWith':
                return $this->compileLike(
                    $column,
                    $operator,
                    $condition['value']
                );

            case 'isNull':
                return [
                    'sql' => $column . ' IS NULL',
                    'bindings' => [],
                ];

            case 'isNotNull':
                return [
                    'sql' => $column . ' IS NOT NULL',
                    'bindings' => [],
                ];

            default:
                throw new RepositoryValidationException(
                    'Unsupported filter operator: ' . $operator
                );
        }
    }

    /**
     * Compiles a comparison condition.
     *
     * @return array
     */
    private function compileComparison(
        string $column,
        string $operator,
        $value
    ): array {
        if ($value === null) {
            if ($operator === '=') {
                return [
                    'sql' => $column . ' IS NULL',
                    'bindings' => [],
                ];
            }

            if ($operator === '<>') {
                return [
                    'sql' => $column . ' IS NOT NULL',
                    'bindings' => [],
                ];
            }

            throw new RepositoryValidationException(
                'NULL is not allowed with this comparison operator.'
            );
        }

        if (!is_scalar($value)) {
            throw new RepositoryValidationException(
                'Filter value must be scalar.'
            );
        }

        return [
            'sql' => $column . ' ' . $operator . ' ?',
            'bindings' => [$value],
        ];
    }

    /**
     * Compiles an IN or NOT IN condition.
     *
     * @return array
     */
    private function compileIn(
        string $column,
        string $operator,
        $values
    ): array {
        if (!is_array($values)) {
            throw new RepositoryValidationException(
                'IN filter value must be an array.'
            );
        }

        if ($values === []) {
            return [
                'sql' => $operator === 'in' ? '1 = 0' : '1 = 1',
                'bindings' => [],
            ];
        }

        foreach ($values as $value) {
            if (!is_scalar($value) || $value === null) {
                throw new RepositoryValidationException(
                    'IN filter values must be non-null scalars.'
                );
            }
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($values), '?')
        );

        return [
            'sql' => $column .
                ($operator === 'in' ? ' IN (' : ' NOT IN (') .
                $placeholders . ')',
            'bindings' => array_values($values),
        ];
    }

    /**
     * Compiles a LIKE condition.
     *
     * @return array
     */
    private function compileLike(
        string $column,
        string $operator,
        $value
    ): array {
        if (!is_string($value)) {
            throw new RepositoryValidationException(
                'LIKE filter value must be a string.'
            );
        }

        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );

        switch ($operator) {
            case 'contains':
                $escaped = '%' . $escaped . '%';
                break;

            case 'startsWith':
                $escaped .= '%';
                break;

            case 'endsWith':
                $escaped = '%' . $escaped;
                break;
        }

        return [
            'sql' => $column . " LIKE ? ESCAPE '\\\\'",
            'bindings' => [$escaped],
        ];
    }
}
