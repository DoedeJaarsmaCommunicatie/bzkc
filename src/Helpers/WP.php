<?php
declare(strict_types = 1);

namespace App\Helpers;

use App\Exceptions\MultiSiteNotEnabledException;

use function wp_dequeue_style;
use function wp_enqueue_style;
use function wp_register_style;
use function wp_enqueue_script;
use function wp_register_script;
use function get_current_blog_id;
use function wp_deregister_script;

class WP
{
	protected static $stylesheet_dir_cache = null;
	protected static $stylesheet_url_cache = null;
	protected static $dequeue_cache = [];
	protected static $dequeue_cache_scripts = [];
	protected static $enqueue_cache = [];
	protected static $enqueue_cache_scripts = [];

	public static function getStylesheetDir()
	{
		if (null !== static::$stylesheet_dir_cache) {
			return static::$stylesheet_dir_cache;
		}

		return static::$stylesheet_dir_cache = get_stylesheet_directory();
	}

	public static function getStylesheetUrl()
	{
		if (null !== static::$stylesheet_url_cache) {
			return static::$stylesheet_url_cache;
		}

		return static::$stylesheet_url_cache = get_stylesheet_directory_uri();
	}

	/**
	 * Gets the asset location from either a string or an array.
	 *
	 * @param array|string $path
	 * @param bool         $is_url
	 *
	 * @return string
	 */
	public static function getAssetLocation($path, $is_url = true): string
	{
		$base = $is_url ? static::getStylesheetUrl() : static::getStylesheetDir();
		$base = trailingslashit($base);

		if (is_array($path)) {
			$path = implode('/', $path);
		}

		return $base . $path;
	}

	/**
	 * Adds scripts to the cache and registers them.
	 *
	 * @param string $handle
	 * @param bool   $src
	 * @param array  $deps
	 * @param bool   $ver
	 * @param bool   $in_footer
	 *
	 * @return void
	 */
	public static function addScript($handle, $src = false, $deps = [], $ver = false, $in_footer = true)
	{
		if (isset(static::$enqueue_cache_scripts[$handle])) {
			return;
		}

		add_action('wp_enqueue_scripts', static function () use ($handle, $src, $deps, $ver, $in_footer) {
			wp_register_script(
				$handle,
				$src,
				$deps,
				$ver,
				$in_footer
			);
		});

		static::$enqueue_cache_scripts[$handle] = $handle;
	}

	/**
	 * Adds handles to the cache and registers them in wordpress
	 *
	 * @param string      $handle
	 * @param bool        $src
	 * @param array       $deps
	 * @param bool|string $ver
	 * @param string      $media
	 *
	 * @return void
	 */
	public static function addStyle($handle, $src = false, $deps = [], $ver = false, $media = 'all')
	{
		if (isset(static::$enqueue_cache[$handle])) {
			return;
		}

		add_action('wp_enqueue_scripts', static function () use ($handle, $src, $deps, $ver, $media) {
			wp_register_style($handle, $src, $deps, $ver, $media);
		});

		static::$enqueue_cache[$handle] = $handle;
	}

	public static function enqueueScripts()
	{
		foreach (static::$enqueue_cache_scripts as $script) {
			add_action('wp_enqueue_scripts', static function () use ($script) {
				wp_enqueue_script($script);
			});
		}
	}

	/**
	 * Enqueues all the cached style handles.
	 *
	 * @return void
	 */
	public static function enqueueStyles()
	{
		foreach (static::$enqueue_cache as $item) {
			add_action('wp_enqueue_scripts', static function () use ($item) {
				wp_enqueue_style($item);
			});
		}
	}

	/**
	 * Removes scripts from wp enqueue array
	 *
	 * @param $handle
	 */
	public static function removeScript($handle)
	{
		if (!isset(static::$dequeue_cache_scripts[$handle])) {
			wp_deregister_script($handle);
			static::$dequeue_cache_scripts[$handle] = $handle;
		}
	}

	/**
	 * Removes styles from wp enqueue array
	 *
	 * @param $handle
	 */
	public static function removeStyle($handle)
	{
		if (isset(static::$dequeue_cache[$handle])) {
			return;
		}

		wp_dequeue_style($handle);
		static::$dequeue_cache[$handle] = $handle;
	}
}
