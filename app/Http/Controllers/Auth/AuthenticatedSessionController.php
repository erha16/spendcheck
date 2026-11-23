<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'string', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            $auditLogger->record('login_failed', 'authentication', 'Percobaan masuk gagal.', userId: null);
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak cocok.']);
        }

        $request->session()->regenerate();
        $auditLogger->record('login', 'authentication', 'Pengguna berhasil masuk.');

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $auditLogger->record('logout', 'authentication', 'Pengguna keluar.');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
