<?php

namespace App\Services\Deployment\Strategies;

use App\Models\Project;

/**
 * Custom projects are intentionally not deployable from the UI: a deployment
 * needs reviewed, first-party commands rather than operator-supplied shell.
 */
class CustomDeploymentStrategy extends ConfigurableStrategy
{
    protected function type(): string
    {
        return "custom";
    }

    public function steps(Project $project, bool $hasRequirements = true): array
    {
        throw new \RuntimeException(
            "Custom deployment is unsupported until a reviewed server-side strategy is implemented. Arbitrary UI commands are prohibited.",
        );
    }
}
