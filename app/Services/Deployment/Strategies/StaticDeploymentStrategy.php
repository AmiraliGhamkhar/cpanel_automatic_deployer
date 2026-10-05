<?php

namespace App\Services\Deployment\Strategies;

/** Static releases, optionally built with npm before activation. */
class StaticDeploymentStrategy extends ConfigurableStrategy
{
    protected function type(): string
    {
        return "static";
    }
}
