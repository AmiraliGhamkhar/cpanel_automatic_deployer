<?php

namespace App\Filament\Widgets;

use App\Models\Server;
use Filament\Tables\{Columns\TextColumn, Table};
use Filament\Widgets\TableWidget;

/** Server reachability at a glance; details live on the server page. */
class ServerStatus extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected function getTableHeading(): ?string
    {
        return "Server status";
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Server::query()->orderBy("name"))
            ->columns([
                TextColumn::make("name")->searchable(),
                TextColumn::make("status")->badge()->color(
                    fn($state) => match ($state) {
                        "online" => "success",
                        "offline" => "danger",
                        default => "gray",
                    },
                ),
                TextColumn::make("last_health_check_at")->since()->placeholder("Never"),
            ])
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->poll("10s");
    }
}
