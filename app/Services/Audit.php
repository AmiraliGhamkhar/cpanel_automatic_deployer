<?php
namespace App\Services;
use App\Models\AuditLog;
class Audit
{
    public static function record(
        string $action,
        string $result = "success",
        ?int $project = null,
        ?int $server = null,
        ?int $user = null,
    ): void {
        AuditLog::create([
            "user_id" => $user ?? auth()->id(),
            "action" => $action,
            "result" => $result,
            "project_id" => $project,
            "server_id" => $server,
            "ip" => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
