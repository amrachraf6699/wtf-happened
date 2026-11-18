<?php

namespace AmrAchraf\WtfHappened;

use AmrAchraf\WtfHappened\Commands\WtfHappenedCommand;
use Illuminate\Support\ServiceProvider;

class WtfHappenedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $defaults = require __DIR__.'/../config/wtf-happened.php';
        $this->app['config']->set('wtf-happened', array_replace_recursive(
            $defaults, $this->app['config']->get('wtf-happened', [])
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WtfHappenedCommand::class]);
            $this->publishes([
                __DIR__.'/../config/wtf-happened.php' => config_path('wtf-happened.php'),
            ], 'wtf-happened-config');
        }
    }
}
