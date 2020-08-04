<?php

namespace App\Providers;

abstract class ServiceProvider
{
	public function __construct () {
		if (method_exists($this, 'boot')) {
			$this->boot();
		}

		if (method_exists($this, 'register')) {
			$this->register();
		}
	}

	public function boot() {}

	public function register() {}
}
