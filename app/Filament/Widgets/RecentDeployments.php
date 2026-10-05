<?php
namespace App\Filament\Widgets;
use Filament\Widgets\TableWidget;
use Filament\Tables\{Table, Columns\TextColumn};
use App\Models\Deployment;
class RecentDeployments extends TableWidget
{
    protected int|string|array $columnSpan = "full";
    protected static ?int $sort = 2;
    public function table(Table $table): Table
    {
        return $table
            ->query(Deployment::query()->latest())
            ->columns([
                TextColumn::make("project.name"),
                TextColumn::make("project.server.name")->label("Server"),
                TextColumn::make("branch"),
                TextColumn::make("commit_hash")->limit(8),
                TextColumn::make("status")->badge(),
                TextColumn::make("created_at")->since(),
                TextColumn::make("duration")->suffix("s"),
            ])
            ->defaultPaginationPageOption(5)
            ->poll("5s")
            ->recordUrl(
                fn(
                    $record,
                ) => \App\Filament\Resources\DeploymentResource::getUrl(
                    "logs",
                    ["record" => $record],
                ),
            );
    }
}
