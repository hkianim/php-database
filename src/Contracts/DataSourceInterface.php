<?php

declare(strict_types=1);

namespace Hkianim\Database\Contracts;

interface DataSourceInterface
{
    /**
     * Returns the SQL FROM clause for this data source.
     */
    public function getFromClause(): string;

    /**
     * Returns the positional parameter bindings for the data source.
     *
     * @return array
     */
    public function getBindings(): array;
}
