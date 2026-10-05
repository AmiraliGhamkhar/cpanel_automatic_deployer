<?php
namespace App\Filament\Resources;
use Filament\Tables\{Table, Columns as C};
use Illuminate\Database\Eloquent\Model;
class AuditLogResource extends \Filament\Resources\Resource
{
    protected static ?string $model = \App\Models\AuditLog::class;
    protected static ?string $navigationIcon = "heroicon-o-archive-box";
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
                C\TextColumn::make("created_at")->dateTime(),
                C\TextColumn::make("user.email")->label("User"),
                C\TextColumn::make("action")->searchable(),
                C\TextColumn::make("project.name"),
                C\TextColumn::make("server.name"),
                C\TextColumn::make("ip"),
                C\TextColumn::make("result")->badge(),
            ])
            ->defaultSort("id", "desc")
            ->poll("5s");
    }
    public static function getPages(): array
    {
        return ["index" => Pages\ListAuditLogs::route("/")];
    }
}
