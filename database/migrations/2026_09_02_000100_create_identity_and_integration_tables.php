<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('external_code', 100)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 100)->unique();
            $table->string('employee_no', 100)->unique();
            $table->string('cas_account', 100)->nullable()->unique();
            $table->string('name', 100);
            $table->string('email')->nullable();
            $table->string('mobile', 100)->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('cas_account', 100)->nullable()->unique()->after('email');
            $table->boolean('is_active')->default(true)->after('cas_account');
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 32)->index();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['user_id', 'role', 'organization_id'], 'role_scope_unique');
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50)->default('middata');
            $table->string('status', 20)->default('running')->index();
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('deactivated_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 40)->index();
            $table->string('status', 20)->default('preview');
            $table->json('payload');
            $table->json('errors')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('role_assignments');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('person_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['cas_account', 'is_active']));
        Schema::dropIfExists('people');
        Schema::dropIfExists('organizations');
    }
};
