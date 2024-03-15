<?php

namespace Ken\Providers;

use Illuminate\Support\ServiceProvider;
use Ken\Commands\CreateRedisIndex;

class BaseProviderService extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            CreateRedisIndex::class
        ]);
    }
}
