<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('active_number_key', 16)->nullable()->default('active');
            $table->dropUnique('minutes_scope_number_unique');
            $table->unique(['meeting_scope_id', 'meeting_type', 'meeting_year', 'sequence_no', 'active_number_key'], 'minutes_scope_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->dropUnique('minutes_scope_number_unique');
            $table->unique(['meeting_scope_id', 'meeting_type', 'meeting_year', 'sequence_no'], 'minutes_scope_number_unique');
            $table->dropColumn(['active_number_key', 'deleted_at']);
        });
    }
};
