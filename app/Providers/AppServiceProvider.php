<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Services\Remote\{
    SshServiceInterface,
    SshService,
    CpanelServiceInterface,
    CpanelUapiService,
};
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \App\Services\BackupManager::class,
            \App\Services\FileBackupManager::class,
        );
        $this->app->bind(SshServiceInterface::class, SshService::class);
        $this->app->bind(
            CpanelServiceInterface::class,
            CpanelUapiService::class,
        );
    }
    public function boot(): void
    {
        foreach (
            [
                \App\Models\Server::class,
                \App\Models\Project::class,
                \App\Models\Deployment::class,
                \App\Models\Backup::class,
                \App\Models\AuditLog::class,
            ]
            as $model
        ) {
            Gate::policy($model, \App\Policies\AdminPolicy::class);
        }
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            function ($event) {
                \App\Models\AuditLog::create([
                    "user_id" => $event->user->id,
                    "action" => "LOGIN",
                    "ip" => request()->ip(),
                    "result" => "success",
                ]);
            },
        );
    }
}
