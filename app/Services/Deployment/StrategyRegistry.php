<?php
namespace App\Services\Deployment;
use App\Services\Deployment\Strategies\{
    DeploymentStrategy,
    LaravelDeploymentStrategy,
    PythonDeploymentStrategy,
    NodeDeploymentStrategy,
    StaticDeploymentStrategy,
    CustomDeploymentStrategy,
};
class StrategyRegistry
{
    public function for(string $type): DeploymentStrategy
    {
        return match ($type) {
            "laravel" => new LaravelDeploymentStrategy(),
            "python" => new PythonDeploymentStrategy(),
            "node" => new NodeDeploymentStrategy(),
            "static" => new StaticDeploymentStrategy(),
            "custom" => new CustomDeploymentStrategy(),
            default => throw new \InvalidArgumentException(
                "Unknown project type",
            ),
        };
    }
}
