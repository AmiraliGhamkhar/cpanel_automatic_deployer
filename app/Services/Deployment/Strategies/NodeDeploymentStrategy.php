<?php
namespace App\Services\Deployment\Strategies;
use App\Models\Project;
class NodeDeploymentStrategy implements DeploymentStrategy
{
    public function requirements(): array
    {
        return ["git", "node", "npm", "timeout"];
    }
    public function steps(Project $project, bool $hasRequirements = true): array
    {
        $steps = ["Install locked Node dependencies" => "npm"];
        if ($project->settings["build"] ?? false) {
            $steps["Build Node assets"] = "build";
        }
        return $steps;
    }
}
