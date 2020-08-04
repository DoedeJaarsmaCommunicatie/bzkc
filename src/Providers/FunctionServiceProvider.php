<?php

namespace App\Providers;

use Twig\Environment;
use Twig\TwigFunction;

class FunctionServiceProvider extends ServiceProvider
{
    protected $functions = [];

    public function boot(): void
    {
        $this->functions = apply_filters('THEME_SLUG/providers/functions', []);

        add_filter('timber/twig', [$this, 'registerFunctions']);
    }

    /**
     * @param Environment $twig
     *
     * @return Environment
     */
    public function registerFunctions(Environment $twig): Environment
    {
        foreach ($this->functions as $name => $function) {
            $twig->addFunction(new TwigFunction($name, $function));
        }

        return $twig;
    }
}
