<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_assignments', fn (Blueprint $table) => $table->string('scope_key', 80)->change());
        DB::table('role_assignments')->where('role', 'minute_manager')
            ->whereNull('deleted_at')->whereNull('meeting_scope_id')
            ->orderBy('id')->chunkById(100, function ($assignments): void {
                foreach ($assignments as $assignment) {
                    $person = DB::table('users')->join('people', 'people.id', '=', 'users.person_id')
                        ->where('users.id', $assignment->user_id)
                        ->first(['people.id', 'people.employee_no', 'people.organization_id', 'people.status']);
                    $scopeId = $person && $person->status === 'active'
                        ? DB::table('meeting_scopes')->join('meeting_scope_organizations', 'meeting_scope_organizations.meeting_scope_id', '=', 'meeting_scopes.id')
                            ->where('meeting_scopes.meeting_type', $assignment->meeting_type)
                            ->where('meeting_scopes.is_active', true)
                            ->where('meeting_scope_organizations.organization_id', $person->organization_id)
                            ->orderBy('meeting_scopes.id')->value('meeting_scopes.id')
                        : null;

                    if (! $scopeId) {
                        DB::table('role_assignments')->where('id', $assignment->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
                        Log::warning('会议管理员旧授权未映射，已停用并需重新授权。', [
                            'assignment_id' => $assignment->id, 'user_id' => $assignment->user_id,
                            'employee_no' => $person?->employee_no, 'meeting_type' => $assignment->meeting_type,
                        ]);

                        continue;
                    }

                    $key = 'meeting:'.$assignment->meeting_type.':scope:'.$scopeId;
                    $duplicate = DB::table('role_assignments')->where('user_id', $assignment->user_id)
                        ->where('role', 'minute_manager')->where('scope_key', $key)->where('id', '!=', $assignment->id)->first();
                    if ($duplicate) {
                        DB::table('role_assignments')->where('id', $duplicate->id)->update([
                            'meeting_type' => $assignment->meeting_type, 'meeting_scope_id' => $scopeId,
                            'deleted_at' => null, 'updated_at' => now(),
                        ]);
                        DB::table('role_assignments')->where('id', $assignment->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
                    } else {
                        DB::table('role_assignments')->where('id', $assignment->id)->update([
                            'meeting_scope_id' => $scopeId, 'scope_key' => $key, 'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Narrowing an authorization is intentionally not reversed.
    }
};
