<?php

namespace App\Services\Deployment\Strategies;

/** Composer install, persistent storage, cache commands and opt-in migrations. */
class LaravelDeploymentStrategy extends ConfigurableStrategy
{
    protected function type(): string
    {
        return "laravel";
    }
}
