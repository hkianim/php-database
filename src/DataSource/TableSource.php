<?php

declare(strict_types=1);

namespace Hkianim\Database\DataSource;

use Hkianim\Database\Contracts\DataSourceInterface;
use Hkianim\Database\Exceptions\DataSourceException;

class TableSource implements DataSourceInterface
{
    /**
     * @var string
     */
    private $table;

    public function __construct(string $table)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new DataSourceException(
                'Invalid table name: ' . $table
            );
        }

        $this->table = $table;
    }

    public function getFromClause(): string
    {
        return '`' . $this->table . '`';
    }

    public function getBindings(): array
    {
        return [];
    }
}
