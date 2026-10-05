<?php

namespace App\Filament\Resources;

use App\Filament\ActionRunner;
use App\Models\{Backup, Project};
use App\Services\Audit;
use App\Services\Backup\RestoreService;
use App\Services\Remote\SshServiceInterface;
use Filament\Forms\Components as F;
use Filament\Resources\Resource;
use Filament\Tables\{Actions as A, Columns as C, Table};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class BackupResource extends Resource
{
    protected static ?string $model = Backup::class;

    protected static ?string $navigationIcon = "heroicon-o-archive-box";

    protected static ?int $navigationSort = 4;

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
                C\TextColumn::make("id"),
                C\TextColumn::make("project.name"),
                C\TextColumn::make("type")->badge(),
                C\TextColumn::make("database_name"),
                C\TextColumn::make("status")->badge()->color(
                    fn($state) => match ($state) {
                        "success" => "success",
                        "failed" => "danger",
                        default => "gray",
                    },
                ),
                C\TextColumn::make("path")->wrap()->toggleable(),
                C\TextColumn::make("created_at")->dateTime(),
            ])
            ->defaultSort("id", "desc")
            ->poll("5s")
            ->actions([
                A\Action::make("restore")
                    ->label("Restore")
                    ->color("warning")
                    ->visible(
                        fn(Backup $record) => $record->status === "success",
                    )
                    ->modalHeading("Restore this backup")
                    ->modalDescription(
                        "A file archive is deployed as a new release, so the previous release stays available for rollback. A database dump overwrites the live database and cannot be undone by a release rollback.",
                    )
                    ->form([
                        F\Checkbox::make("confirmed")
                            ->label("I confirm this restore")
                            ->accepted()
                            ->required(),
                    ])
                    ->action(function (Backup $record, array $data) {
                        $project = $record->project;
                        Gate::authorize("operate", $project);
                        ActionRunner::run(function () use (
                            $record,
                            $project,
                            $data,
                        ) {
                            $restore = app(RestoreService::class);
                            if ($record->type === "files") {
                                $restore->asDeployment(
                                    $project,
                                    auth()->user(),
                                    $record,
                                    (bool) $data["confirmed"],
                                );
                                return;
                            }
                            app(\App\Services\ProjectOperations::class)->claimMaintenance(
                                $project,
                                auth()->user(),
                                function (string $token) use (
                                    $restore,
                                    $project,
                                    $record,
                                    $data,
                                ) {
                                    $restore->database(
                                        $project,
                                        auth()->user(),
                                        $record,
                                        (bool) $data["confirmed"],
                                        $token,
                                    );
                                },
                            );
                        });
                    }),
                A\Action::make("delete")
                    ->label("Delete")
                    ->color("danger")
                    ->icon("heroicon-o-trash")
                    ->requiresConfirmation()
                    ->modalDescription(
                        "Deletes the record and the archive on the host. Deployment history is kept.",
                    )
                    ->form([
                        F\Checkbox::make("confirmed")
                            ->label("Delete this archive permanently")
                            ->accepted()
                            ->required(),
                    ])
                    ->action(function (Backup $record, array $data) {
                        Gate::authorize("operate", $record->project);
                        ActionRunner::run(
                            fn() => app(RestoreService::class)->delete(
                                $record->project,
                                auth()->user(),
                                $record,
                                (bool) $data["confirmed"],
                                app(SshServiceInterface::class),
                            ),
                        );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ["index" => Pages\ListBackups::route("/")];
    }
}
