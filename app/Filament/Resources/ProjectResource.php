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
                            "cpanel_git" => "cPanel Git Version Control (no releases, no rollback)",
                            "custom" => "Custom (not supported yet)",
                        ])
                        ->default("ssh")
                        ->required(),
                    F\Select::make("release_strategy")
                        ->options([
                            "symlink" => "Atomic symlink releases (preferred)",
                            "in_place" => "In-place copy (hosts that do not follow symlinks)",
                        ])
                        ->default("symlink")
                        ->helperText(
                            "In-place releases archive the live directory before overwriting it, so rollback can copy an older release back.",
                        )
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
                            "Mandatory public HTTPS endpoint. The host is also the target of TCP and process checks.",
                        ),
                    F\Select::make("settings.health_type")
                        ->label("Health check type")
                        ->options([
                            "http" => "HTTPS request (expects HTTP 200)",
                            "tcp" => "TCP connect to the health host",
                            "process" => "Application process on the host (SSH)",
                        ])
                        ->default("http")
                        ->required(),
                    F\TextInput::make("settings.health_port")
                        ->label("TCP port (TCP checks only)")
                        ->numeric()
                        ->helperText(
                            "Leave empty to use the port from the health URL, or 443.",
                        ),
                    F\TextInput::make("settings.health_process")
                        ->label("Process pattern (process checks only)")
                        ->helperText(
                            "A literal fragment of the process command line, e.g. passenger_wsgi or node app.js. Letters, digits, spaces, dots, slashes, underscores and hyphens only.",
                        ),
                    F\TextInput::make("settings.health_attempts")
                        ->label("Health attempts (1-5)")
                        ->numeric()
                        ->default(3),
                    F\TextInput::make("settings.health_delay")
                        ->label("Seconds between attempts (0-30)")
                        ->numeric()
                        ->default(2),
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
                C\TextColumn::make("health_checked_at")
                    ->label("Last health")
                    ->since()
                    ->placeholder("Never")
                    ->description(
                        fn(Project $record) => $record->health_check_message,
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
                            ->required()
                            ->live(onBlur: true),
                        F\Select::make("commit")
                            ->label("Commit")
                            ->options(function (Project $record, callable $get) {
                                if ($record->deployment_mode !== "ssh") {
                                    return [];
                                }
                                try {
                                    return app(
                                        \App\Services\Remote\GitHubService::class,
                                    )->recentCommits(
                                        $record->repository_url,
                                        (string) ($get("branch") ?: $record->branch),
                                    );
                                } catch (\Throwable) {
                                    // The GitHub API may be rate limited or
                                    // unreachable; the field simply stays empty.
                                    return [];
                                }
                            })
                            ->searchable()
                            ->placeholder("Latest commit on the branch")
                            ->helperText(
                                "Loaded from GitHub. Leave empty to deploy the latest commit on the branch.",
                            )
                            ->visible(
                                fn(Project $record) => $record->deployment_mode === "ssh",
                            ),
                        F\TextInput::make("manual_commit")
                            ->label("Or paste a full 40-character commit SHA")
                            ->helperText(
                                "Overrides the selection above. Full lowercase SHA only.",
                            )
                            ->visible(
                                fn(Project $record) => $record->deployment_mode === "ssh",
                            ),
                        F\Checkbox::make("confirmed")
                            ->label(
                                "Deploy trusted repository code; migrations may run if enabled.",
                            )
                            ->accepted()
                            ->required(),
                    ])
                    ->modalDescription(
                        fn(Project $record) => $record->deployment_mode === "cpanel_git"
                            ? "cPanel Git mode pulls the repository with cPanel's Git Version Control API and runs the repository's .cpanel.yml tasks. A commit cannot be pinned and there is no release to roll back to."
                            : null,
                    )
                    ->requiresConfirmation()
                    ->action(function (Project $record, array $data) {
                        ActionRunner::run(
                            fn() => app(
                                \App\Services\Deployment\DeploymentService::class,
                            )->trigger(
                                $record,
                                auth()->user(),
                                $data["branch"],
                                ($data["manual_commit"] ?? null) ?: ($data["commit"] ?: null),
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
                        ->visible(
                            fn(Project $record) => $record->deployment_mode === "ssh",
                        )
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
                        ->visible(
                            fn(Project $record) => $record->deployment_mode === "ssh",
                        )
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
                        ->visible(
                            fn(Project $record) => $record->deployment_mode === "ssh",
                        )
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
                        ->visible(
                            fn(Project $record) => $record->deployment_mode === "ssh",
                        )
                        ->form([
                            F\Select::make("type")
                                ->label("What to back up")
                                ->options([
                                    "files" => "Release files (tar.gz on the host)",
                                    "database" => "Database dump (mysqldump, needs DB_* variables)",
                                ])
                                ->default("files")
                                ->required(),
                            F\Checkbox::make("confirmed")
                                ->label(
                                    "Confirm backup. File archives exclude .env and .git; database dumps need credentials in the project environment.",
                                )
                                ->accepted()
                                ->required(),
                        ])
                        ->action(function (Project $record, array $data) {
                            ActionRunner::run(
                                fn() => app(
                                    \App\Services\ProjectOperations::class,
                                )->maintenance(
                                    $record,
                                    auth()->user(),
                                    "backup",
                                    (bool) $data["confirmed"],
                                    (string) $data["type"],
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
                            try {
                                if ($record->active_deployment_id !== null) {
                                    throw new \RuntimeException(
                                        "Project is busy.",
                                    );
                                }
                                $detected = app(
                                    \App\Services\Remote\GitHubService::class,
                                )->detectWithReason(
                                    $record->repository_url,
                                    $record->branch,
                                );
                                $record->update(["project_type" => $detected["type"]]);
                                \App\Services\Audit::record(
                                    "DETECT_PROJECT_TYPE",
                                    "success",
                                    $record->id,
                                    $record->server_id,
                                );
                                \Filament\Notifications\Notification::make()
                                    ->title("Detected: " . $detected["type"])
                                    ->body(
                                        $detected["marker"]
                                            ? "Matched repository file: " . $detected["marker"] . ". Override it in Settings if needed."
                                            : "No known project marker was found; review the type manually.",
                                    )
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title("Detection failed")
                                    ->body(
                                        $e instanceof \RuntimeException ||
                                        $e instanceof \InvalidArgumentException
                                            ? $e->getMessage()
                                            : "The GitHub API could not be queried.",
                                    )
                                    ->danger()
                                    ->send();
                            }
                        }),
                    A\Action::make("releaseLock")
                        ->label("Release stuck operation")
                        ->icon("heroicon-o-lock-open")
                        ->color("danger")
                        ->visible(
                            fn(Project $record) => $record->active_deployment_id !== null,
                        )
                        ->modalHeading("Release the active operation lock")
                        ->modalDescription(
                            "Use this only when a worker died and the operation is stuck. A deployment that is still running will be marked failed and interrupted; a deployment that is still healthy is not rolled back. Stale operations are released automatically after 45 minutes.",
                        )
                        ->form([
                            F\Checkbox::make("confirmed")
                                ->label(
                                    "I understand this marks the active deployment failed and releases the lock.",
                                )
                                ->accepted()
                                ->required(),
                        ])
                        ->action(function (Project $record, array $data) {
                            Gate::authorize("operate", $record);
                            ActionRunner::run(function () use ($record, $data) {
                                if (!$data["confirmed"]) {
                                    throw new \RuntimeException(
                                        "Confirmation required.",
                                    );
                                }
                                app(
                                    \App\Services\Operations\StaleOperationReaper::class,
                                )->reap($record, true, auth()->id());
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
