<?php
namespace App\Filament\Resources;
use Filament\Tables\{Table, Columns as C};
use Illuminate\Database\Eloquent\Model;
class BackupResource extends \Filament\Resources\Resource
{
    protected static ?string $model = \App\Models\Backup::class;
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
                C\TextColumn::make("id"),
                C\TextColumn::make("project.name"),
                C\TextColumn::make("type"),
                C\TextColumn::make("status")->badge(),
                C\TextColumn::make("path")->wrap(),
                C\TextColumn::make("created_at")->dateTime(),
            ])
            ->defaultSort("id", "desc")
            ->poll("5s");
    }
    public static function getPages(): array
    {
        return ["index" => Pages\ListBackups::route("/")];
    }
}
