<?php
namespace App\Services\Deployment\Strategies;
use App\Models\Project;
class StaticDeploymentStrategy implements DeploymentStrategy
{
    public function requirements(): array
    {
        return ["git", "timeout"];
    }
    public function verificationCandidates(): array
    {
        // A static site may be built from any source layout, so there is no marker.
        return [];
    }

    public function steps(Project $project, bool $hasRequirements = true): array
    {
        return $project->settings["build"] ?? false
            ? [
                "Install build dependencies" => "npm",
                "Build static assets" => "build",
            ]
            : [];
    }
}
