<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('meeting_type', 32)->default('party_committee');
            $table->unsignedSmallInteger('meeting_year')->nullable();
            $table->unsignedSmallInteger('sequence_no')->nullable();
            $table->string('title', 200)->nullable();
            $table->dateTime('meeting_start_at')->nullable();
            $table->dateTime('meeting_end_at')->nullable();
            $table->longText('first_topic_content')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedInteger('current_version')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('due_at')->nullable();
            $table->boolean('is_overdue')->nullable()->index();
            $table->dateTime('archived_at')->nullable()->index();
            $table->dateTime('resubmitted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'meeting_type', 'meeting_year', 'sequence_no'], 'minutes_number_unique');
            $table->index(['organization_id', 'status', 'meeting_start_at'], 'minutes_scope_status_date');
        });

        Schema::create('minute_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('meeting_minute_id');
            $table->foreign('meeting_minute_id')->references('id')->on('meeting_minutes')->restrictOnDelete();
            $table->foreignId('person_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role_type', 16)->index();
            $table->string('display_name', 100);
            $table->boolean('is_external')->default(false);
            $table->timestamps();
        });

        Schema::create('minute_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('meeting_minute_id');
            $table->foreign('meeting_minute_id')->references('id')->on('meeting_minutes')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->json('snapshot');
            $table->dateTime('due_at');
            $table->boolean('is_overdue');
            $table->foreignId('archived_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('archived_at');
            $table->timestamps();
            $table->unique(['meeting_minute_id', 'version_no']);
        });

        Schema::create('minute_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('meeting_minute_id');
            $table->foreign('meeting_minute_id')->references('id')->on('meeting_minutes')->restrictOnDelete();
            $table->foreignId('minute_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_no')->nullable();
            $table->string('original_name');
            $table->string('object_key')->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 20)->default('not_configured');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('return_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('meeting_minute_id');
            $table->foreign('meeting_minute_id')->references('id')->on('meeting_minutes')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->text('reason');
            $table->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('returned_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_records');
        Schema::dropIfExists('minute_files');
        Schema::dropIfExists('minute_versions');
        Schema::dropIfExists('minute_participants');
        Schema::dropIfExists('meeting_minutes');
    }
};
