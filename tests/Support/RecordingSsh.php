<?php

namespace Tests\Support;

use App\Models\Server;
use App\Services\Remote\{Command, SshServiceInterface};

/**
 * Records every allowlisted command the deployment pipeline would execute, so
 * tests can assert ordering and content without a real host.
 */
class RecordingSsh implements SshServiceInterface
{
    /** @var list<Command> */
    public array $commands = [];

    /** @var array<string,string> Output returned by run(), keyed by a substring match. */
    public array $outputs = [];

    public bool $exists = true;

    /** @var list<array{path:string,content:string}> */
    public array $uploads = [];

    public function __construct(private string $defaultOutput = "") {}

    public int $connects = 0;

    public function connect(Server $server): void
    {
        // A connection is not a command; only run()/upload() are recorded.
        $this->connects++;
    }

    public function run(Command $command): string
    {
        $this->commands[] = $command;
        foreach ($this->outputs as $needle => $output) {
            if (str_contains($command->shell, $needle)) {
                return $output;
            }
        }
        return $this->defaultOutput;
    }

    public function exists(string $path): bool
    {
        return $this->exists;
    }

    public function upload(string $path, string $content): void
    {
        $this->uploads[] = ["path" => $path, "content" => $content];
    }

    public function read(string $path): string
    {
        return "";
    }

    /** Shell strings in execution order, excluding uploads. */
    public function shells(): array
    {
        return array_values(
            array_map(
                fn (Command $c) => $c->shell,
                array_filter(
                    $this->commands,
                    fn (Command $c) => !str_starts_with($c->shell, "upload "),
                ),
            ),
        );
    }

    public function indexOf(string $needle): int|false
    {
        foreach ($this->shells() as $index => $shell) {
            if (str_contains($shell, $needle)) {
                return $index;
            }
        }
        return false;
    }

    public function has(string $needle): bool
    {
        return $this->indexOf($needle) !== false;
    }
}
