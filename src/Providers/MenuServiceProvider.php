<?php
namespace App\Providers;

use Timber\Menu;
use Illuminate\Support\Str;
use function register_nav_menus;

/**
 * Class MenuServiceProvider
 *
 * @package App\Providers
 */
class MenuServiceProvider extends ServiceProvider
{
    /**
     * The registered menus
     *
     * @var array
     */
    protected $menus = [
        'primary-menu' => 'Primary',
    ];

    /**
     * Register nav menus in timber
     *
     * @return void
     */
    public function boot(): void
    {
        register_nav_menus(apply_filters('THEME_SLUG/providers/menus', $this->menus));

        add_filter('timber/context', [ $this, 'registerContent' ]);
    }

    /**
     * Register nav menu's in twig.
     *
     * @param array $content
     *
     * @return mixed
     */
    public function registerContent($content)
    {
        foreach ($this->menus as $key => $name) {
            $content[ Str::camel($key) ] = new Menu($key);
        }
        return $content;
    }
}
