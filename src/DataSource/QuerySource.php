<?php

declare(strict_types=1);

namespace Hkianim\Database\DataSource;

use Hkianim\Database\Contracts\DataSourceInterface;
use Hkianim\Database\Exceptions\DataSourceException;

class QuerySource implements DataSourceInterface
{
    /**
     * @var string
     */
    private $sql;

    /**
     * @var array
     */
    private $bindings;

    public function __construct(string $sql, array $bindings = [])
    {
        $sql = trim($sql);

        if ($sql === '') {
            throw new DataSourceException(
                'Query source SQL cannot be empty.'
            );
        }

        $placeholderCount = $this->countPlaceholders($sql);

        if ($placeholderCount !== count($bindings)) {
            throw new DataSourceException(
                'The number of SQL placeholders does not match the bindings.'
            );
        }

        $this->sql = $sql;
        $this->bindings = array_values($bindings);
    }

    public function getFromClause(): string
    {
        return '(' . $this->sql . ')';
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Counts positional placeholders outside SQL strings and comments.
     */
    private function countPlaceholders(string $sql): int
    {
        $count = 0;
        $length = strlen($sql);
        $state = 'normal';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($state === 'normal') {
                if ($char === "'") {
                    $state = 'single_quote';
                } elseif ($char === '"') {
                    $state = 'double_quote';
                } elseif ($char === '`') {
                    $state = 'backtick';
                } elseif ($char === '#') {
                    $state = 'line_comment';
                } elseif (
                    $char === '-' &&
                    $next === '-' &&
                    ($i + 2 >= $length || ctype_space($sql[$i + 2]))
                ) {
                    $state = 'line_comment';
                    $i++;
                } elseif ($char === '/' && $next === '*') {
                    $state = 'block_comment';
                    $i++;
                } elseif ($char === '?') {
                    $count++;
                } elseif ($char === ';') {
                    throw new DataSourceException(
                        'Multiple SQL statements are not allowed.'
                    );
                }

                continue;
            }

            if ($state === 'line_comment') {
                if ($char === "\n" || $char === "\r") {
                    $state = 'normal';
                }

                continue;
            }

            if ($state === 'block_comment') {
                if ($char === '*' && $next === '/') {
                    $state = 'normal';
                    $i++;
                }

                continue;
            }

            if (
                $state === 'single_quote' ||
                $state === 'double_quote' ||
                $state === 'backtick'
            ) {
                $closingChar = $state === 'single_quote'
                    ? "'"
                    : ($state === 'double_quote' ? '"' : '`');

                if ($char === '\\' && $i + 1 < $length) {
                    $i++;
                } elseif ($char === $closingChar) {
                    if ($next === $closingChar) {
                        $i++;
                    } else {
                        $state = 'normal';
                    }
                }
            }
        }

        if ($state === 'block_comment') {
            throw new DataSourceException(
                'Unclosed SQL block comment.'
            );
        }

        if (
            $state === 'single_quote' ||
            $state === 'double_quote' ||
            $state === 'backtick'
        ) {
            throw new DataSourceException(
                'Unclosed SQL quoted expression.'
            );
        }

        return $count;
    }
}
