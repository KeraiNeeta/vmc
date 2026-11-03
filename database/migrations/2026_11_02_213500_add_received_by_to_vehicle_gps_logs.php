<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_gps_logs', function (Blueprint $table): void {
            $table->foreignId('received_by')->nullable()->after('job_assignment_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_gps_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('received_by');
        });
    }
};
