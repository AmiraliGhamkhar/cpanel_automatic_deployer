<?php
namespace App\Filament\Resources\Pages;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;
class ListProjects extends ListRecords
{
    protected static string $resource = \App\Filament\Resources\ProjectResource::class;
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
