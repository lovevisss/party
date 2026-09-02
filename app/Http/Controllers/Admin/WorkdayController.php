<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workday;
use App\Services\AuditService;
use App\Services\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WorkdayController extends Controller
{
    public function index(Request $r): Response
    {
        return Inertia::render('admin/Workdays', ['workdays' => Workday::when($r->year, fn ($q, $y) => $q->whereYear('date', $y))->orderBy('date')->paginate(40), 'year' => $r->integer('year') ?: now()->year]);
    }

    public function store(Request $r, AuditService $a): RedirectResponse
    {
        $d = $r->validate(['date' => 'required|date', 'is_workday' => 'required|boolean', 'name' => 'nullable|string|max:100']);
        $day = Workday::updateOrCreate(['date' => $d['date']], [...$d, 'updated_by' => $r->user()->id]);
        $a->record('workday.saved', $day);

        return back()->with('success', '工作日历已保存。');
    }

    public function destroy(Workday $workday, AuditService $a): RedirectResponse
    {
        $a->record('workday.deleted', $workday);
        $workday->delete();

        return back();
    }

    public function import(Request $r, SpreadsheetReader $reader, AuditService $a): RedirectResponse
    {
        $r->validate(['file' => 'required|file|max:5120']);
        $rows = $reader->rows($r->file('file'));
        $h = array_map('trim', array_shift($rows) ?: []);
        $m = array_flip($h);
        $dk = $m['日期'] ?? $m['date'] ?? null;
        $wk = $m['是否工作日'] ?? $m['is_workday'] ?? null;
        $nk = $m['名称'] ?? $m['name'] ?? null;
        abort_if($dk === null || $wk === null, 422, '文件必须包含日期和是否工作日列。');
        DB::transaction(function () use ($rows, $dk, $wk, $nk, $r) {
            foreach ($rows as $row) {
                if (empty($row[$dk])) {
                    continue;
                }$raw = trim((string) $row[$wk]);
                $timestamp = strtotime($row[$dk]);
                abort_if($timestamp === false, 422, '工作日历包含无效日期。');
                Workday::updateOrCreate(['date' => date('Y-m-d', $timestamp)], ['is_workday' => in_array(mb_strtolower($raw), ['1', '是', 'true', '工作日'], true), 'name' => $nk !== null ? ($row[$nk] ?? null) : null, 'updated_by' => $r->user()->id]);
            }
        });
        $a->record('workday.imported', metadata: ['rows' => count($rows)]);

        return back()->with('success', '工作日历导入完成。');
    }
}
