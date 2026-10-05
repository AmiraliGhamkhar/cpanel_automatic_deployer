<?php
namespace App\Services\Deployment\Strategies;
use App\Models\Project;
class LaravelDeploymentStrategy implements DeploymentStrategy
{
    public function requirements(): array
    {
        return ["git", "php", "composer", "timeout"];
    }
    public function steps(Project $project, bool $hasRequirements = true): array
    {
        $steps = [
            "Install Composer dependencies" => "composer",
            "Prepare Laravel directories" => "laravel-storage",
            "Clear stale Laravel configuration" => "laravel-clear",
        ];
        if ($project->settings["migrations"] ?? false) {
            $steps["Run explicitly enabled migrations"] = "migrate";
        }
        $steps["Cache Laravel configuration"] = "laravel-cache";
        return $steps;
    }
}
