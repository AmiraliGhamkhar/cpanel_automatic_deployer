<?php
namespace App\Filament\Resources\Pages;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
class EditProject extends EditRecord
{
    protected static string $resource = \App\Filament\Resources\ProjectResource::class;
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(\App\Services\ConfigurationService::class)->project(
            $data,
            $record,
        );
    }
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset(
            $data["cpanel_api_token"],
            $data["ssh_private_key"],
            $data["environment_config"],
        );
        return $data;
    }
}
