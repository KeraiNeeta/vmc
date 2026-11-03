<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_jobs', function (Blueprint $table): void {
            $table->string('society_name')->nullable()->after('ward_id');
        });
    }

    public function down(): void
    {
        Schema::table('emergency_jobs', function (Blueprint $table): void {
            $table->dropColumn('society_name');
        });
    }
};
