<?php
namespace App\Filament\Resources\Pages;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
class CreateProject extends CreateRecord
{
    protected static string $resource = \App\Filament\Resources\ProjectResource::class;
    protected function handleRecordCreation(array $data): Model
    {
        return app(\App\Services\ConfigurationService::class)->project($data);
    }
}
