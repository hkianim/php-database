<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Hkianim\Database\Query\FilterCompiler;
use Hkianim\Database\Exceptions\RepositoryValidationException;

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }

    echo "OK: {$message}\n";
}

$compiler = new FilterCompiler([
    'id',
    'hsbNo',
    'hsbType',
    'details',
    'status',
]);

// Empty filter
$result = $compiler->compile([]);

check($result['sql'] === '', 'Empty filter SQL');
check($result['bindings'] === [], 'Empty filter bindings');

// Simple comparison
$result = $compiler->compile([
    'operator' => 'and',
    'conditions' => [
        [
            'field' => 'hsbType',
            'operator' => 'eq',
            'value' => 'htBank',
        ],
    ],
]);

check(
    $result['sql'] === '(`hsbType` = ?)',
    'Simple comparison SQL'
);

check(
    $result['bindings'] === ['htBank'],
    'Simple comparison bindings'
);

// IN operator
$result = $compiler->compile([
    'operator' => 'and',
    'conditions' => [
        [
            'field' => 'hsbType',
            'operator' => 'in',
            'value' => ['htBank', 'htCash'],
        ],
    ],
]);

check(
    $result['sql'] === '(`hsbType` IN (?, ?))',
    'IN operator SQL'
);

check(
    $result['bindings'] === ['htBank', 'htCash'],
    'IN operator bindings'
);

// Empty IN
$result = $compiler->compile([
    'operator' => 'and',
    'conditions' => [
        [
            'field' => 'id',
            'operator' => 'in',
            'value' => [],
        ],
    ],
]);

check(
    $result['sql'] === '(1 = 0)',
    'Empty IN operator'
);

// NULL comparison
$result = $compiler->compile([
    'operator' => 'and',
    'conditions' => [
        [
            'field' => 'details',
            'operator' => 'eq',
            'value' => null,
        ],
    ],
]);

check(
    $result['sql'] === '(`details` IS NULL)',
    'NULL equality'
);

// Nested groups
$result = $compiler->compile([
    'operator' => 'and',
    'conditions' => [
        [
            'field' => 'status',
            'operator' => 'eq',
            'value' => 1,
        ],
        [
            'operator' => 'or',
            'conditions' => [
                [
                    'field' => 'hsbType',
                    'operator' => 'eq',
                    'value' => 'htBank',
                ],
                [
                    'field' => 'hsbType',
                    'operator' => 'eq',
                    'value' => 'htCash',
                ],
            ],
        ],
    ],
]);

check(
    $result['sql'] ===
    '(`status` = ? AND (`hsbType` = ? OR `hsbType` = ?))',
    'Nested groups SQL'
);

check(
    $result['bindings'] === [1, 'htBank', 'htCash'],
    'Nested groups binding order'
);

// LIKE operator
$result = $compiler->compile([
    'operator' => 'and',
    'conditions' => [
        [
            'field' => 'details',
            'operator' => 'contains',
            'value' => 'ملت',
        ],
    ],
]);

check(
    $result['bindings'] === ['%ملت%'],
    'LIKE contains bindings'
);

// Invalid column
try {
    $compiler->compile([
        'operator' => 'and',
        'conditions' => [
            [
                'field' => 'unknownColumn',
                'operator' => 'eq',
                'value' => 1,
            ],
        ],
    ]);

    throw new RuntimeException('Invalid column was accepted.');
} catch (RepositoryValidationException $e) {
    echo "OK: Invalid column rejected\n";
}

// Invalid operator
try {
    $compiler->compile([
        'operator' => 'and',
        'conditions' => [
            [
                'field' => 'id',
                'operator' => 'between',
                'value' => [1, 10],
            ],
        ],
    ]);

    throw new RuntimeException('Invalid operator was accepted.');
} catch (RepositoryValidationException $e) {
    echo "OK: Invalid operator rejected\n";
}

echo "\nAll tests passed.\n";
