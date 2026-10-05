<?php

namespace App\Services\Deployment\Strategies;

/** Virtual environment plus requirements.txt or pyproject.toml installation. */
class PythonDeploymentStrategy extends ConfigurableStrategy
{
    protected function type(): string
    {
        return "python";
    }
}
