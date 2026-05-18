<?php

namespace larablocks\MapAi;

use Illuminate\Support\ServiceProvider;
use larablocks\MapAi\Commands\InstallCommand;

class MapAiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}
