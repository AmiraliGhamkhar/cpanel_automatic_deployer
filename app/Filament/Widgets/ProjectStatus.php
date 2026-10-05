<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Tables\{Columns\TextColumn, Table};
use Filament\Widgets\TableWidget;

/** Application state after the last deployment and health check. */
class ProjectStatus extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected function getTableHeading(): ?string
    {
        return "Project status";
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Project::query()->with("server")->orderBy("name"))
            ->columns([
                TextColumn::make("name")->searchable(),
                TextColumn::make("server.name")->label("Server"),
                TextColumn::make("status")->badge()->color(
                    fn($state) => match ($state) {
                        "running" => "success",
                        "failed" => "danger",
                        default => "gray",
                    },
                ),
                TextColumn::make("health_check_message")
                    ->label("Last health result")
                    ->wrap()
                    ->limit(60)
                    ->placeholder("Not checked yet"),
            ])
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->poll("10s")
            ->recordUrl(
                fn($record) => \App\Filament\Resources\ProjectResource::getUrl(
                    "edit",
                    ["record" => $record],
                ),
            );
    }
}
