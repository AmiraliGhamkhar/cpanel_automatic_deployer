<?php

namespace App\Services\Deployment;

use App\Services\Deployment\Strategies\{
    ConfigurableStrategy,
    CustomDeploymentStrategy,
    LaravelDeploymentStrategy,
    NodeDeploymentStrategy,
    PythonDeploymentStrategy,
    StaticDeploymentStrategy,
};

/** Selects the strategy for a project type; unknown types are rejected. */
class StrategyRegistry
{
    public function for(string $type): ConfigurableStrategy
    {
        return match ($type) {
            "laravel" => new LaravelDeploymentStrategy(),
            "python" => new PythonDeploymentStrategy(),
            "node" => new NodeDeploymentStrategy(),
            "static" => new StaticDeploymentStrategy(),
            "custom" => new CustomDeploymentStrategy(),
            default => throw new \InvalidArgumentException("Unknown project type"),
        };
    }

    /** Project types that have a template, for validation and UI lists. */
    public function types(): array
    {
        return array_keys((array) config("deployment_templates.templates", []));
    }
}
