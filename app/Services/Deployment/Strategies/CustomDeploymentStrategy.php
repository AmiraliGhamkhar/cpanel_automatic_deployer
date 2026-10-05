<?php
namespace App\Services\Deployment\Strategies;
use App\Models\Project;
class CustomDeploymentStrategy implements DeploymentStrategy
{
    public function requirements(): array
    {
        return [];
    }
    public function steps(Project $project, bool $hasRequirements = true): array
    {
        throw new \RuntimeException(
            "Custom deployment is unsupported until a reviewed server-side strategy is implemented. Arbitrary UI commands are prohibited.",
        );
    }
}
