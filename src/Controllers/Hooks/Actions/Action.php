<?php

namespace App\Controllers\Hooks\Actions;

abstract class Action
{
    abstract public function action();

    public function priority(): int
    {
        return 10;
    }

    public function parameterCount(): int
    {
        return 1;
    }
}
