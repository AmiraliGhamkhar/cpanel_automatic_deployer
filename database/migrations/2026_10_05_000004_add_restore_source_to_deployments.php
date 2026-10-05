<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A file restore is a deployment whose payload is a backup archive. */
    public function up(): void
    {
        Schema::table("deployments", function (Blueprint $t) {
            $t->unsignedBigInteger("source_backup_id")
                ->nullable()
                ->after("target_deployment_id");
        });
    }

    public function down(): void
    {
        Schema::table("deployments", function (Blueprint $t) {
            $t->dropColumn("source_backup_id");
        });
    }
};
