<?php
return [
    "default" => "database",
    "connections" => [
        "database" => [
            "driver" => "database",
            "connection" => null,
            "table" => "jobs",
            "queue" => "default",
            "retry_after" => 2100,
            "after_commit" => false,
        ],
    ],
    "failed" => [
        "driver" => "database-uuids",
        "database" => env("DB_CONNECTION", "pgsql"),
        "table" => "failed_jobs",
    ],
];
