<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CasAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CasController extends Controller
{
    public function login(Request $request, CasAuthenticationService $cas): RedirectResponse
    {
        return redirect()->away($cas->loginUrl((string) $request->query('returnUrl', '/dashboard')));
    }

    public function callback(Request $request, CasAuthenticationService $cas, AuditService $audit): RedirectResponse
    {
        $request->validate(['ticket' => ['required', 'string', 'max:2048']]);
        $identity = $cas->validateTicket($request->string('ticket')->toString());
        $person = Person::where('employee_no', $identity['account'])->where('status', 'active')->first();
        if (! $person) {
            $audit->record('auth.unmatched', metadata: ['account' => $identity['account']]);

            return redirect()->route('access.denied')->with('error', '账号未同步或已停用，请联系管理员。');
        }
        $user = User::updateOrCreate(['cas_account' => $identity['account']], ['person_id' => $person->id, 'name' => $person->name, 'email' => $person->email ?: $person->employee_no.'@invalid.local', 'password' => bcrypt(Str::random(64)), 'is_active' => true]);
        Auth::login($user);
        $request->session()->regenerate();
        $audit->record('auth.login', $user);

        return redirect()->to(session()->pull('cas.return_url', '/dashboard'));
    }

    public function logout(Request $request, CasAuthenticationService $cas, AuditService $audit): RedirectResponse
    {
        if ($request->user()) {
            $audit->record('auth.logout', $request->user());
        } Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($cas->logoutUrl());
    }

    public function backChannelLogout(Request $request, AuditService $audit): JsonResponse
    {
        $audit->record('auth.back_channel_logout', metadata: ['received' => true]);
        if ($request->hasSession()) {
            $request->session()->invalidate();
        }

        return response()->json(['code' => 0, 'data' => ['success' => true]]);
    }

    public function denied(): Response
    {
        return Inertia::render('auth/NoAccess');
    }
}
