<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $branchId = DB::table('meeting_scopes')->where('meeting_type', 'party_branch')->where('code', 'organ')->value('id');
        $jointId = DB::table('meeting_scopes')->where('meeting_type', 'party_government_joint')->where('code', 'organ')->value('id');

        if (! $branchId || ! $jointId) {
            throw new RuntimeException('机关会议范围不存在，请先执行前置迁移。');
        }

        foreach (DB::table('meeting_scope_organizations')->where('meeting_scope_id', $branchId)->pluck('organization_id') as $organizationId) {
            DB::table('meeting_scope_organizations')->insertOrIgnore([
                'meeting_scope_id' => $jointId,
                'organization_id' => $organizationId,
            ]);
        }
    }

    public function down(): void
    {
        // 已有党政联席授权可能依赖这些关联，回滚时保留数据。
    }
};
