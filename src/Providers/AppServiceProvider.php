<?php
namespace App\Providers;

use App\Helpers\Log;
use App\Bootstrap\Container;

class AppServiceProvider extends ServiceProvider
{
	protected $providers;

	public function boot(): void
	{
		$providers = include get_stylesheet_directory() . '/src/config/app.php';
		$this->providers = apply_filters('THEME_SLUG/app/register-providers/providers', $providers['providers']);
	}

	public function register(): void
	{
		foreach ($this->providers as $provider) {
			try {
				do_action('THEME_SLUG/app/register-providers/register/' . (new \ReflectionClass($provider))->getShortName());
			} catch (\ReflectionException $exception) {
				Log::warning('Reflection error while registering providers', ['class' => $provider]);
				return;
			}
			Container::get($provider);
		}
	}
}
