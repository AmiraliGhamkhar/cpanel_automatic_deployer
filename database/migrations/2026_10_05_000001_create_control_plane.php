<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create("users", function (Blueprint $t) {
            $t->id();
            $t->string("name");
            $t->string("email")->unique();
            $t->string("password");
            $t->boolean("is_admin")->default(false);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create("sessions", function (Blueprint $t) {
            $t->string("id")->primary();
            $t->foreignId("user_id")->nullable()->index();
            $t->string("ip_address", 45)->nullable();
            $t->text("user_agent")->nullable();
            $t->longText("payload");
            $t->integer("last_activity")->index();
        });
        Schema::create("cache", function (Blueprint $t) {
            $t->string("key")->primary();
            $t->mediumText("value");
            $t->integer("expiration");
        });
        Schema::create("cache_locks", function (Blueprint $t) {
            $t->string("key")->primary();
            $t->string("owner");
            $t->integer("expiration");
        });
        Schema::create("jobs", function (Blueprint $t) {
            $t->id();
            $t->string("queue")->index();
            $t->longText("payload");
            $t->unsignedTinyInteger("attempts");
            $t->unsignedInteger("reserved_at")->nullable();
            $t->unsignedInteger("available_at");
            $t->unsignedInteger("created_at");
        });
        Schema::create("failed_jobs", function (Blueprint $t) {
            $t->id();
            $t->string("uuid")->unique();
            $t->text("connection");
            $t->text("queue");
            $t->longText("payload");
            $t->longText("exception");
            $t->timestamp("failed_at")->useCurrent();
        });
        Schema::create("servers", function (Blueprint $t) {
            $t->id();
            $t->string("name");
            $t->string("hostname");
            $t->unsignedSmallInteger("port")->default(2083);
            $t->string("cpanel_username");
            $t->text("cpanel_api_token")->nullable();
            $t->string("ssh_username")->nullable();
            $t->text("ssh_private_key")->nullable();
            $t->string("ssh_host_fingerprint")->nullable();
            $t->unsignedSmallInteger("ssh_port")->default(22);
            $t->string("connection_mode")->default("cpanel_api");
            $t->text("notes")->nullable();
            $t->boolean("enabled")->default(true);
            $t->timestamp("last_health_check_at")->nullable();
            $t->string("status")->default("unknown");
            $t->json("capabilities")->nullable();
            $t->timestamps();
        });
        Schema::create("projects", function (Blueprint $t) {
            $t->id();
            $t->foreignId("server_id")->constrained()->restrictOnDelete();
            $t->string("name");
            $t->string("repository_url");
            $t->string("repository_provider")->default("github");
            $t->string("branch")->default("main");
            $t->string("project_type");
            $t->string("deployment_mode")->default("ssh");
            $t->string("release_strategy")->default("symlink");
            $t->string("remote_path");
            $t->unique(["server_id", "remote_path"]);
            $t->string("public_path")->nullable();
            $t->string("entrypoint")->nullable();
            $t->longText("environment_config")->nullable();
            $t->string("health_check_url");
            $t->json("settings")->nullable();
            $t->boolean("enabled")->default(true);
            $t->unsignedBigInteger("active_deployment_id")->nullable()->index();
            $t->uuid("active_operation_token")->nullable();
            $t->unsignedBigInteger("current_deployment_id")->nullable();
            $t->string("status")->default("unknown");
            $t->timestamps();
        });
        Schema::create("deployments", function (Blueprint $t) {
            $t->id();
            $t->foreignId("project_id")->constrained()->restrictOnDelete();
            $t->foreignId("triggered_by")
                ->constrained("users")
                ->restrictOnDelete();
            $t->string("kind")->default("deploy");
            $t->unsignedBigInteger("target_deployment_id")->nullable();
            $t->string("commit_hash", 40)->nullable()->index();
            $t->string("branch");
            $t->string("status")->default("pending")->index();
            $t->timestamp("started_at")->nullable();
            $t->timestamp("finished_at")->nullable();
            $t->unsignedInteger("duration")->nullable();
            $t->string("release_path")->nullable();
            $t->string("previous_release_path")->nullable();
            $t->longText("log_output")->nullable();
            $t->string("failure_reason")->nullable();
            $t->boolean("rollback_available")->default(false);
            $t->timestamps();
            $t->index(["project_id", "created_at"]);
        });
        Schema::create("backups", function (Blueprint $t) {
            $t->id();
            $t->foreignId("project_id")->constrained()->restrictOnDelete();
            $t->string("type")->default("files");
            $t->string("path")->nullable();
            $t->string("database_name")->nullable();
            $t->unsignedBigInteger("size")->nullable();
            $t->string("status")->default("pending");
            $t->json("metadata")->nullable();
            $t->timestamps();
        });
        Schema::create("audit_logs", function (Blueprint $t) {
            $t->id();
            $t->foreignId("user_id")->nullable()->constrained()->nullOnDelete();
            $t->foreignId("project_id")
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $t->foreignId("server_id")
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $t->string("action");
            $t->string("ip", 45)->nullable();
            $t->string("result");
            $t->timestamp("created_at")->useCurrent()->index();
        });
    }
    public function down(): void
    {
        foreach (
            [
                "audit_logs",
                "backups",
                "deployments",
                "projects",
                "servers",
                "failed_jobs",
                "jobs",
                "cache_locks",
                "cache",
                "sessions",
                "users",
            ]
            as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
