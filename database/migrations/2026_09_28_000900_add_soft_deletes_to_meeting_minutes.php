<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('meeting_minutes', 'deleted_at')) {
            Schema::table('meeting_minutes', fn (Blueprint $table) => $table->softDeletes());
        }
        if (! Schema::hasColumn('meeting_minutes', 'active_number_key')) {
            Schema::table('meeting_minutes', fn (Blueprint $table) => $table->string('active_number_key', 16)->nullable()->default('active'));
        }

        // MySQL may use the old unique index to enforce the meeting_scope_id foreign key.
        // Give that foreign key its own index before replacing the unique index.
        if (! $this->hasIndex('minutes_meeting_scope_id_fk_index')) {
            Schema::table('meeting_minutes', fn (Blueprint $table) => $table->index('meeting_scope_id', 'minutes_meeting_scope_id_fk_index'));
        }

        $numberIndex = $this->numberIndex();
        if (in_array('active_number_key', $numberIndex['columns'] ?? [], true)) {
            return;
        }
        if ($numberIndex !== null) {
            Schema::table('meeting_minutes', fn (Blueprint $table) => $table->dropUnique('minutes_scope_number_unique'));
        }
        Schema::table('meeting_minutes', fn (Blueprint $table) => $table->unique(
            ['meeting_scope_id', 'meeting_type', 'meeting_year', 'sequence_no', 'active_number_key'],
            'minutes_scope_number_unique',
        ));
    }

    public function down(): void
    {
        if (Schema::hasColumn('meeting_minutes', 'deleted_at') && DB::table('meeting_minutes')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Cannot roll back the meeting minute deletion migration while deleted minutes exist.');
        }
        $numberIndex = $this->numberIndex();
        if ($numberIndex !== null && in_array('active_number_key', $numberIndex['columns'] ?? [], true)) {
            Schema::table('meeting_minutes', fn (Blueprint $table) => $table->dropUnique('minutes_scope_number_unique'));
            $numberIndex = null;
        }
        if ($numberIndex === null) {
            Schema::table('meeting_minutes', fn (Blueprint $table) => $table->unique(
                ['meeting_scope_id', 'meeting_type', 'meeting_year', 'sequence_no'],
                'minutes_scope_number_unique',
            ));
        }
        if (Schema::hasColumn('meeting_minutes', 'active_number_key')) {
            Schema::table('meeting_minutes', fn (Blueprint $table) => $table->dropColumn('active_number_key'));
        }
        if (Schema::hasColumn('meeting_minutes', 'deleted_at')) {
            Schema::table('meeting_minutes', fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }

    /** @return array<string, mixed>|null */
    private function numberIndex(): ?array
    {
        return collect(Schema::getIndexes('meeting_minutes'))
            ->first(fn (array $index): bool => ($index['name'] ?? null) === 'minutes_scope_number_unique');
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('meeting_minutes'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
