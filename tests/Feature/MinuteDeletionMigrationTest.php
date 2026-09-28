<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('minute deletion migration resumes after columns were added but the old unique index remains', function () {
    $migration = require database_path('migrations/2026_09_28_000900_add_soft_deletes_to_meeting_minutes.php');
    $migration->down();
    Schema::table('meeting_minutes', function (Blueprint $table): void {
        $table->dropIndex('minutes_meeting_scope_id_fk_index');
        $table->softDeletes();
        $table->string('active_number_key', 16)->nullable()->default('active');
    });

    $migration->up();
    $migration->up();

    $indexes = collect(Schema::getIndexes('meeting_minutes'))->keyBy('name');
    expect(Schema::hasColumn('meeting_minutes', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('meeting_minutes', 'active_number_key'))->toBeTrue()
        ->and($indexes->has('minutes_meeting_scope_id_fk_index'))->toBeTrue()
        ->and($indexes->get('minutes_scope_number_unique')['columns'])->toBe([
            'meeting_scope_id', 'meeting_type', 'meeting_year', 'sequence_no', 'active_number_key',
        ]);
});
