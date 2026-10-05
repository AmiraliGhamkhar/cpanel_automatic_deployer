<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentService;
class DeployProjectJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 1800;
    public bool $failOnTimeout = true;
    public function __construct(public int $deploymentId) {}
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("deployment-" . $this->deploymentId)
                ->dontRelease()
                ->expireAfter(2100),
        ];
    }
    public function handle(DeploymentService $service): void
    {
        try {
            $service->execute(Deployment::findOrFail($this->deploymentId));
        } catch (\Throwable) {
            $service->fail($this->deploymentId);
        }
    }
    public function failed(?\Throwable $e): void
    {
        app(DeploymentService::class)->fail($this->deploymentId);
    }
}
