<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Hkianim\Database\Contracts\DataSourceInterface;
use Hkianim\Database\DataSource\TableSource;
use Hkianim\Database\Exceptions\RepositoryException;
use Hkianim\Database\Exceptions\RepositoryValidationException;
use Hkianim\Database\Repository\BaseRepository;

$dsn = getenv('DB_TEST_DSN');
$username = getenv('DB_TEST_USERNAME');
$password = getenv('DB_TEST_PASSWORD');

if (!$dsn) {
    exit("DB_TEST_DSN is required.\n");
}

$username = $username === false ? '' : $username;
$password = $password === false ? '' : $password;

$passed = 0;
$failed = 0;

function test(string $name, callable $callback): void
{
    global $passed, $failed;

    try {
        $callback();
        $passed++;

        echo "[PASS] {$name}\n";
    } catch (Throwable $exception) {
        $failed++;

        echo "[FAIL] {$name}\n";
        echo "       " . $exception->getMessage() . "\n";
    }
}

function assertSameValue($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message !== ''
            ? $message
            : 'Expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertThrows(string $class, callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if (!$exception instanceof $class) {
            throw new RuntimeException(
                'Expected ' . $class .
                ', got ' . get_class($exception)
            );
        }

        return $exception;
    }

    throw new RuntimeException(
        'Expected exception ' . $class . ' was not thrown.'
    );
}

class TestHsbNumberRepository extends BaseRepository
{
    protected function getDataSource(): DataSourceInterface
    {
        return new TableSource('php_database_repository_test');
    }

    protected function getPrimaryKey(): array
    {
        return ['id'];
    }

    protected function getAvailableColumns(): array
    {
        return [
            'id',
            'hsbNo',
            'hsbName',
            'balance',
            'active',
        ];
    }

    protected function getWriteTable(): ?string
    {
        return 'php_database_repository_test';
    }
}

class TestCompositeRepository extends BaseRepository
{
    protected function getDataSource(): DataSourceInterface
    {
        return new TableSource('php_database_composite_test');
    }

    protected function getPrimaryKey(): array
    {
        return ['companyId', 'personId'];
    }

    protected function getAvailableColumns(): array
    {
        return [
            'companyId',
            'personId',
            'personName',
        ];
    }

    protected function getWriteTable(): ?string
    {
        return 'php_database_composite_test';
    }
}

try {
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    $pdo->exec(
        "CREATE TEMPORARY TABLE php_database_repository_test (
            id INT NOT NULL AUTO_INCREMENT,
            hsbNo VARCHAR(30) NOT NULL,
            hsbName VARCHAR(100) NOT NULL,
            balance DECIMAL(12, 2) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "INSERT INTO php_database_repository_test
            (hsbNo, hsbName, balance, active)
        VALUES
            ('1001', 'First', 125.50, 1),
            ('1002', 'Second', 250.00, 1),
            ('1003', 'Third', NULL, 0)"
    );

    $pdo->exec(
        "CREATE TEMPORARY TABLE php_database_composite_test (
            companyId INT NOT NULL,
            personId INT NOT NULL,
            personName VARCHAR(100) NOT NULL,
            PRIMARY KEY (companyId, personId)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "INSERT INTO php_database_composite_test
            (companyId, personId, personName)
        VALUES
            (1, 10, 'Person A'),
            (1, 20, 'Person B'),
            (2, 10, 'Person C')"
    );

    $repository = new TestHsbNumberRepository($pdo);
    $compositeRepository = new TestCompositeRepository($pdo);

    // getAll

    test('getAll returns records', function () use ($repository) {
        $result = $repository->getAll();

        assertSameValue(3, count($result['data']));
        assertSameValue(null, $result['count']);
    });

    test('getAll supports selected columns', function () use ($repository) {
        $result = $repository->getAll([
            'select' => ['id', 'hsbNo'],
        ]);

        assertSameValue(
            ['id', 'hsbNo'],
            array_keys($result['data'][0])
        );
    });

    test('getAll supports filtering', function () use ($repository) {
        $result = $repository->getAll([
            'filter' => [
                'operator' => 'and',
                'conditions' => [
                    [
                        'field' => 'active',
                        'operator' => 'eq',
                        'value' => 1,
                    ],
                ],
            ],
        ]);

        assertSameValue(2, count($result['data']));
    });

    test('getAll supports sorting', function () use ($repository) {
        $result = $repository->getAll([
            'sorting' => [
                [
                    'field' => 'hsbNo',
                    'direction' => 'desc',
                ],
            ],
        ]);

        assertSameValue('1003', $result['data'][0]['hsbNo']);
    });

    test('getAll supports pagination', function () use ($repository) {
        $result = $repository->getAll([
            'pagination' => [
                'page' => 2,
                'perPage' => 2,
            ],
        ]);

        assertSameValue(1, count($result['data']));
    });

    test('getAll supports count', function () use ($repository) {
        $result = $repository->getAll([
            'withCount' => true,
            'pagination' => [
                'page' => 1,
                'perPage' => 1,
            ],
        ]);

        assertSameValue(1, count($result['data']));
        assertSameValue(3, $result['count']);
    });

    test('getAll supports row numbers', function () use ($repository) {
        $result = $repository->getAll([
            'withRowNumber' => true,
            'pagination' => [
                'page' => 2,
                'perPage' => 2,
            ],
        ]);

        assertSameValue(3, $result['data'][0]['rowNumber']);
    });

    test('getAll rejects row number column collision', function () use ($pdo) {
        $pdo->exec(
            "CREATE TEMPORARY TABLE php_database_row_number_test (
                id INT NOT NULL AUTO_INCREMENT,
                rowNumber INT NOT NULL DEFAULT 0,
                name VARCHAR(100) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB"
        );

        $repository = new class ($pdo) extends BaseRepository {
            protected function getDataSource(): DataSourceInterface
            {
                return new TableSource('php_database_row_number_test');
            }

            protected function getPrimaryKey(): array
            {
                return ['id'];
            }

            protected function getAvailableColumns(): array
            {
                return ['id', 'rowNumber', 'name'];
            }
        };

        assertThrows(
            RepositoryValidationException::class,
            function () use ($repository) {
                $repository->getAll([
                    'withRowNumber' => true,
                ]);
            }
        );
    });

    test('getAll supports unlimited pagination', function () use ($repository) {
        $result = $repository->getAll([
            'pagination' => [
                'page' => 5,
                'perPage' => 0,
            ],
        ]);

        assertSameValue(3, count($result['data']));
    });

    test('getAll rejects unsupported options', function () use ($repository) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($repository) {
                $repository->getAll([
                    'unknownOption' => true,
                ]);
            }
        );
    });

    test('getAll rejects invalid pagination', function () use ($repository) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($repository) {
                $repository->getAll([
                    'pagination' => [
                        'page' => 0,
                        'perPage' => 10,
                    ],
                ]);
            }
        );
    });

    // find

    test('find returns a record', function () use ($repository) {
        $row = $repository->find(['id' => 1]);

        assertTrue(is_array($row), 'Expected a record.');
        assertSameValue('1001', $row['hsbNo']);
    });

    test('find returns null for missing record', function () use ($repository) {
        $row = $repository->find(['id' => 999]);

        assertSameValue(null, $row);
    });

    test('find supports selected columns', function () use ($repository) {
        $row = $repository->find(
            ['id' => 1],
            ['hsbNo']
        );

        assertSameValue(['hsbNo'], array_keys($row));
    });

    test('find rejects missing primary key', function () use ($repository) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($repository) {
                $repository->find([]);
            }
        );
    });

    // count

    test('count returns total records', function () use ($repository) {
        assertSameValue(3, $repository->count());
    });

    test('count supports filters', function () use ($repository) {
        $filter = [
            'operator' => 'and',
            'conditions' => [
                [
                    'field' => 'active',
                    'operator' => 'eq',
                    'value' => 1,
                ],
            ],
        ];

        assertSameValue(2, $repository->count($filter));
    });

    // insert

    test('insert returns generated primary key', function () use ($repository) {
        $result = $repository->insert([
            'hsbNo' => '1004',
            'hsbName' => 'Fourth',
            'balance' => 400.50,
            'active' => 1,
        ]);

        assertTrue(
            isset($result['id']),
            'Generated primary key is missing.'
        );

        $row = $repository->find([
            'id' => $result['id'],
        ]);

        assertSameValue('1004', $row['hsbNo']);
    });

    test('insert supports null values', function () use ($repository) {
        $result = $repository->insert([
            'hsbNo' => '1005',
            'hsbName' => 'Fifth',
            'balance' => null,
            'active' => 1,
        ]);

        $row = $repository->find([
            'id' => $result['id'],
        ]);

        assertSameValue(null, $row['balance']);
    });

    test('insert supports explicitly supplied primary key', function () use ($repository) {
        $result = $repository->insert([
            'id' => 100,
            'hsbNo' => '1100',
            'hsbName' => 'Manual ID',
            'balance' => 10,
            'active' => 1,
        ]);

        assertSameValue(100, $result['id']);
    });

    test('insert rejects empty single primary key', function () use ($repository) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($repository) {
                $repository->insert([
                    'id' => '',
                    'hsbNo' => '1300',
                    'hsbName' => 'Invalid ID',
                    'balance' => 10,
                    'active' => 1,
                ]);
            }
        );
    });

    test('insert rejects empty data', function () use ($repository) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($repository) {
                $repository->insert([]);
            }
        );
    });

    test('insert rejects non-scalar values', function () use ($repository) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($repository) {
                $repository->insert([
                    'hsbNo' => '1200',
                    'hsbName' => ['invalid'],
                ]);
            }
        );
    });

    // update

    test('update changes a record', function () use ($repository) {
        $affected = $repository->update(
            ['id' => 1],
            ['hsbName' => 'Updated First']
        );

        assertSameValue(1, $affected);

        $row = $repository->find(['id' => 1]);

        assertSameValue('Updated First', $row['hsbName']);
    });

    test('update supports null values', function () use ($repository) {
        $repository->update(
            ['id' => 1],
            ['balance' => null]
        );

        $row = $repository->find(['id' => 1]);

        assertSameValue(null, $row['balance']);
    });

    test('update rejects empty data', function () use ($repository) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($repository) {
                $repository->update(['id' => 1], []);
            }
        );
    });

    test('update rejects primary key changes', function () use ($repository) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($repository) {
                $repository->update(
                    ['id' => 1],
                    ['id' => 2]
                );
            }
        );
    });

    // delete

    test('delete removes a record', function () use ($repository) {
        $affected = $repository->delete(['id' => 100]);

        assertSameValue(1, $affected);

        assertSameValue(
            null,
            $repository->find(['id' => 100])
        );
    });

    test('delete returns zero for missing record', function () use ($repository) {
        $affected = $repository->delete(['id' => 999]);

        assertSameValue(0, $affected);
    });

    // Composite primary key

    test('composite primary key insert returns all key values', function () use ($compositeRepository) {
        $result = $compositeRepository->insert([
            'companyId' => 3,
            'personId' => 30,
            'personName' => 'Person D',
        ]);

        assertSameValue(
            [
                'companyId' => 3,
                'personId' => 30,
            ],
            $result
        );
    });

    test('composite primary key find works', function () use ($compositeRepository) {
        $row = $compositeRepository->find([
            'companyId' => 1,
            'personId' => 20,
        ]);

        assertSameValue('Person B', $row['personName']);
    });

    test('composite primary key update works', function () use ($compositeRepository) {
        $affected = $compositeRepository->update(
            [
                'companyId' => 1,
                'personId' => 20,
            ],
            [
                'personName' => 'Updated Person B',
            ]
        );

        assertSameValue(1, $affected);
    });

    test('composite primary key delete works', function () use ($compositeRepository) {
        $affected = $compositeRepository->delete([
            'companyId' => 1,
            'personId' => 20,
        ]);

        assertSameValue(1, $affected);
    });

    test('composite primary key rejects incomplete values', function () use ($compositeRepository) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($compositeRepository) {
                $compositeRepository->find([
                    'companyId' => 1,
                ]);
            }
        );
    });

    // Repository configuration

    test('repository rejects primary key missing from available columns', function () use ($pdo) {
        $repository = new class ($pdo) extends BaseRepository {
            protected function getDataSource(): DataSourceInterface
            {
                return new TableSource('php_database_repository_test');
            }

            protected function getPrimaryKey(): array
            {
                return ['missingId'];
            }

            protected function getAvailableColumns(): array
            {
                return ['id', 'hsbNo', 'hsbName'];
            }
        };

        assertThrows(
            RepositoryException::class,
            function () use ($repository) {
                $repository->getAll();
            }
        );
    });

} catch (Throwable $exception) {
    echo "[ERROR] Test initialization failed.\n";
    echo $exception->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($pdo)) {
        $pdo = null;
    }
}

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
