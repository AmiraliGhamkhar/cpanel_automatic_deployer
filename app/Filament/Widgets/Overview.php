<?php
namespace App\Filament\Widgets;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\{Server, Project, Deployment};
class Overview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;
    protected function getStats(): array
    {
        return [
            Stat::make("Servers", Server::count())->description(
                Server::where("status", "online")->count() .
                    " reachable at last check",
            ),
            Stat::make("Projects", Project::count())->description(
                Project::where("status", "running")->count() .
                    " passed their last health check",
            ),
            Stat::make(
                "Successful deployments",
                Deployment::whereIn("status", [
                    "success",
                    "rolled_back",
                ])->count(),
            )->color("success"),
            Stat::make(
                "Failed deployments",
                Deployment::where("status", "failed")->count(),
            )->color("danger"),
        ];
    }
}
