<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('role_assignments', 'position_label')) {
            Schema::table('role_assignments', function (Blueprint $table) {
                $table->string('position_label', 32)->nullable()->after('organization_id');
                $table->string('scope_key', 40)->default('global')->after('position_label');
            });
        }

        DB::table('role_assignments')->whereNotNull('organization_id')->orderBy('id')->each(function (object $assignment): void {
            DB::table('role_assignments')->where('id', $assignment->id)->update(['scope_key' => 'org:'.$assignment->organization_id]);
        });

        Schema::table('role_assignments', function (Blueprint $table) {
            $table->unique(['user_id', 'role', 'scope_key'], 'role_scope_key_unique');
            $table->dropUnique('role_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropUnique('role_scope_key_unique');
            $table->unique(['user_id', 'role', 'organization_id'], 'role_scope_unique');
            $table->dropColumn(['position_label', 'scope_key']);
        });
    }
};
