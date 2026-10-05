<?php

/**
 * Reusable deployment templates.
 *
 * Each template describes how a project type is detected, what the host must
 * provide, which allowlisted operation templates run (and when), how the
 * application is restarted and how success is verified.
 *
 * `operations` are keys of the allowlisted command templates in
 * App\Services\Remote\Command. Nothing here is user input: a template can only
 * select from commands that already exist in reviewed application code.
 */
return [
    "templates" => [
        "laravel" => [
            "label" => "Laravel",
            "markers" => ["artisan"],
            "requirements" => ["git", "php", "composer", "timeout"],
            "verification" => ["artisan"],
            "steps" => [
                [
                    "title" => "Install Composer dependencies",
                    "operation" => "composer",
                ],
                [
                    "title" => "Prepare Laravel directories",
                    "operation" => "laravel-storage",
                ],
                [
                    "title" => "Clear stale Laravel configuration",
                    "operation" => "laravel-clear",
                ],
                [
                    "title" => "Run explicitly enabled migrations",
                    "operation" => "migrate",
                    "when" => "migrations",
                ],
                [
                    "title" => "Cache Laravel configuration",
                    "operation" => "laravel-cache",
                ],
            ],
            "install_commands" => ["composer"],
            "build_commands" => [],
            "migration_commands" => ["migrate"],
            "restart_strategy" => "passenger",
            "health_check_strategy" => "http",
            "notes" =>
                "Migrations never run unless explicitly enabled, and are never rolled back with a release.",
        ],
        "python" => [
            "label" => "Python",
            "markers" => ["requirements.txt", "pyproject.toml", "poetry.lock", "Pipfile", "manage.py"],
            "requirements" => ["git", "python", "pip", "timeout"],
            "verification" => ["requirements.txt", "pyproject.toml"],
            "steps" => [
                [
                    "title" => "Create virtual environment and install dependencies",
                    "operation" => "python",
                    "operation_alt" => "pyproject",
                    "when_file" => "requirements.txt",
                ],
            ],
            "install_commands" => ["python", "pyproject"],
            "build_commands" => [],
            "migration_commands" => [],
            "restart_strategy" => "passenger",
            "health_check_strategy" => "http",
            "notes" =>
                "Passenger is configured manually in the provider's Application Manager; the panel does not change application registration.",
        ],
        "node" => [
            "label" => "Node.js",
            "markers" => ["package.json"],
            "requirements" => ["git", "node", "npm", "timeout"],
            "verification" => ["package.json"],
            "steps" => [
                ["title" => "Install locked Node dependencies", "operation" => "npm"],
                [
                    "title" => "Build Node assets",
                    "operation" => "build",
                    "when" => "build",
                ],
            ],
            "install_commands" => ["npm"],
            "build_commands" => ["build"],
            "migration_commands" => [],
            "restart_strategy" => "passenger",
            "health_check_strategy" => "http",
            "notes" =>
                "Node.js is not available on every cPanel host; the capability matrix reports the truth.",
        ],
        "static" => [
            "label" => "Static site",
            "markers" => ["index.html"],
            "requirements" => ["git", "timeout"],
            "verification" => [],
            "steps" => [
                [
                    "title" => "Install build dependencies",
                    "operation" => "npm",
                    "when" => "build",
                ],
                [
                    "title" => "Build static assets",
                    "operation" => "build",
                    "when" => "build",
                ],
            ],
            "install_commands" => ["npm"],
            "build_commands" => ["build"],
            "migration_commands" => [],
            "restart_strategy" => "none",
            "health_check_strategy" => "http",
            "notes" =>
                "Point the domain's document root at the current release (or its build directory).",
        ],
        "custom" => [
            "label" => "Custom",
            "markers" => [],
            "requirements" => [],
            "verification" => [],
            "steps" => [],
            "install_commands" => [],
            "build_commands" => [],
            "migration_commands" => [],
            "restart_strategy" => "none",
            "health_check_strategy" => "http",
            "notes" =>
                "No deployment is possible until a reviewed strategy is implemented in application code. Arbitrary commands from the UI are prohibited.",
        ],
    ],
];
