<?php
namespace App\Filament\Resources;
use App\Models\Server;
use Filament\Resources\Resource;
use Filament\Forms\{Form, Components as F};
use Filament\Tables\{Table, Columns as C, Actions as A};
use Illuminate\Support\Facades\Gate;
class ServerResource extends Resource
{
    protected static ?string $model = Server::class;
    protected static ?string $navigationIcon = "heroicon-o-server-stack";
    protected static ?int $navigationSort = 1;
    public static function form(Form $form): Form
    {
        return $form->schema([
            F\Section::make("Hosting account")
                ->description(
                    "No root or WHM access required. Use the provider’s valid TLS cPanel hostname.",
                )
                ->schema([
                    F\TextInput::make("name")->required()->maxLength(100),
                    F\TextInput::make("hostname")->required(),
                    F\Select::make("connection_mode")
                        ->options([
                            "cpanel_api" => "cPanel API (inspection only)",
                            "ssh" => "SSH / SFTP",
                            "cpanel_api_and_ssh" => "cPanel API + SSH",
                        ])
                        ->default("cpanel_api_and_ssh")
                        ->required(),
                    F\TextInput::make("port")
                        ->numeric()
                        ->default(2083)
                        ->required(),
                    F\TextInput::make("cpanel_username")->required(),
                    F\Toggle::make("enabled")->default(true),
                ])
                ->columns(2),
            F\Section::make("Write-only credentials")
                ->description(
                    "Blank credentials on edit preserve the saved value. Saved secrets are never loaded into the form.",
                )
                ->schema([
                    F\TextInput::make("cpanel_api_token")
                        ->password()
                        ->autocomplete("new-password")
                        ->afterStateHydrated(
                            fn($component) => $component->state(null),
                        )
                        ->dehydrated(fn($state) => filled($state)),
                    F\TextInput::make("ssh_username"),
                    F\TextInput::make("ssh_port")
                        ->numeric()
                        ->default(22)
                        ->required(),
                    F\TextInput::make("ssh_host_fingerprint")
                        ->label("Verified SHA256 host fingerprint")
                        ->helperText(
                            "Obtain out-of-band from the hosting provider. Never blindly trust a key scan.",
                        ),
                    F\Textarea::make("ssh_private_key")
                        ->rows(3)
                        ->afterStateHydrated(
                            fn($component) => $component->state(null),
                        )
                        ->dehydrated(fn($state) => filled($state))
                        ->extraInputAttributes([
                            "autocomplete" => "off",
                            "spellcheck" => "false",
                        ]),
                ])
                ->columns(2),
            F\Textarea::make("notes")->maxLength(2000)->columnSpanFull(),
        ]);
    }
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                C\TextColumn::make("name")->searchable(),
                C\TextColumn::make("hostname"),
                C\TextColumn::make("connection_mode")->badge(),
                C\TextColumn::make("status")->badge()->color(
                    fn($state) => match ($state) {
                        "online" => "success",
                        "offline" => "danger",
                        default => "gray",
                    },
                ),
                C\TextColumn::make("projects_count")
                    ->counts("projects")
                    ->label("Projects"),
                C\TextColumn::make("last_health_check_at")->since(),
            ])
            ->poll("5s")
            ->actions([
                A\Action::make("test")
                    ->label("Test connection")
                    ->icon("heroicon-o-signal")
                    ->requiresConfirmation()
                    ->action(function (Server $record) {
                        Gate::authorize("operate", $record);
                        \App\Filament\ActionRunner::run(function () use (
                            $record,
                        ) {
                            \App\Jobs\ServerCapabilityCheckJob::dispatch(
                                $record->id,
                                auth()->id(),
                            );
                            \App\Services\Audit::record(
                                "TEST_SERVER",
                                "queued",
                                null,
                                $record->id,
                            );
                        });
                    }),
                A\Action::make("diagnostics")
                    ->icon("heroicon-o-clipboard-document-list")
                    ->modalContent(
                        fn(Server $record) => view("capabilities", [
                            "server" => $record,
                        ]),
                    )
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel("Close"),
                A\EditAction::make(),
            ]);
    }
    public static function getPages(): array
    {
        return [
            "index" => Pages\ListServers::route("/"),
            "create" => Pages\CreateServer::route("/create"),
            "edit" => Pages\EditServer::route("/{record}/edit"),
        ];
    }
}
