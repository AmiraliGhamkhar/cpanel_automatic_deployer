<?php
return [
    "default" => env("CACHE_STORE", "database"),
    "stores" => [
        "database" => [
            "driver" => "database",
            "table" => "cache",
            "lock_table" => "cache_locks",
            "connection" => null,
        ],
        "array" => ["driver" => "array", "serialize" => false],
    ],
    "prefix" => "cpanel_control_",
];
