<?php
namespace App\Services\Remote;
use App\Models\Server;
interface CpanelServiceInterface
{
    public function inspect(Server $server, string $feature): array;
}
