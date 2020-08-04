<?php

namespace App\Providers;

use App\Bootstrap\Container;
use App\Controllers\Hooks\Actions\Action;
use App\Controllers\Hooks\Filters\Filter;

class HookServiceProvider extends ServiceProvider
{
    /**
     * @var Action[]
     */
    protected $actions = [];

    /**
     * @var Filter[]
     */
    protected $filters = [];
    protected $actions_unhook = [];
    protected $filters_unhook = [];

    public function boot(): void
    {
        $this->filters = apply_filters('THEME_SLUG/providers/filters', []);

        $this->actions = apply_filters('THEME_SLUG/providers/actions', []);

        $this->filters_unhook = apply_filters('THEME_SLUG/providers/filters/unhook', []);

        $this->actions_unhook = apply_filters('THEME_SLUG/providers/actions/unhook', []);
    }

    public function register(): void
    {
        foreach ($this->actions as $hookName => $action) {
            if (class_exists($action) && is_subclass_of($action, Action::class)) {
                $called = Container::get($action);
                add_action($hookName, [$called, 'action'], $called->priority(), $called->parameterCount());
            } else {
                add_action($hookName, $action);
            }
        }

        foreach ($this->filters as $hookName => $filter) {
            if (class_exists($filter) && is_subclass_of($filter, Filter::class)) {
                $called = Container::get($filter);
                add_filter($hookName, [$called, 'filter'], $called->priority(), $called->parameterCount());
            } else {
                add_filter($hookName, $filter);
            }
        }

        foreach ($this->actions_unhook as $hook => $item) {
            remove_action($hook, $item['name'], $item['priority']);
        }

        foreach ($this->filters_unhook as $hook => $item) {
            remove_filter($hook, $item['name'], $item['priority']);
        }
    }
}
