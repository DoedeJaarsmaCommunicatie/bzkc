<?php
namespace App;

use App\Bootstrap\Container;
use App\Providers\AppServiceProvider;

Container::get(AppServiceProvider::class);
