<?php

declare(strict_types=1);

namespace Hkianim\Database\Query;

use PDO;
use PDOException;
use PDOStatement;
use Hkianim\Database\Exceptions\QueryException;
use Hkianim\Database\Exceptions\RepositoryValidationException;

class QueryExecutor
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        return $this->run(function () use ($sql, $bindings) {
            $statement = $this->prepareAndExecute($sql, $bindings);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        return $this->run(function () use ($sql, $bindings) {
            $statement = $this->prepareAndExecute($sql, $bindings);

            $row = $statement->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        });
    }

    public function fetchColumn(
        string $sql,
        array $bindings = [],
        int $column = 0
    ) {
        if ($column < 0) {
            throw new RepositoryValidationException(
                'Column index cannot be negative.'
            );
        }

        return $this->run(function () use ($sql, $bindings, $column) {
            $statement = $this->prepareAndExecute($sql, $bindings);

            $row = $statement->fetch(PDO::FETCH_NUM);

            if ($row === false) {
                throw new QueryException(
                    'The query did not return any rows.'
                );
            }

            if (!array_key_exists($column, $row)) {
                throw new QueryException(
                    'The requested query column does not exist.'
                );
            }

            return $row[$column];
        });
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run(function () use ($sql, $bindings) {
            $statement = $this->prepareAndExecute($sql, $bindings);

            return $statement->rowCount();
        });
    }

    private function prepareAndExecute(
        string $sql,
        array $bindings
    ): PDOStatement {
        $this->validateBindings($bindings);

        $statement = $this->pdo->prepare($sql);

        if ($statement === false) {
            throw new QueryException(
                'Database query preparation failed.'
            );
        }

        foreach (array_values($bindings) as $index => $value) {
            $statement->bindValue(
                $index + 1,
                $value,
                $this->getParameterType($value)
            );
        }

        $statement->execute();

        return $statement;
    }

    private function run(callable $callback)
    {
        try {
            return $callback();
        } catch (PDOException $exception) {
            throw new QueryException(
                'Database query execution failed.',
                0,
                $exception
            );
        }
    }

    private function validateBindings(array $bindings): void
    {
        foreach ($bindings as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new RepositoryValidationException(
                    'Query binding values must be scalar or null.'
                );
            }
        }
    }

    private function getParameterType($value): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }

        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        // PDO does not provide a dedicated float parameter type.
        return PDO::PARAM_STR;
    }
}