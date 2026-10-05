<?php
return [
    "driver" => env("SESSION_DRIVER", "database"),
    "lifetime" => (int) env("SESSION_LIFETIME", 30),
    "expire_on_close" => true,
    "encrypt" => true,
    "files" => storage_path("framework/sessions"),
    "connection" => null,
    "table" => "sessions",
    "store" => null,
    "lottery" => [2, 100],
    "cookie" => "control_session",
    "path" => "/",
    "domain" => null,
    "secure" => env("SESSION_SECURE_COOKIE", true),
    "http_only" => true,
    "same_site" => "strict",
    "partitioned" => false,
];
