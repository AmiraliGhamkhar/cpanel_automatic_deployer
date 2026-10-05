<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Last health probe result, shown on the project page and dashboard. */
    public function up(): void
    {
        Schema::table("projects", function (Blueprint $t) {
            $t->timestamp("health_checked_at")->nullable()->after("status");
            $t->string("health_check_message", 500)
                ->nullable()
                ->after("health_checked_at");
        });
    }

    public function down(): void
    {
        Schema::table("projects", function (Blueprint $t) {
            $t->dropColumn(["health_checked_at", "health_check_message"]);
        });
    }
};
