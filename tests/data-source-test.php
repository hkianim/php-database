<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Hkianim\Database\DataSource\TableSource;
use Hkianim\Database\DataSource\ViewSource;
use Hkianim\Database\DataSource\QuerySource;
use Hkianim\Database\Exceptions\DataSourceException;

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }

    echo "OK: {$message}\n";
}

// TableSource
$table = new TableSource('HsbNumbers');

check(
    $table->getFromClause() === '`HsbNumbers`',
    'TableSource FROM clause'
);

check(
    $table->getBindings() === [],
    'TableSource bindings'
);

// ViewSource
$view = new ViewSource('vwHsbNumbers');

check(
    $view->getFromClause() === '`vwHsbNumbers`',
    'ViewSource FROM clause'
);

// QuerySource
$query = new QuerySource(
    'SELECT id, hsbNo FROM HsbNumbers WHERE hsbType = ?',
    ['htBank']
);

check(
    $query->getFromClause() ===
    '(SELECT id, hsbNo FROM HsbNumbers WHERE hsbType = ?)',
    'QuerySource FROM clause'
);

check(
    $query->getBindings() === ['htBank'],
    'QuerySource bindings'
);

// Placeholder inside string must not be counted.
$query = new QuerySource(
    "SELECT id FROM HsbNumbers WHERE details = '?' AND hsbType = ?",
    ['htBank']
);

check(
    $query->getBindings() === ['htBank'],
    'Placeholder inside SQL string'
);

// Invalid table name
try {
    new TableSource('HsbNumbers; DROP TABLE Users');

    throw new RuntimeException('Invalid table name was accepted.');
} catch (DataSourceException $e) {
    echo "OK: Invalid table name rejected\n";
}

// Mismatched bindings
try {
    new QuerySource(
        'SELECT id FROM HsbNumbers WHERE hsbType = ?',
        []
    );

    throw new RuntimeException('Mismatched bindings were accepted.');
} catch (DataSourceException $e) {
    echo "OK: Mismatched bindings rejected\n";
}

echo "\nAll tests passed.\n";
