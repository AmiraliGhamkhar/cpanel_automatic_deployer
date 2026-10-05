<?php
namespace App\Services;
use App\Models\{Server, Project};
use App\Services\Security\Input;
use Illuminate\Support\Facades\{Gate, Validator, DB};
use Illuminate\Validation\Rule;
class ConfigurationService
{
    public function server(array $data, ?Server $record = null): Server
    {
        Gate::authorize(
            $record ? "update" : "create",
            $record ?? Server::class,
        );
        $data = Validator::make($data, [
            "name" => "required|string|max:100",
            "hostname" => [
                "required",
                "regex:/\A[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}\z/D",
            ],
            "port" => ["required", Rule::in([2083])],
            "cpanel_username" => [
                "required",
                "regex:/\A[a-zA-Z][a-zA-Z0-9_-]{0,31}\z/D",
            ],
            "ssh_username" => [
                "nullable",
                "regex:/\A[a-zA-Z][a-zA-Z0-9_-]{0,31}\z/D",
            ],
            "ssh_port" => "required|integer|min:1|max:65535",
            "ssh_host_fingerprint" => [
                "nullable",
                "regex:/\ASHA256:[A-Za-z0-9+\/]{43}\z/D",
            ],
            "cpanel_api_token" => "nullable|string|max:4096",
            "ssh_private_key" => "nullable|string|max:32768",
            "connection_mode" => [
                "required",
                Rule::in(["ssh", "cpanel_api", "cpanel_api_and_ssh"]),
            ],
            "notes" => "nullable|string|max:2000",
            "enabled" => "boolean",
        ])->validate();
        return DB::transaction(function () use ($data, $record) {
            if ($record) {
                $record = Server::lockForUpdate()->findOrFail($record->id);
                if (
                    $record
                        ->projects()
                        ->whereNotNull("active_deployment_id")
                        ->exists()
                ) {
                    throw new \RuntimeException(
                        "A project operation is active.",
                    );
                }
                foreach (["cpanel_api_token", "ssh_private_key"] as $secret) {
                    if (empty($data[$secret])) {
                        unset($data[$secret]);
                    }
                }
                $record->fill($data);
                if (
                    $record->isDirty([
                        "hostname",
                        "port",
                        "connection_mode",
                        "ssh_username",
                        "ssh_port",
                        "ssh_host_fingerprint",
                        "cpanel_api_token",
                        "ssh_private_key",
                    ])
                ) {
                    $record->fill([
                        "capabilities" => null,
                        "last_health_check_at" => null,
                        "status" => "unknown",
                    ]);
                }
                $record->save();
            } else {
                $record = Server::create($data);
            }
            Audit::record("SAVE_SERVER", "success", null, $record->id);
            return $record;
        });
    }
    public function project(array $data, ?Project $record = null): Project
    {
        Gate::authorize(
            $record ? "update" : "create",
            $record ?? Project::class,
        );
        $data = Validator::make($data, [
            "name" => "required|string|max:100",
            "server_id" => "required|exists:servers,id",
            "repository_url" => "required|string|max:255",
            "branch" => "required|string|max:200",
            "project_type" => [
                "required",
                Rule::in(["laravel", "python", "node", "static", "custom"]),
            ],
            "deployment_mode" => [
                "required",
                Rule::in(["ssh", "cpanel_git", "custom"]),
            ],
            "release_strategy" => [
                "required",
                Rule::in(["symlink", "in_place"]),
            ],
            "remote_path" => "required|string|max:200",
            "public_path" => [
                "nullable",
                "regex:~\A(?:public|dist|build|site)?\z~D",
            ],
            "entrypoint" => [
                "nullable",
                "regex:/\A[A-Za-z0-9_-]+\.(?:js|py)\z/D",
            ],
            "health_check_url" => ["required", "url:https", "max:255"],
            "settings" => "array",
            "settings.migrations" => "boolean",
            "settings.build" => "boolean",
            "settings.restart" => ["required", Rule::in(["none", "passenger"])],
            "settings.health_type" => [
                "nullable",
                Rule::in(["http", "tcp", "process"]),
            ],
            "settings.health_port" => "nullable|integer|min:1|max:65535",
            "settings.health_process" => "nullable|string|max:120",
            "settings.health_attempts" => "nullable|integer|min:1|max:5",
            "settings.health_delay" => "nullable|integer|min:0|max:30",
            "enabled" => "boolean",
        ])->validate();
        $server = Server::findOrFail($data["server_id"]);
        Gate::authorize("operate", $server);
        Input::repository($data["repository_url"]);
        Input::branch($data["branch"]);
        Input::path(
            $data["remote_path"],
            $server->ssh_username ?? $server->cpanel_username,
        );
        $url = parse_url($data["health_check_url"]);
        if (
            isset($url["user"]) ||
            isset($url["pass"]) ||
            isset($url["fragment"]) ||
            ($url["port"] ?? 443) !== 443
        ) {
            throw new \RuntimeException(
                "Health URL must use HTTPS port 443 without credentials or fragments.",
            );
        }
        $data["settings"] = array_intersect_key(
            $data["settings"] ?? [],
            array_flip([
                "migrations",
                "build",
                "restart",
                "health_type",
                "health_port",
                "health_process",
                "health_attempts",
                "health_delay",
            ]),
        );
        if (
            ($data["settings"]["health_type"] ?? "http") === "process" &&
            !empty($data["settings"]["health_process"])
        ) {
            Input::processPattern($data["settings"]["health_process"]);
        }
        if (
            ($data["settings"]["health_type"] ?? "http") === "process" &&
            empty($data["settings"]["health_process"])
        ) {
            throw new \RuntimeException(
                "A process health check requires the process pattern to look for.",
            );
        }
        return DB::transaction(function () use ($data, $record) {
            Server::lockForUpdate()->findOrFail($data["server_id"]);
            foreach (
                Project::where("server_id", $data["server_id"])
                    ->where("id", "!=", $record?->id ?? 0)
                    ->pluck("remote_path")
                as $existing
            ) {
                if (
                    $existing === $data["remote_path"] ||
                    str_starts_with($existing, $data["remote_path"] . "/") ||
                    str_starts_with($data["remote_path"], $existing . "/")
                ) {
                    throw new \RuntimeException(
                        "Project directories on the same server must not overlap.",
                    );
                }
            }
            if ($record) {
                $record = Project::lockForUpdate()->findOrFail($record->id);
                if ($record->active_deployment_id !== null) {
                    throw new \RuntimeException(
                        "A project operation is active.",
                    );
                }
                if (
                    $record->deployments()->exists() &&
                    ($record->server_id != (int) $data["server_id"] ||
                        $record->remote_path !== $data["remote_path"])
                ) {
                    throw new \RuntimeException(
                        "Server and path cannot change after a deployment. Create a new project.",
                    );
                }
                $record->update($data);
            } else {
                $record = Project::create($data);
            }
            Audit::record(
                "SAVE_PROJECT",
                "success",
                $record->id,
                $record->server_id,
            );
            return $record;
        });
    }
}
