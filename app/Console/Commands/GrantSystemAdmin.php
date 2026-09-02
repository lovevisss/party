<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Person;
use App\Services\AuthorizationService;
use Illuminate\Console\Command;

class GrantSystemAdmin extends Command
{
    protected $signature = 'minutes:grant-admin {employee_no}';

    protected $description = 'Grant system administrator role';

    public function handle(AuthorizationService $authorizations): int
    {
        $p = Person::where('employee_no', $this->argument('employee_no'))->first();
        if (! $p) {
            $this->error('未找到已同步人员。');

            return self::FAILURE;
        }
        $authorizations->grant($p, UserRole::SystemAdmin, null, null);
        $this->info('系统管理员授权成功。');

        return self::SUCCESS;
    }
}
