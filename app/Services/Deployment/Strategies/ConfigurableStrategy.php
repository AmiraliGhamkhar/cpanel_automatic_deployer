<?php

namespace App\Services\Deployment\Strategies;

use App\Models\Project;

/**
 * Turns a reusable deployment template (config/deployment_templates.php) into
 * the step list for one project.
 *
 * A template may only select operations that exist as reviewed command
 * templates in App\Services\Remote\Command; unknown operations are rejected
 * when the command is built.
 */
abstract class ConfigurableStrategy implements DeploymentStrategy
{
    /** Template key in config/deployment_templates.php. */
    abstract protected function type(): string;

    public function template(): array
    {
        $template = config("deployment_templates.templates." . $this->type());
        if (!is_array($template)) {
            throw new \RuntimeException(
                "Missing deployment template for " . $this->type() . ".",
            );
        }
        return $template;
    }

    public function requirements(): array
    {
        return (array) ($this->template()["requirements"] ?? []);
    }

    public function verificationCandidates(): array
    {
        return (array) ($this->template()["verification"] ?? []);
    }

    /** Default restart mechanism declared by the template. */
    public function restartStrategy(): string
    {
        return (string) ($this->template()["restart_strategy"] ?? "none");
    }

    public function steps(Project $project, bool $hasRequirements = true): array
    {
        $steps = [];
        foreach ($this->template()["steps"] ?? [] as $step) {
            $when = $step["when"] ?? null;
            if ($when !== null && !$project->setting($when, false)) {
                continue;
            }
            $operation = (string) $step["operation"];
            if (isset($step["when_file"]) && !$hasRequirements) {
                $operation = (string) ($step["operation_alt"] ?? $operation);
            }
            $steps[(string) $step["title"]] = $operation;
        }
        return $steps;
    }
}
