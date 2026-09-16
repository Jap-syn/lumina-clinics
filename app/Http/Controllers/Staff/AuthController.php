<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Middleware\StaffToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign in to the reception area with the shared staff token.
 *
 * This is NOT a user system and is not pretending to be one: there is one
 * credential, it is the same one the API accepts, and nothing here records who
 * signed in - because there is no "who" to record yet. It exists so the screens
 * can be clicked through, and so that one middleware guards every staff page.
 *
 * What it does do properly: a timing-safe comparison, a session fixation guard
 * on success, and a throttle on the route, because a single shared secret is
 * exactly the kind of thing people try to guess.
 */
class AuthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->get(StaffToken::SESSION_KEY) === true) {
            return redirect()->route('staff.diary');
        }

        return view('staff.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['token' => ['required', 'string', 'max:255']],
            [],
            ['token' => 'staff token'],
        );

        if (! hash_equals((string) config('lumina.staff_token'), $data['token'])) {
            throw ValidationException::withMessages([
                'token' => 'That staff token was not recognised.',
            ]);
        }

        // New session id on privilege change.
        $request->session()->regenerate();
        $request->session()->put(StaffToken::SESSION_KEY, true);

        return redirect()->intended(route('staff.diary'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(StaffToken::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('staff.login')->with('status', 'Signed out.');
    }
}
