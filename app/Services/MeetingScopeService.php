<?php

namespace App\Services;

use App\Enums\MeetingType;
use App\Models\MeetingScope;
use App\Models\Organization;
use App\Models\Person;
use Illuminate\Support\Collection;

class MeetingScopeService
{
    /** @return array<int, string> */
    public static function partyBranchMappings(): array
    {
        return [
            '100301' => '金融与经贸学院党总支', '100302' => '财税学院党总支',
            '100303' => '工商管理学院党总支', '100304' => '会计学院党总支',
            '100305' => '信息与人工智能学院党总支', '100306' => '法律与社会工作学院、马克思主义学院党总支',
            '100307' => '文化传播与设计学院党总支', '100308' => '外国语学院党总支',
            '100309' => '联合党总支', '100310' => '联合党总支',
            '100401' => '机关党总支', '100402' => '机关党总支', '100403' => '机关党总支',
            '100404' => '机关党总支', '100405' => '机关党总支', '100406' => '机关党总支',
            '100407' => '机关党总支', '100408' => '机关党总支', '100409' => '机关党总支',
            '100410' => '机关党总支', '100411' => '机关党总支', '100412' => '机关党总支',
            '100413' => '机关党总支', '100414' => '机关党总支', '100415' => '机关党总支',
            '100416' => '机关党总支', '100417' => '机关党总支',
        ];
    }

    /** @return Collection<int, MeetingScope> */
    public function scopes(MeetingType $type): Collection
    {
        return MeetingScope::query()->where('meeting_type', $type->value)->where('is_active', true)->orderBy('display_order')->get();
    }

    public function syncOrganizationMappings(): void
    {
        $scopes = MeetingScope::query()->get()->keyBy(fn (MeetingScope $scope) => $scope->meeting_type->value.'|'.$scope->name);
        foreach (Organization::query()->get(['id', 'external_code', 'name']) as $organization) {
            $branchName = self::partyBranchMappings()[$organization->external_code] ?? null;
            if ($branchName && ($scope = $scopes->get(MeetingType::PartyBranch->value.'|'.$branchName))) {
                $scope->organizations()->syncWithoutDetaching([$organization->id]);
            }
            if ($branchName === '机关党总支' && ($scope = $scopes->get(MeetingType::PartyGovernmentJoint->value.'|机关党总支'))) {
                $scope->organizations()->syncWithoutDetaching([$organization->id]);
            }
            if (preg_match('/^10030[1-9]$|^100310$/', $organization->external_code)) {
                $scope = $scopes->get(MeetingType::PartyGovernmentJoint->value.'|'.$organization->name);
                $scope?->organizations()->syncWithoutDetaching([$organization->id]);
            }
        }
    }

    public function scopeForPerson(Person $person, MeetingType $type): ?MeetingScope
    {
        return MeetingScope::query()->where('meeting_type', $type->value)->where('is_active', true)
            ->whereHas('organizations', fn ($query) => $query->whereKey($person->organization_id))->first();
    }

    /** @return list<int> */
    public function organizationIds(MeetingScope $scope): array
    {
        return array_values($scope->organizations()->pluck('organizations.id')->map(fn ($id): int => (int) $id)->all());
    }
}
