<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Makes deployments diagnosable: the failed step and its sanitized output
     * are stored separately from the human-readable failure summary.
     */
    public function up(): void
    {
        Schema::table("deployments", function (Blueprint $t) {
            $t->string("failure_step", 200)->nullable()->after("failure_reason");
            $t->text("failure_detail")->nullable()->after("failure_step");
        });
    }

    public function down(): void
    {
        Schema::table("deployments", function (Blueprint $t) {
            $t->dropColumn(["failure_step", "failure_detail"]);
        });
    }
};
