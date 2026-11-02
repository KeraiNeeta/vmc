<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_station_id')->constrained()->restrictOnDelete();
            $table->string('vehicle_number', 40)->unique();
            $table->string('vehicle_type', 40);
            $table->string('registration_number', 40)->nullable()->unique();
            $table->string('gps_device_id', 100)->nullable()->unique();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 30)->default('AVAILABLE')->index();
            $table->timestampTz('last_service_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['fire_station_id', 'status']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fire_station_id')->constrained()->restrictOnDelete();
            $table->foreignId('leader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('contact_number', 32)->nullable();
            $table->string('status', 30)->default('AVAILABLE')->index();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['fire_station_id', 'name']);
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('designation', 100)->nullable();
            $table->string('qualification', 150)->nullable();
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->timestampsTz();
            $table->primary(['team_id', 'user_id']);
            $table->index(['user_id', 'team_id']);
        });

        Schema::create('emergency_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id', 40)->unique();
            $table->string('caller_name');
            $table->string('caller_phone', 32);
            $table->string('call_source', 40)->default('PHONE');
            $table->string('incident_type', 60);
            $table->string('priority', 20)->default('NORMAL')->index();
            $table->timestampTz('occurred_at')->index();
            $table->foreignId('ward_id')->nullable()->constrained()->nullOnDelete();
            $table->text('incident_address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 40)->default('RECEIVED')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['status', 'occurred_at']);
            $table->index(['ward_id', 'status']);
        });

        Schema::create('job_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_job_id')->constrained()->cascadeOnDelete();
            $table->string('call_reference', 100)->nullable()->unique();
            $table->string('caller_name');
            $table->string('caller_phone', 32);
            $table->string('call_source', 40)->default('PHONE');
            $table->timestampTz('received_at')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['emergency_job_id', 'received_at']);
        });

        Schema::create('job_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fire_station_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_leader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->index();
            $table->timestampTz('released_at')->nullable();
            $table->string('status', 30)->default('ASSIGNED')->index();
            $table->text('remarks')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();
            $table->index(['emergency_job_id', 'status']);
            $table->index(['vehicle_id', 'status']);
        });

        Schema::create('job_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_assignment_id')->nullable()->constrained('job_assignments')->nullOnDelete();
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at')->index();
            $table->text('remarks')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->index(['emergency_job_id', 'changed_at']);
        });

        Schema::create('job_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestampsTz();
            $table->index(['emergency_job_id', 'created_at']);
        });

        Schema::create('vehicle_gps_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_assignment_id')->nullable()->constrained('job_assignments')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('speed_kph', 6, 2)->nullable();
            $table->timestampTz('recorded_at')->index();
            $table->index(['vehicle_id', 'recorded_at']);
        });

        Schema::create('job_external_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_job_id')->constrained()->cascadeOnDelete();
            $table->string('reference_type', 40);
            $table->string('reference_number', 100);
            $table->text('details')->nullable();
            $table->timestampTz('transferred_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['reference_type', 'reference_number']);
            $table->index(['emergency_job_id', 'reference_type']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->morphs('notifiable');
            $table->string('type');
            $table->text('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('job_external_references');
        Schema::dropIfExists('vehicle_gps_logs');
        Schema::dropIfExists('job_remarks');
        Schema::dropIfExists('job_status_history');
        Schema::dropIfExists('job_assignments');
        Schema::dropIfExists('job_call_logs');
        Schema::dropIfExists('emergency_jobs');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('vehicles');
    }
};
