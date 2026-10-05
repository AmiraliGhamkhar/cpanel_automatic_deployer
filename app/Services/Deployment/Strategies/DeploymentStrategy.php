<?php
namespace App\Services\Deployment\Strategies;
use App\Models\Project;
interface DeploymentStrategy
{
    public function requirements(): array;

    /**
     * Repository files proving the checkout is complete. At least one must
     * exist; an empty list skips verification (static sites have no marker).
     *
     * @return list<string>
     */
    public function verificationCandidates(): array;
    /** @return array<string,string> Step title => allowlisted operation */
    public function steps(
        Project $project,
        bool $hasRequirements = true,
    ): array;
}
