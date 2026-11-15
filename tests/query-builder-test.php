<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Hkianim\Database\DataSource\TableSource;
use Hkianim\Database\DataSource\QuerySource;
use Hkianim\Database\Query\QueryBuilder;
use Hkianim\Database\Exceptions\RepositoryValidationException;

$passed = 0;
$failed = 0;

function test(string $name, callable $callback): void
{
    global $passed, $failed;

    try {
        $callback();
        $passed++;

        echo "[PASS] {$name}" . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;

        echo "[FAIL] {$name}" . PHP_EOL;
        echo "       " . $exception->getMessage() . PHP_EOL;
    }
}

function assertSameValue($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected: ' . var_export($expected, true) .
            PHP_EOL .
            'Actual: ' . var_export($actual, true)
        );
    }
}

function assertThrows(
    string $exceptionClass,
    callable $callback
): void {
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException(
            'Expected exception ' . $exceptionClass .
            ', received ' . get_class($exception)
        );
    }

    throw new RuntimeException(
        'Expected exception was not thrown: ' . $exceptionClass
    );
}

$columns = [
    'id',
    'name',
    'status',
    'createdAt',
];

$source = new TableSource('Users');

test('Default SELECT query', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns, ['id']);

    $result = $builder->build();

    assertSameValue(
        'SELECT * FROM `Users` AS `source` ORDER BY `id` ASC',
        $result['data']['sql']
    );

    assertSameValue([], $result['data']['bindings']);
});

test('Selecting specific columns', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns);

    $result = $builder
        ->select(['id', 'name'])
        ->build();

    assertSameValue(
        'SELECT `id`, `name` FROM `Users` AS `source`',
        $result['data']['sql']
    );
});

test('Empty select means all columns', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns);

    $result = $builder
        ->select([])
        ->build();

    assertSameValue(
        'SELECT * FROM `Users` AS `source`',
        $result['data']['sql']
    );
});

test('Custom sorting', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns, ['id']);

    $result = $builder
        ->orderBy([
            ['field' => 'name', 'direction' => 'desc'],
            ['field' => 'createdAt', 'direction' => 'asc'],
        ])
        ->build();

    assertSameValue(
        'SELECT * FROM `Users` AS `source` ' .
        'ORDER BY `name` DESC, `createdAt` ASC',
        $result['data']['sql']
    );
});

test('Custom sorting replaces default primary key sorting', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns, ['id']);

    $result = $builder
        ->orderBy([
            ['field' => 'name', 'direction' => 'desc'],
        ])
        ->build();

    assertSameValue(
        'SELECT * FROM `Users` AS `source` ORDER BY `name` DESC',
        $result['data']['sql']
    );
});

test('Empty custom sorting restores default sorting', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns, ['id']);

    $result = $builder
        ->orderBy([
            ['field' => 'name', 'direction' => 'desc'],
        ])
        ->orderBy([])
        ->build();

    assertSameValue(
        'SELECT * FROM `Users` AS `source` ORDER BY `id` ASC',
        $result['data']['sql']
    );
});

test('Filter query and bindings', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns);

    $result = $builder
        ->where([
            'operator' => 'and',
            'conditions' => [
                [
                    'field' => 'status',
                    'operator' => 'eq',
                    'value' => 'active',
                ],
                [
                    'field' => 'name',
                    'operator' => 'contains',
                    'value' => 'Ali',
                ],
            ],
        ])
        ->build();

    assertSameValue(
        "SELECT * FROM `Users` AS `source` " .
        "WHERE (`status` = ? AND `name` LIKE ? ESCAPE '\\\\')",
        $result['data']['sql']
    );

    assertSameValue(
        ['active', '%Ali%'],
        $result['data']['bindings']
    );
});

test('Nested filter groups', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns);

    $result = $builder
        ->where([
            'operator' => 'and',
            'conditions' => [
                [
                    'field' => 'status',
                    'operator' => 'eq',
                    'value' => 'active',
                ],
                [
                    'operator' => 'or',
                    'conditions' => [
                        [
                            'field' => 'name',
                            'operator' => 'eq',
                            'value' => 'Ali',
                        ],
                        [
                            'field' => 'name',
                            'operator' => 'eq',
                            'value' => 'Reza',
                        ],
                    ],
                ],
            ],
        ])
        ->build();

    assertSameValue(
        'SELECT * FROM `Users` AS `source` ' .
        'WHERE (`status` = ? AND (`name` = ? OR `name` = ?))',
        $result['data']['sql']
    );

    assertSameValue(
        ['active', 'Ali', 'Reza'],
        $result['data']['bindings']
    );
});

test('Pagination SQL and bindings', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns);

    $result = $builder
        ->paginate(3, 20)
        ->build();

    assertSameValue(
        'SELECT * FROM `Users` AS `source` LIMIT ? OFFSET ?',
        $result['data']['sql']
    );

    assertSameValue(
        [20, 40],
        $result['data']['bindings']
    );
});

test('Zero per page disables pagination', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns);

    $result = $builder
        ->paginate(5, 0)
        ->build();

    assertSameValue(
        'SELECT * FROM `Users` AS `source`',
        $result['data']['sql']
    );

    assertSameValue([], $result['data']['bindings']);
});

test('Count query excludes sorting and pagination', function () use ($source, $columns) {
    $builder = new QueryBuilder($source, $columns);

    $result = $builder
        ->where([
            'operator' => 'and',
            'conditions' => [
                [
                    'field' => 'status',
                    'operator' => 'eq',
                    'value' => 'active',
                ],
            ],
        ])
        ->orderBy([
            ['field' => 'name', 'direction' => 'desc'],
        ])
        ->paginate(2, 10)
        ->build();

    assertSameValue(
        'SELECT COUNT(*) FROM `Users` AS `source` ' .
        'WHERE (`status` = ?)',
        $result['count']['sql']
    );

    assertSameValue(
        ['active'],
        $result['count']['bindings']
    );
});

test('QuerySource bindings come before filter bindings', function () use ($columns) {
    $querySource = new QuerySource(
        'SELECT id, name, status FROM Users WHERE status = ?',
        ['active']
    );

    $builder = new QueryBuilder($querySource, $columns);

    $result = $builder
        ->where([
            'operator' => 'and',
            'conditions' => [
                [
                    'field' => 'name',
                    'operator' => 'eq',
                    'value' => 'Ali',
                ],
            ],
        ])
        ->paginate(2, 10)
        ->build();

    assertSameValue(
        [
            'active',
            'Ali',
            10,
            10,
        ],
        $result['data']['bindings']
    );

    assertSameValue(
        ['active', 'Ali'],
        $result['count']['bindings']
    );
});

test('Grouped QuerySource count wraps the source', function () use ($columns) {
    $querySource = new QuerySource(
        'SELECT status, COUNT(*) AS total ' .
        'FROM Users GROUP BY status HAVING COUNT(*) > ?',
        [2]
    );

    $builder = new QueryBuilder($querySource, $columns);

    $result = $builder->build();

    assertSameValue(
        'SELECT COUNT(*) FROM (' .
        'SELECT status, COUNT(*) AS total ' .
        'FROM Users GROUP BY status HAVING COUNT(*) > ?' .
        ') AS `source`',
        $result['count']['sql']
    );

    assertSameValue([2], $result['count']['bindings']);
});

test('Invalid selected column throws exception', function () use ($source, $columns) {
    assertThrows(
        RepositoryValidationException::class,
        function () use ($source, $columns) {
            (new QueryBuilder($source, $columns))
                ->select(['unknownColumn']);
        }
    );
});

test('Invalid sorting direction throws exception', function () use ($source, $columns) {
    assertThrows(
        RepositoryValidationException::class,
        function () use ($source, $columns) {
            (new QueryBuilder($source, $columns))
                ->orderBy([
                    ['field' => 'name', 'direction' => 'sideways'],
                ]);
        }
    );
});

test('Duplicate sorting column throws exception', function () use ($source, $columns) {
    assertThrows(
        RepositoryValidationException::class,
        function () use ($source, $columns) {
            (new QueryBuilder($source, $columns))
                ->orderBy([
                    ['field' => 'name', 'direction' => 'asc'],
                    ['field' => 'name', 'direction' => 'desc'],
                ]);
        }
    );
});

test('Invalid pagination throws exception', function () use ($source, $columns) {
    assertThrows(
        RepositoryValidationException::class,
        function () use ($source, $columns) {
            (new QueryBuilder($source, $columns))
                ->paginate(0, 10);
        }
    );

    assertThrows(
        RepositoryValidationException::class,
        function () use ($source, $columns) {
            (new QueryBuilder($source, $columns))
                ->paginate(1, -1);
        }
    );
});

echo PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}

echo "All tests passed." . PHP_EOL;
