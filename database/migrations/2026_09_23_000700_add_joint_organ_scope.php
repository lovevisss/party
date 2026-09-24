<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('meeting_scopes')->updateOrInsert(
            ['meeting_type' => 'party_government_joint', 'code' => 'organ'],
            ['name' => '机关党总支', 'display_order' => 0, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now],
        );

        $scopeId = DB::table('meeting_scopes')->where('meeting_type', 'party_government_joint')->where('code', 'organ')->value('id');
        foreach (DB::table('organizations')->whereBetween('external_code', ['100401', '100417'])->pluck('id') as $organizationId) {
            DB::table('meeting_scope_organizations')->insertOrIgnore([
                'meeting_scope_id' => $scopeId,
                'organization_id' => $organizationId,
            ]);
        }
    }

    public function down(): void
    {
        $scopeId = DB::table('meeting_scopes')->where('meeting_type', 'party_government_joint')->where('code', 'organ')->value('id');
        if ($scopeId) {
            foreach (['meeting_minutes', 'role_assignments', 'participant_presets'] as $table) {
                if (DB::table($table)->where('meeting_scope_id', $scopeId)->exists()) {
                    throw new RuntimeException('机关党政联席会议范围已有业务数据，不能回滚该迁移。');
                }
            }
            DB::table('meeting_scope_organizations')->where('meeting_scope_id', $scopeId)->delete();
            DB::table('meeting_scopes')->where('id', $scopeId)->delete();
        }
    }
};
