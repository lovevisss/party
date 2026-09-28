<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $r, AuditLogPresenter $presenter): Response
    {
        $filters = $r->validate(['category' => ['nullable', Rule::in(array_keys(AuditLogPresenter::CATEGORIES))]]);
        $category = $filters['category'] ?? null;
        $q = AuditLog::query()->when($category, fn ($query) => $query->whereRaw('SUBSTR(event, 1, ?) = ?', [strlen($category) + 1, $category.'.']));
        $logs = $q->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('admin/AuditLogs', [
            'logs' => [...$logs->toArray(), 'data' => $presenter->present($logs->getCollection())],
            'filters' => ['category' => $category ?? ''],
            'categories' => AuditLogPresenter::CATEGORIES,
        ]);
    }
}
