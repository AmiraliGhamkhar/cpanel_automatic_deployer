<?php
namespace App\Filament\Resources;
use App\Models\Deployment;
use Filament\Resources\Resource;
use Filament\Tables\{Table, Columns as C, Actions as A, Filters as F};
use Illuminate\Database\Eloquent\Model;
class DeploymentResource extends Resource
{
    protected static ?string $model = Deployment::class;
    protected static ?string $navigationIcon = "heroicon-o-command-line";
    protected static ?int $navigationSort = 3;
    public static function canCreate(): bool
    {
        return false;
    }
    public static function canEdit(Model $record): bool
    {
        return false;
    }
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                C\TextColumn::make("id")->label("#")->sortable(),
                C\TextColumn::make("project.name")->searchable(),
                C\TextColumn::make("project.server.name")->label("Server"),
                C\TextColumn::make("kind")->badge(),
                C\TextColumn::make("branch"),
                C\TextColumn::make("commit_hash")->limit(8)->label("Commit"),
                C\TextColumn::make("status")->badge()->color(
                    fn($state) => match ($state) {
                        "success", "rolled_back" => "success",
                        "failed" => "danger",
                        "running" => "warning",
                        default => "gray",
                    },
                ),
                C\TextColumn::make("created_at")->dateTime()->sortable(),
                C\TextColumn::make("duration")->suffix("s"),
            ])
            ->defaultSort("id", "desc")
            ->poll("3s")
            ->filters([
                F\SelectFilter::make("project_id")->relationship(
                    "project",
                    "name",
                ),
                F\SelectFilter::make("status")->options(
                    array_combine(
                        [
                            "pending",
                            "running",
                            "success",
                            "failed",
                            "cancelled",
                            "rolled_back",
                        ],
                        [
                            "Pending",
                            "Running",
                            "Success",
                            "Failed",
                            "Cancelled",
                            "Rolled back",
                        ],
                    ),
                ),
            ])
            ->actions([
                A\Action::make("logs")->url(
                    fn(Deployment $record) => static::getUrl("logs", [
                        "record" => $record,
                    ]),
                ),
            ]);
    }
    public static function getPages(): array
    {
        return [
            "index" => Pages\ListDeployments::route("/"),
            "logs" => Pages\DeploymentLogs::route("/{record}/logs"),
        ];
    }
}
