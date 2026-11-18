<?php

namespace AmrAchraf\WtfHappened;

use AmrAchraf\WtfHappened\Commands\WtfHappenedCommand;
use Illuminate\Support\ServiceProvider;

class WtfHappenedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $defaults = require __DIR__.'/../config/wtf-happened.php';
        $configured = $this->app['config']->get('wtf-happened', []);
        $merged = array_replace_recursive($defaults, $configured);
        // Ordered lists replace defaults rather than merging by numeric index.
        foreach (['module_roots', 'category_rules'] as $key) {
            if (array_key_exists($key, $configured['analysis'] ?? [])) {
                $merged['analysis'][$key] = $configured['analysis'][$key];
            }
        }
        $this->app['config']->set('wtf-happened', $merged);
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
