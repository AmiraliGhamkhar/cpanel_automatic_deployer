<?php
namespace App\Filament\Resources;
use App\Models\{Project, Deployment};
use Filament\Resources\Resource;
use Filament\Forms\{Form, Components as F};
use Filament\Tables\{Table, Columns as C, Actions as A};
use Illuminate\Support\Facades\Gate;
use App\Filament\ActionRunner;
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;
    protected static ?string $navigationIcon = "heroicon-o-folder";
    protected static ?int $navigationSort = 2;
    public static function form(Form $form): Form
    {
        return $form->schema([
            F\Section::make("Project")
                ->schema([
                    F\TextInput::make("name")->required()->maxLength(100),
                    F\Select::make("server_id")
                        ->relationship("server", "name")
                        ->required()
                        ->searchable()
                        ->preload(),
                    F\TextInput::make("repository_url")
                        ->label("GitHub HTTPS repository")
                        ->required()
                        ->helperText(
                            "Public repositories only for remote clones. Repository build scripts execute as your hosting account user.",
                        ),
                    F\TextInput::make("branch")->default("main")->required(),
                    F\Select::make("project_type")
                        ->options([
                            "laravel" => "Laravel",
                            "python" => "Python",
                            "node" => "Node.js",
                            "static" => "Static",
                            "custom" => "Custom (requires reviewed strategy)",
                        ])
                        ->required(),
                    F\Toggle::make("enabled")->default(true),
                ])
                ->columns(2),
            F\Section::make("Hosting configuration")
                ->description(
                    "Configure your cPanel document root or Passenger application to use the current symlink. The panel does not change domain mappings.",
                )
                ->schema([
                    F\Select::make("deployment_mode")
                        ->options([
                            "ssh" => "SSH",
                            "cpanel_git" => "cPanel Git (not supported yet)",
                            "custom" => "Custom (not supported yet)",
                        ])
                        ->default("ssh")
                        ->required(),
                    F\Select::make("release_strategy")
                        ->options([
                            "symlink" => "Atomic symlink releases",
                            "in_place" => "In-place (blocked for safety)",
                        ])
                        ->default("symlink")
                        ->required(),
                    F\TextInput::make("remote_path")
                        ->required()
                        ->placeholder("/home/account/apps/myproject")
                        ->helperText(
                            "Dedicated empty directory; not public_html. The panel never takes over existing files.",
                        ),
                    F\Select::make("public_path")
                        ->options([
                            "public" => "public (Laravel)",
                            "dist" => "dist",
                            "build" => "build",
                            "site" => "site",
                        ])
                        ->helperText(
                            "Provider document root hint, relative to current. Configure it in cPanel.",
                        ),
                    F\TextInput::make("entrypoint")->helperText(
                        "Provider startup file hint, e.g. app.js or passenger_wsgi.py; configure in Application Manager.",
                    ),
                    F\TextInput::make("health_check_url")
                        ->url()
                        ->required()
                        ->placeholder("https://example.com/health")
                        ->helperText(
                            "Mandatory public HTTPS endpoint returning HTTP 200. Redirects are rejected.",
                        ),
                    F\Select::make("settings.restart")
                        ->options([
                            "none" => "None (PHP/static)",
                            "passenger" => "Passenger restart marker",
                        ])
                        ->default("none")
                        ->required(),
                    F\Toggle::make("settings.build")
                        ->label("Run npm build (requires Node/npm)")
                        ->default(false),
                    F\Toggle::make("settings.migrations")
                        ->label("Explicitly enable Laravel migrations")
                        ->helperText(
                            "Database changes are not undone by a release rollback.",
                        )
                        ->default(false),
                ])
                ->columns(2),
        ]);
    }
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                C\TextColumn::make("name")->searchable(),
                C\TextColumn::make("server.name")->label("Server"),
                C\TextColumn::make("project_type")->badge(),
                C\TextColumn::make("branch"),
                C\TextColumn::make("status")->badge()->color(
                    fn($state) => match ($state) {
                        "running" => "success",
                        "failed" => "danger",
                        default => "gray",
                    },
                ),
                C\TextColumn::make("active_deployment_id")
                    ->label("Operation")
                    ->formatStateUsing(
                        fn($state) => $state === 0
                            ? "Maintenance"
                            : "#" . $state,
                    ),
            ])
            ->poll("5s")
            ->actions([
                A\Action::make("deploy")
                    ->color("primary")
                    ->icon("heroicon-o-rocket-launch")
                    ->form([
                        F\TextInput::make("branch")
                            ->default(fn(Project $record) => $record->branch)
                            ->required(),
                        F\TextInput::make("commit")->label(
                            "Commit SHA (blank = latest branch commit)",
                        ),
                        F\Checkbox::make("confirmed")
                            ->label(
                                "Deploy trusted repository code; migrations may run if enabled.",
                            )
                            ->accepted()
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Project $record, array $data) {
                        ActionRunner::run(
                            fn() => app(
                                \App\Services\Deployment\DeploymentService::class,
                            )->trigger(
                                $record,
                                auth()->user(),
                                $data["branch"],
                                $data["commit"] ?: null,
                                (bool) $data["confirmed"],
                            ),
                        );
                    }),
                A\ActionGroup::make([
                    A\Action::make("history")
                        ->url(
                            fn(Project $record) => DeploymentResource::getUrl(
                                "index",
                                [
                                    "tableFilters" => [
                                        "project_id" => [
                                            "value" => $record->id,
                                        ],
                                    ],
                                ],
                            ),
                        )
                        ->icon("heroicon-o-clock"),
                    A\Action::make("environment")
                        ->modalHeading("Write-only environment variables")
                        ->modalDescription(
                            "Values never return to the browser. Changes apply on the next deployment or supported restart. Laravel cached configuration requires a new deployment.",
                        )
                        ->form([
                            F\Placeholder::make("keys")
                                ->label("Saved keys (all values masked)")
                                ->content(
                                    fn(Project $record) => implode(
                                        ", ",
                                        array_map(
                                            fn($key) => $key . " = ********",
                                            array_keys(
                                                $record->environment_config ??
                                                    [],
                                            ),
                                        ),
                                    ) ?:
                                    "No variables",
                                ),
                            F\TextInput::make("key")->required(),
                            F\TextInput::make("value")
                                ->password()
                                ->autocomplete("new-password"),
                            F\Toggle::make("delete")->default(false),
                            F\Checkbox::make("confirmed")
                                ->label("Confirm environment change")
                                ->accepted()
                                ->required(),
                        ])
                        ->action(function (Project $record, array $data) {
                            ActionRunner::run(
                                fn() => app(
                                    \App\Services\ProjectOperations::class,
                                )->environment(
                                    $record,
                                    auth()->user(),
                                    $data["key"],
                                    $data["value"] ?? "",
                                    (bool) $data["delete"],
                                    (bool) $data["confirmed"],
                                ),
                            );
                        }),
                    A\Action::make("rollback")
                        ->color("warning")
                        ->form([
                            F\Select::make("target")
                                ->label("Previous verified release")
                                ->options(
                                    fn(Project $record) => $record
                                        ->deployments()
                                        ->whereIn("status", [
                                            "success",
                                            "rolled_back",
                                        ])
                                        ->where("rollback_available", true)
                                        ->where(
                                            "id",
                                            "!=",
                                            $record->current_deployment_id ?? 0,
                                        )
                                        ->latest()
                                        ->get()
                                        ->mapWithKeys(
                                            fn($d) => [
                                                $d->id =>
                                                    "#" .
                                                    $d->id .
                                                    " · " .
                                                    substr(
                                                        $d->commit_hash ?? "",
                                                        0,
                                                        8,
                                                    ) .
                                                    " · " .
                                                    $d->status,
                                            ],
                                        ),
                                )
                                ->required(),
                            F\Checkbox::make("confirmed")
                                ->label(
                                    "Switch release; database and environment values will not be rolled back.",
                                )
                                ->accepted()
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->action(function (Project $record, array $data) {
                            ActionRunner::run(
                                fn() => app(
                                    \App\Services\Deployment\RollbackDeploymentService::class,
                                )->trigger(
                                    $record,
                                    auth()->user(),
                                    (int) $data["target"],
                                    (bool) $data["confirmed"],
                                ),
                            );
                        }),
                    A\Action::make("restart")
                        ->requiresConfirmation()
                        ->modalDescription(
                            "Rewrite the shared environment, touch the Passenger restart marker and verify health.",
                        )
                        ->action(function (Project $record) {
                            ActionRunner::run(
                                fn() => app(
                                    \App\Services\ProjectOperations::class,
                                )->maintenance(
                                    $record,
                                    auth()->user(),
                                    "restart",
                                    true,
                                ),
                            );
                        }),
                    A\Action::make("backup")
                        ->requiresConfirmation()
                        ->modalDescription(
                            "Archive the active release on this host, excluding .env and .git. Does not include databases or shared uploads. No automatic restore.",
                        )
                        ->action(function (Project $record) {
                            ActionRunner::run(
                                fn() => app(
                                    \App\Services\ProjectOperations::class,
                                )->maintenance(
                                    $record,
                                    auth()->user(),
                                    "backup",
                                    true,
                                ),
                            );
                        }),
                    A\Action::make("health")
                        ->label("Health check")
                        ->action(function (Project $record) {
                            Gate::authorize("operate", $record);
                            ActionRunner::run(
                                fn() => \App\Jobs\HealthCheckJob::dispatch(
                                    $record->id,
                                    auth()->id(),
                                ),
                            );
                        }),
                    A\Action::make("detect")
                        ->label("Detect project type")
                        ->requiresConfirmation()
                        ->action(function (Project $record) {
                            Gate::authorize("update", $record);
                            ActionRunner::run(function () use ($record) {
                                if ($record->active_deployment_id !== null) {
                                    throw new \RuntimeException(
                                        "Project is busy.",
                                    );
                                }
                                $type = app(
                                    \App\Services\Remote\GitHubService::class,
                                )->detect(
                                    $record->repository_url,
                                    $record->branch,
                                );
                                $record->update(["project_type" => $type]);
                                \App\Services\Audit::record(
                                    "DETECT_PROJECT_TYPE",
                                    "success",
                                    $record->id,
                                    $record->server_id,
                                );
                            });
                        }),
                    A\EditAction::make(),
                ]),
            ]);
    }
    public static function getPages(): array
    {
        return [
            "index" => Pages\ListProjects::route("/"),
            "create" => Pages\CreateProject::route("/create"),
            "edit" => Pages\EditProject::route("/{record}/edit"),
        ];
    }
}
