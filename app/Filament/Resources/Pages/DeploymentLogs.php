<?php
namespace App\Filament\Resources\Pages;
use App\Models\Deployment;
use Illuminate\Support\Facades\Gate;
class DeploymentLogs extends \Filament\Resources\Pages\Page
{
    protected static string $resource = \App\Filament\Resources\DeploymentResource::class;
    protected static string $view = "filament.deployment-logs";
    #[\Livewire\Attributes\Locked]
    public int $deploymentId;
    public function mount(int|string $record): void
    {
        $this->deploymentId = (int) $record;
        Gate::authorize("view", Deployment::findOrFail($this->deploymentId));
    }
    public function deployment(): Deployment
    {
        $d = Deployment::with("project.server")->findOrFail(
            $this->deploymentId,
        );
        Gate::authorize("view", $d);
        return $d;
    }
}
