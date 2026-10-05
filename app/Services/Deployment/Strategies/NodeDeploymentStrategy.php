<?php

namespace App\Services\Deployment\Strategies;

/** npm ci and an optional build step for hosts that provide Node.js. */
class NodeDeploymentStrategy extends ConfigurableStrategy
{
    protected function type(): string
    {
        return "node";
    }
}
