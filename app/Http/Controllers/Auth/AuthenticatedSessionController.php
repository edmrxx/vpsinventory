<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    /**
     * Show the login page.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'title' => 'Sign in',
            'status' => session('status'),
        ]);
    }

    /**
     * The same sign-in, laid out for a tablet on the floor.
     *
     * Bigger fields, bigger button, nothing else to look at. It is a
     * different **screen**, not a different way in: the form posts to the
     * same `/login` route, so the same validation, the same throttle and
     * the same session handling apply. A second authentication path would
     * be a second thing to get wrong, and the one that nobody remembers to
     * check when a rule changes.
     *
     * "Remember me" is deliberately absent. A kiosk is a shared device, and
     * a shared device that stays signed in is an unlocked door.
     */
    public function kiosk(): Response
    {
        return Inertia::render('Auth/Kiosk', [
            'title' => 'Kiosk sign in',
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming login request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $this->audit->logLogin($request->user());

        // Somebody who signed in at the tablet came to serve a customer,
        // not to read a dashboard. `intended()` is skipped on purpose: a
        // half-finished page from whoever used the tablet last is not
        // where the next person should land.
        if ($request->string('from')->toString() === 'kiosk') {
            return redirect()->route('pos');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Log the user out and destroy the session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Recorded before the session goes, while the user is still known.
        $this->audit->logLogout($request->user());

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You are logged out.');
    }
}
