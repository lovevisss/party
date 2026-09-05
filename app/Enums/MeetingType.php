<?php

namespace App\Enums;

enum MeetingType: string
{
    case PartyBranch = 'party_branch';
    case PartyGovernmentJoint = 'party_government_joint';

    public function label(): string
    {
        return match ($this) {
            self::PartyBranch => '党总支会议纪要',
            self::PartyGovernmentJoint => '党政联席会议纪要',
        };
    }

    public function slug(): string
    {
        return match ($this) {
            self::PartyBranch => 'party-branch',
            self::PartyGovernmentJoint => 'party-government-joint',
        };
    }

    public function scopeLabel(): string
    {
        return $this === self::PartyBranch ? '党总支' : '学院';
    }

    public static function fromSlug(string $slug): self
    {
        return match ($slug) {
            'party-branch' => self::PartyBranch,
            'party-government-joint' => self::PartyGovernmentJoint,
            default => abort(404),
        };
    }
}
