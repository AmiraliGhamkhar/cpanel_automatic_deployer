<?php
namespace App\Filament\Resources\Pages;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
class CreateServer extends CreateRecord
{
    protected static string $resource = \App\Filament\Resources\ServerResource::class;
    protected function handleRecordCreation(array $data): Model
    {
        return app(\App\Services\ConfigurationService::class)->server($data);
    }
}
