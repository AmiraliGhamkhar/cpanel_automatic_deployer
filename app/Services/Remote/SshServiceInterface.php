<?php
namespace App\Services\Remote;
use App\Models\Server;
interface SshServiceInterface
{
    public function connect(Server $server): void;
    public function run(Command $command): string;
    public function exists(string $path): bool;
    public function upload(string $path, string $content): void;
    public function read(string $path): string;
}
