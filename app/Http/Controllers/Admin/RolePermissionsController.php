<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class RolePermissionsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/RolePermissions', [
            'roles' => [
                ['name' => '会议提交人', 'scope' => '本人在授权会议范围内的纪要', 'draft' => true, 'return' => false, 'delete' => false, 'admin' => false],
                ['name' => '会议管理员', 'scope' => '所管理会议范围内的纪要', 'draft' => false, 'return' => true, 'delete' => false, 'admin' => false],
                ['name' => '全局管理员', 'scope' => '全部已归档终稿 · 当前版本', 'draft' => false, 'return' => false, 'delete' => false, 'admin' => false],
                ['name' => '系统管理员', 'scope' => '全部已归档终稿 · 当前版本', 'draft' => false, 'return' => true, 'delete' => true, 'admin' => true],
            ],
        ]);
    }
}
