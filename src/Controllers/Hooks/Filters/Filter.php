<?php

namespace App\Controllers\Hooks\Filters;

abstract class Filter
{
    abstract public function filter();

    public function priority(): int
    {
        return 10;
    }

    public function parameterCount(): int
    {
        return 1;
    }
}
