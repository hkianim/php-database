<?php

declare(strict_types=1);

namespace Hkianim\Database\DataSource;

use Hkianim\Database\Contracts\DataSourceInterface;
use Hkianim\Database\Exceptions\DataSourceException;

class ViewSource implements DataSourceInterface
{
    /**
     * @var string
     */
    private $view;

    public function __construct(string $view)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $view)) {
            throw new DataSourceException(
                'Invalid view name: ' . $view
            );
        }

        $this->view = $view;
    }

    public function getFromClause(): string
    {
        return '`' . $this->view . '`';
    }

    public function getBindings(): array
    {
        return [];
    }
}
