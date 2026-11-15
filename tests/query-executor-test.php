<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Hkianim\Database\Query\QueryExecutor;
use Hkianim\Database\Exceptions\QueryException;
use Hkianim\Database\Exceptions\RepositoryValidationException;

$dsn = getenv('DB_TEST_DSN');
$username = getenv('DB_TEST_USERNAME');
$password = getenv('DB_TEST_PASSWORD');

if (!$dsn) {
    exit("DB_TEST_DSN is required.\n");
}

if ($username === false) {
    $username = '';
}

if ($password === false) {
    $password = '';
}

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

function assertThrows(
    string $expectedClass,
    callable $callback
): Throwable {
    try {
        $callback();
    } catch (Throwable $exception) {
        if (!$exception instanceof $expectedClass) {
            throw new RuntimeException(
                'Expected ' . $expectedClass .
                ', got ' . get_class($exception)
            );
        }

        return $exception;
    }

    throw new RuntimeException(
        'Expected exception ' . $expectedClass . ' was not thrown.'
    );
}

try {
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $executor = new QueryExecutor($pdo);

    $pdo->exec(
        "CREATE TEMPORARY TABLE php_database_executor_test (
            id INT NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            active TINYINT(1) NOT NULL,
            nullable_value VARCHAR(100) NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "INSERT INTO php_database_executor_test
            (name, amount, active, nullable_value)
        VALUES
            ('First', 125.50, 1, NULL),
            ('Second', 250.00, 0, 'sample')"
    );

    // fetchAll

    test('fetchAll returns all rows', function () use ($executor) {
        $rows = $executor->fetchAll(
            "SELECT id, name
             FROM php_database_executor_test
             ORDER BY id"
        );

        assertSameValue(2, count($rows));
        assertSameValue('First', $rows[0]['name']);
        assertSameValue('Second', $rows[1]['name']);
    });

    test('fetchAll supports bindings', function () use ($executor) {
        $rows = $executor->fetchAll(
            "SELECT name
             FROM php_database_executor_test
             WHERE id > ?
             ORDER BY id",
            [1]
        );

        assertSameValue(1, count($rows));
        assertSameValue('Second', $rows[0]['name']);
    });

    // fetchOne

    test('fetchOne returns one row', function () use ($executor) {
        $row = $executor->fetchOne(
            "SELECT name, amount
             FROM php_database_executor_test
             WHERE id = ?",
            [1]
        );

        assertTrue(is_array($row), 'Expected an array.');
        assertSameValue('First', $row['name']);
        assertSameValue('125.50', $row['amount']);
    });

    test('fetchOne returns null when no row exists', function () use ($executor) {
        $row = $executor->fetchOne(
            "SELECT *
             FROM php_database_executor_test
             WHERE id = ?",
            [999]
        );

        assertSameValue(null, $row);
    });

    // fetchColumn

    test('fetchColumn returns the requested column', function () use ($executor) {
        $value = $executor->fetchColumn(
            "SELECT name, amount
             FROM php_database_executor_test
             WHERE id = ?",
            [1],
            1
        );

        assertSameValue('125.50', $value);
    });

    test('fetchColumn supports column zero by default', function () use ($executor) {
        $value = $executor->fetchColumn(
            "SELECT name
             FROM php_database_executor_test
             WHERE id = ?",
            [2]
        );

        assertSameValue('Second', $value);
    });

    test('fetchColumn throws when no row exists', function () use ($executor) {
        assertThrows(
            QueryException::class,
            function () use ($executor) {
                $executor->fetchColumn(
                    "SELECT name
                     FROM php_database_executor_test
                     WHERE id = ?",
                    [999]
                );
            }
        );
    });

    test('fetchColumn throws for invalid column index', function () use ($executor) {
        assertThrows(
            QueryException::class,
            function () use ($executor) {
                $executor->fetchColumn(
                    "SELECT name
                     FROM php_database_executor_test
                     WHERE id = ?",
                    [1],
                    1
                );
            }
        );
    });

    test('fetchColumn rejects negative column index', function () use ($executor) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($executor) {
                $executor->fetchColumn(
                    "SELECT name
                     FROM php_database_executor_test",
                    [],
                    -1
                );
            }
        );
    });

    // execute

    test('execute inserts a row and returns affected rows', function () use ($executor) {
        $affected = $executor->execute(
            "INSERT INTO php_database_executor_test
                (name, amount, active, nullable_value)
             VALUES (?, ?, ?, ?)",
            ['Third', 75.25, true, null]
        );

        assertSameValue(1, $affected);
    });

    test('execute updates rows and returns affected rows', function () use ($executor) {
        $affected = $executor->execute(
            "UPDATE php_database_executor_test
             SET name = ?
             WHERE id = ?",
            ['Updated', 1]
        );

        assertSameValue(1, $affected);
    });

    test('execute deletes rows and returns affected rows', function () use ($executor) {
        $affected = $executor->execute(
            "DELETE FROM php_database_executor_test
             WHERE id = ?",
            [3]
        );

        assertSameValue(1, $affected);
    });

    // Binding validation

    test('bindings support scalar and null values', function () use ($executor) {
        $row = $executor->fetchOne(
            "SELECT
                ? AS integer_value,
                ? AS string_value,
                ? AS boolean_value,
                ? AS null_value",
            [12, 'hello', true, null]
        );

        assertTrue(is_array($row), 'Expected a result row.');
        assertSameValue('12', $row['integer_value']);
        assertSameValue('hello', $row['string_value']);
        assertSameValue('1', $row['boolean_value']);
        assertSameValue(null, $row['null_value']);
    });

    test('bindings reject non-scalar values', function () use ($executor) {
        assertThrows(
            RepositoryValidationException::class,
            function () use ($executor) {
                $executor->fetchAll(
                    "SELECT ?",
                    [['invalid']]
                );
            }
        );
    });

    // Exception handling

    test('database errors are wrapped in QueryException', function () use ($executor) {
        $exception = assertThrows(
            QueryException::class,
            function () use ($executor) {
                $executor->fetchAll(
                    "SELECT *
                     FROM php_database_missing_table"
                );
            }
        );

        assertTrue(
            $exception->getPrevious() instanceof PDOException,
            'Previous exception must be a PDOException.'
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
