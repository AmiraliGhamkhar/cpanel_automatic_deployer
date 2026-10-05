<?php
namespace App\Filament\Resources\Pages;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;
class ListServers extends ListRecords
{
    protected static string $resource = \App\Filament\Resources\ServerResource::class;
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
