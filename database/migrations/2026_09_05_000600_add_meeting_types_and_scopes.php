<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $partyBranchNames = [
        '100301' => '金融与经贸学院党总支',
        '100302' => '财税学院党总支',
        '100303' => '工商管理学院党总支',
        '100304' => '会计学院党总支',
        '100305' => '信息与人工智能学院党总支',
        '100306' => '法律与社会工作学院、马克思主义学院党总支',
        '100307' => '文化传播与设计学院党总支',
        '100308' => '外国语学院党总支',
    ];

    /** @var array<int, string> */
    private array $jointNames = [
        '100301' => '金融与经贸学院',
        '100302' => '财税学院',
        '100303' => '工商管理学院',
        '100304' => '会计学院',
        '100305' => '信息与人工智能学院',
        '100306' => '法律与社会工作学院、马克思主义学院',
        '100307' => '文化传播与设计学院',
        '100308' => '外国语学院',
        '100309' => '创业学院、继续教育学院',
        '100310' => '体育部',
    ];

    public function up(): void
    {
        if (Schema::hasTable('meeting_scopes')) {
            $this->migrateLegacyRecords();
            $this->completeIndexes();

            return;
        }
        $this->assertLegacyOrganizationsAreMappable();

        Schema::create('meeting_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('meeting_type', 40)->index();
            $table->string('code', 60);
            $table->string('name', 150);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['meeting_type', 'code']);
        });

        Schema::create('meeting_scope_organizations', function (Blueprint $table) {
            $table->foreignId('meeting_scope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->primary(['meeting_scope_id', 'organization_id'], 'meeting_scope_org_primary');
        });

        $this->seedScopes();
        $this->syncOrganizationMappings();

        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->foreignId('meeting_scope_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
        });
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->string('meeting_type', 40)->nullable()->after('role')->index();
            $table->foreignId('meeting_scope_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
        });
        Schema::table('participant_presets', function (Blueprint $table) {
            $table->string('meeting_type', 40)->nullable()->after('organization_id')->index();
            $table->foreignId('meeting_scope_id')->nullable()->after('meeting_type')->constrained()->restrictOnDelete();
        });

        $this->migrateLegacyRecords();

        $this->completeIndexes();
    }

    public function down(): void
    {
        Schema::table('participant_presets', function (Blueprint $table) {
            $table->dropUnique('participant_presets_context_name_unique');
            $table->unique(['user_id', 'organization_id', 'name'], 'participant_presets_owner_name_unique');
            $table->dropConstrainedForeignId('meeting_scope_id');
            $table->dropColumn('meeting_type');
        });
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meeting_scope_id');
            $table->dropColumn('meeting_type');
        });
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->dropUnique('minutes_scope_number_unique');
            $table->unique(['organization_id', 'meeting_type', 'meeting_year', 'sequence_no'], 'minutes_number_unique');
            $table->dropConstrainedForeignId('meeting_scope_id');
        });
        Schema::dropIfExists('meeting_scope_organizations');
        Schema::dropIfExists('meeting_scopes');
    }

    private function assertLegacyOrganizationsAreMappable(): void
    {
        $usedOrganizationIds = DB::table('meeting_minutes')->pluck('organization_id')
            ->merge(DB::table('role_assignments')->where('role', 'college_submitter')->pluck('organization_id'))
            ->merge(DB::table('participant_presets')->pluck('organization_id'))
            ->filter()->unique();
        $unmapped = DB::table('organizations')->whereIn('id', $usedOrganizationIds)
            ->whereNot(function ($query) {
                $query->whereBetween('external_code', ['100301', '100310'])
                    ->orWhereBetween('external_code', ['100401', '100417']);
            })->pluck('external_code');
        if ($unmapped->isNotEmpty()) {
            throw new RuntimeException('旧数据存在无法映射的单位：'.$unmapped->implode('、'));
        }
    }

    private function seedScopes(): void
    {
        $now = now();
        $rows = [
            ['meeting_type' => 'party_branch', 'code' => 'organ', 'name' => '机关党总支', 'display_order' => 1],
            ['meeting_type' => 'party_branch', 'code' => 'joint', 'name' => '联合党总支', 'display_order' => 2],
        ];
        $order = 3;
        foreach ($this->partyBranchNames as $externalCode => $name) {
            $rows[] = ['meeting_type' => 'party_branch', 'code' => 'branch-'.$externalCode, 'name' => $name, 'display_order' => $order++];
        }
        $order = 1;
        foreach ($this->jointNames as $externalCode => $name) {
            $rows[] = ['meeting_type' => 'party_government_joint', 'code' => 'college-'.$externalCode, 'name' => $name, 'display_order' => $order++];
        }
        DB::table('meeting_scopes')->insert(array_map(fn (array $row): array => [...$row, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], $rows));
    }

    private function syncOrganizationMappings(): void
    {
        foreach (DB::table('organizations')->get(['id', 'external_code']) as $organization) {
            foreach ($this->scopeKeysForExternalCode((string) $organization->external_code) as [$type, $code]) {
                $scopeId = DB::table('meeting_scopes')->where('meeting_type', $type)->where('code', $code)->value('id');
                DB::table('meeting_scope_organizations')->updateOrInsert(['meeting_scope_id' => $scopeId, 'organization_id' => $organization->id]);
            }
        }
    }

    private function migrateLegacyRecords(): void
    {
        foreach (DB::table('meeting_minutes')->get(['id', 'organization_id']) as $minute) {
            DB::table('meeting_minutes')->where('id', $minute->id)->update(['meeting_type' => 'party_branch', 'meeting_scope_id' => $this->partyBranchScopeId((int) $minute->organization_id)]);
        }
        foreach (DB::table('participant_presets')->get(['id', 'organization_id']) as $preset) {
            DB::table('participant_presets')->where('id', $preset->id)->update(['meeting_type' => 'party_branch', 'meeting_scope_id' => $this->partyBranchScopeId((int) $preset->organization_id)]);
        }
        foreach (DB::table('role_assignments')->get(['id', 'role', 'organization_id']) as $assignment) {
            if ($assignment->role === 'system_admin') {
                DB::table('role_assignments')->where('id', $assignment->id)->update(['meeting_type' => null, 'meeting_scope_id' => null, 'position_label' => null, 'scope_key' => 'global']);
            } elseif ($assignment->role === 'school_manager') {
                DB::table('role_assignments')->where('id', $assignment->id)->update(['role' => 'minute_manager', 'meeting_type' => 'party_branch', 'meeting_scope_id' => null, 'position_label' => null, 'scope_key' => 'meeting:party_branch:global']);
            } elseif ($assignment->role === 'college_submitter') {
                $scopeId = $this->partyBranchScopeId((int) $assignment->organization_id);
                DB::table('role_assignments')->where('id', $assignment->id)->update(['role' => 'minute_submitter', 'meeting_type' => 'party_branch', 'meeting_scope_id' => $scopeId, 'position_label' => null, 'scope_key' => 'meeting:party_branch:scope:'.$scopeId]);
            }
        }
    }

    private function completeIndexes(): void
    {
        if (! $this->indexExists('meeting_minutes', 'minutes_scope_number_unique')) {
            Schema::table('meeting_minutes', function (Blueprint $table) {
                if ($this->indexExists('meeting_minutes', 'minutes_number_unique')) {
                    $table->dropUnique('minutes_number_unique');
                }
                $table->unique(['meeting_scope_id', 'meeting_type', 'meeting_year', 'sequence_no'], 'minutes_scope_number_unique');
            });
        }
        if (! $this->indexExists('participant_presets', 'participant_presets_context_name_unique')) {
            Schema::table('participant_presets', function (Blueprint $table) {
                $table->index('user_id', 'participant_presets_user_id_index');
                $table->dropUnique('participant_presets_owner_name_unique');
                $table->unique(['user_id', 'meeting_type', 'meeting_scope_id', 'name'], 'participant_presets_context_name_unique');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $item): bool => ($item['name'] ?? null) === $index);
    }

    private function partyBranchScopeId(int $organizationId): int
    {
        $externalCode = (string) DB::table('organizations')->where('id', $organizationId)->value('external_code');
        [$type, $scopeCode] = $this->scopeKeysForExternalCode($externalCode)[0];

        return (int) DB::table('meeting_scopes')->where('meeting_type', $type)->where('code', $scopeCode)->value('id');
    }

    /** @return list<array{string,string}> */
    private function scopeKeysForExternalCode(string $externalCode): array
    {
        $partyBranchCode = match (true) {
            $externalCode >= '100401' && $externalCode <= '100417' => 'organ',
            in_array($externalCode, ['100309', '100310'], true) => 'joint',
            isset($this->partyBranchNames[$externalCode]) => 'branch-'.$externalCode,
            default => null,
        };
        $keys = $partyBranchCode ? [['party_branch', $partyBranchCode]] : [];
        if (isset($this->jointNames[$externalCode])) {
            $keys[] = ['party_government_joint', 'college-'.$externalCode];
        }

        return $keys;
    }
};
