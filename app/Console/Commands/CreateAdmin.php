<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\User;
class CreateAdmin extends Command
{
    protected $signature = "control:admin {email}";
    protected $description = "Create the personal administrator (password is entered privately)";
    public function handle(): int
    {
        $email = $this->argument("email");
        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            User::where("email", $email)->exists()
        ) {
            $this->error("Invalid or existing email.");
            return 1;
        }
        $password = $this->secret("Password (at least 16 characters)");
        if (strlen($password ?? "") < 16) {
            $this->error("Password must have at least 16 characters.");
            return 1;
        }
        User::create([
            "name" => "Administrator",
            "email" => $email,
            "password" => $password,
            "is_admin" => true,
        ]);
        $this->info("Administrator created.");
        return 0;
    }
}
