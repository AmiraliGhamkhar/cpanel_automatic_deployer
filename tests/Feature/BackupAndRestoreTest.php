<?php

namespace Tests\Feature;

use App\Models\{Backup, Project, Server, User};
use App\Services\Backup\{BackupTypeRegistry, DatabaseCredentials, RestoreService};
use App\Services\Remote\SshServiceInterface;
use App\Services\{HealthCheckEngine, HealthCheckResult};
use App\Jobs\{BackupProjectJob, DeployProjectJob};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\RecordingSsh;
use Tests\TestCase;

class BackupAndRestoreTest extends TestCase
{
    use RefreshDatabase;

    private RecordingSsh $ssh;

    private User $user;

    private function project(array $environment = []): Project
    {
        $this->user = User::create([
            "name" => "Admin",
            "email" => "admin@example.com",
            "password" => "long-testing-password",
            "is_admin" => true,
        ]);
        $server = Server::create([
            "name" => "Target",
            "hostname" => "host.example.com",
            "cpanel_username" => "demo",
            "ssh_username" => "demo",
            "connection_mode" => "ssh",
            "last_health_check_at" => now(),
            "capabilities" => array_fill_keys(
                ["ssh", "sftp", "symlink", "tar", "git", "timeout", "mysqldump"],
                ["status" => "available"],
            ),
        ]);
        $project = Project::create([
            "name" => "Site",
            "server_id" => $server->id,
            "repository_url" => "https://github.com/example/site",
            "branch" => "main",
            "project_type" => "static",
            "remote_path" => "/home/demo/apps/site",
            "health_check_url" => "https://example.com/health",
            "environment_config" => $environment,
            "settings" => ["restart" => "none"],
        ]);
        // A previous successful release must exist for maintenance operations.
        $project->update(["current_deployment_id" => $project->deployments()->create([
            "triggered_by" => $this->user->id,
            "branch" => "main",
            "status" => "success",
            "release_path" => "/home/demo/apps/site/releases/1",
            "rollback_available" => true,
        ])->id]);

        $this->ssh = new RecordingSsh();
        $this->app->instance(SshServiceInterface::class, $this->ssh);
        app()->instance(BackupTypeRegistry::class, new BackupTypeRegistry());

        return $project;
    }

    private function runBackup(Project $project, Backup $backup, string $token): void
    {
        $project->update([
            "active_deployment_id" => 0,
            "active_operation_token" => $token,
        ]);
        new BackupProjectJob($backup->id, $this->user->id, $token)->handle(
            app(BackupTypeRegistry::class),
            $this->ssh,
        );
    }

    public function test_database_dump_uses_a_protected_option_file_and_never_a_command_line_password(): void
    {
        $project = $this->project([
            "DB_DATABASE" => "demo_app",
            "DB_USERNAME" => "demo_user",
            "DB_PASSWORD" => "s3cret-db-password",
            "DB_HOST" => "localhost",
        ]);
        $backup = $project->backups()->create(["type" => "database"]);
        $this->runBackup($project, $backup, "token-1");

        $backup->refresh();
        $this->assertSame("success", $backup->status);
        $this->assertSame("demo_app", $backup->database_name);
        $this->assertSame(
            $project->remote_path . "/backups/database-" . $backup->id . ".sql.gz",
            $backup->path,
        );

        $this->assertCount(1, $this->ssh->uploads);
        $this->assertStringEndsWith(
            "/shared/.mysql-" . $backup->id . ".cnf",
            $this->ssh->uploads[0]["path"],
        );
        $this->assertStringContainsString(
            "password=\"s3cret-db-password\"",
            $this->ssh->uploads[0]["content"],
        );

        $dump = $this->ssh->shells()[0];
        $this->assertStringContainsString("mysqldump --defaults-extra-file=", $dump);
        $this->assertStringContainsString("--single-transaction", $dump);
        $this->assertStringContainsString("trap 'rm -f", $dump);
        $this->assertStringNotContainsString("--password=", $dump);
        $this->assertStringNotContainsString("sudo", $dump);

        // The lock is released and the operation is audited.
        $this->assertNull($project->fresh()->active_deployment_id);
        $this->assertDatabaseHas("audit_logs", ["action" => "BACKUP_PROJECT"]);
    }

    public function test_database_backup_is_refused_without_mysqldump_or_credentials(): void
    {
        $project = $this->project(["DB_DATABASE" => "demo_app"]);
        $backup = $project->backups()->create(["type" => "database"]);
        $this->runBackup($project, $backup, "token-2");
        // DB_USERNAME is missing: credentials cannot be built.
        $this->assertSame("failed", $backup->fresh()->status);

        $project2 = $this->project([
            "DB_DATABASE" => "demo_app",
            "DB_USERNAME" => "demo_user",
            "DB_PASSWORD" => "pw",
        ]);
        $project2->server->update([
            "capabilities" => array_fill_keys(
                ["ssh", "sftp", "symlink", "git", "timeout"],
                ["status" => "available"],
            ),
        ]);
        $backup2 = $project2->backups()->create(["type" => "database"]);
        $this->runBackup($project2, $backup2, "token-3");
        $this->assertSame("failed", $backup2->fresh()->status);
    }

    public function test_password_with_quotes_is_rejected_instead_of_corrupting_the_option_file(): void
    {
        $credentials = new DatabaseCredentials(
            "demo_app",
            "demo_user",
            'pass"word',
            "localhost",
        );
        $this->assertFalse($credentials->passwordValid());
        $this->expectException(\InvalidArgumentException::class);
        $credentials->optionFile();
    }

    public function test_database_url_is_parsed_and_fingerprinted(): void
    {
        $credentials = DatabaseCredentials::fromEnvironment([
            "DATABASE_URL" => "mysql://demo_user:p%40ss@db.example.com:3306/demo_app",
        ]);
        $this->assertSame("demo_app", $credentials->database);
        $this->assertSame("demo_user", $credentials->username);
        $this->assertSame("p@ss", $credentials->password);
        $this->assertSame("db.example.com", $credentials->host);

        $same = DatabaseCredentials::fromEnvironment([
            "DATABASE_URL" => "mysql://demo_user:p%40ss@db.example.com:3306/demo_app",
        ]);
        $other = DatabaseCredentials::fromEnvironment([
            "DATABASE_URL" => "mysql://demo_user:other@db.example.com:3306/demo_app",
        ]);
        $this->assertSame($credentials->fingerprint(), $same->fingerprint());
        $this->assertNotSame($credentials->fingerprint(), $other->fingerprint());
    }

    public function test_file_restore_deploys_the_archive_as_a_new_release(): void
    {
        $project = $this->project();
        $backup = $project->backups()->create([
            "type" => "files",
            "status" => "success",
            "path" => $project->remote_path . "/backups/7.tar.gz",
            "metadata" => ["branch" => "main"],
        ]);

        $github = Mockery::mock(\App\Services\Remote\GitHubService::class);
        $github->shouldReceive("commit")->never();
        $this->app->instance(\App\Services\Remote\GitHubService::class, $github);
        $health = Mockery::mock(HealthCheckEngine::class);
        $health->shouldReceive("check")->andReturn(
            HealthCheckResult::pass("HTTPS request returned HTTP 200."),
        );
        $this->app->instance(HealthCheckEngine::class, $health);

        $deployment = app(RestoreService::class)->asDeployment(
            $project,
            $this->user,
            $backup,
            true,
        );
        $this->assertSame("restore", $deployment->kind);
        $this->assertSame($backup->id, $deployment->source_backup_id);

        new DeployProjectJob($deployment->id)->handle(app(\App\Services\Deployment\DeploymentService::class));

        $deployment->refresh();
        $this->assertSame("success", $deployment->status);
        $this->assertSame(
            $project->remote_path . "/releases/" . $deployment->id,
            $deployment->release_path,
        );
        $this->assertTrue($this->ssh->has("tar -xzf"));
        $this->assertTrue($this->ssh->has("/backups/" . $backup->id . ".tar.gz"));
        $this->assertTrue($this->ssh->has("mv -Tf -- .current-next current"));
        $this->assertDatabaseHas("audit_logs", [
            "action" => "RESTORE_BACKUP",
            "result" => "success",
        ]);
    }

    public function test_restore_requires_confirmation_and_a_successful_backup(): void
    {
        $project = $this->project();
        $backup = $project->backups()->create([
            "type" => "files",
            "status" => "success",
        ]);

        try {
            app(RestoreService::class)->asDeployment(
                $project,
                $this->user,
                $backup,
                false,
            );
            $this->fail("Restore without confirmation was accepted.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("confirmation", $e->getMessage());
        }

        $failed = $project->backups()->create(["type" => "files", "status" => "failed"]);
        $this->expectException(\RuntimeException::class);
        app(RestoreService::class)->asDeployment(
            $project,
            $this->user,
            $failed,
            true,
        );
    }

    public function test_delete_removes_the_archive_and_audits(): void
    {
        $project = $this->project();
        $backup = $project->backups()->create([
            "type" => "files",
            "status" => "success",
            "path" => $project->remote_path . "/backups/9.tar.gz",
        ]);

        app(RestoreService::class)->delete(
            $project,
            $this->user,
            $backup,
            true,
            $this->ssh,
        );

        $this->assertDatabaseMissing("backups", ["id" => $backup->id]);
        $this->assertTrue($this->ssh->has("rm -f -- '" . $project->remote_path . "/backups/9.tar.gz'"));
        $this->assertDatabaseHas("audit_logs", [
            "action" => "DELETE_BACKUP",
            "result" => "success",
        ]);
    }
}
