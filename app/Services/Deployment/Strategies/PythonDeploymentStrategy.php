<?php
namespace App\Services\Deployment\Strategies;
use App\Models\Project;
class PythonDeploymentStrategy implements DeploymentStrategy
{
    public function requirements(): array
    {
        return ["git", "python", "pip", "timeout"];
    }

    public function verificationCandidates(): array
    {
        return ["requirements.txt", "pyproject.toml"];
    }
    public function steps(Project $project, bool $hasRequirements = true): array
    {
        return [
            "Create virtual environment and install dependencies" => $hasRequirements
                ? "python"
                : "pyproject",
        ];
    }
}
