<?php
namespace App\Services\Deployment\Strategies;
use App\Models\Project;
interface DeploymentStrategy
{
    public function requirements(): array;
    /** @return array<string,string> Step title => allowlisted operation */
    public function steps(
        Project $project,
        bool $hasRequirements = true,
    ): array;
}
