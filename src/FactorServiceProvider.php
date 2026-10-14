<?php

declare(strict_types=1);

namespace Vortech\Factor;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Vortech\Factor\Numbers\RoundingMode;

final class FactorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/factor.php', 'factor');

        $this->app->singleton(Context::class, static function (Application $app): Context {
            $config = $app->make(Repository::class);

            return new Context(
                precision: $config->integer('factor.precision', 40),
                rounding: RoundingMode::from($config->string('factor.rounding', RoundingMode::HalfAwayFromZero->value)),
                maxIterations: $config->integer('factor.max_iterations', 200),
                maxLength: $config->integer('factor.max_length', 10_000),
                maxDepth: $config->integer('factor.max_depth', 128),
            );
        });

        $this->app->singleton(Factor::class, static fn (Application $app): Factor => new Factor($app->make(Context::class)));
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        Context::use($this->app->make(Context::class));

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/factor.php' => $this->app->configPath('factor.php')], 'factor-config');
        }
    }
}
