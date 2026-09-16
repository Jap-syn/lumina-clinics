<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase one guard for everything behind the reception desk.
 *
 * A single shared credential. This is deliberately thin and is argued for in
 * the proposal: per-person accounts with a branch role are the first security
 * item, and a launch condition rather than a phase-two nicety if Lumina goes
 * live holding consent records. Until then one token opens every branch diary,
 * which is exactly why it is written down rather than glossed over.
 *
 * Two ways to present it, one credential:
 *
 *   - a session, set by the staff login page, so a receptionist can click
 *     through the screens instead of pasting a header into every request;
 *   - a bearer token (or X-Staff-Token), so the JSON API and the race scripts
 *     keep working exactly as before.
 *
 * A browser gets a redirect to the login page; anything expecting JSON gets a
 * 401 and no redirect, because a 302 to an HTML page is a miserable thing to
 * receive from an API.
 */
class StaffToken
{
    /** Session key set by App\Http\Controllers\Staff\AuthController. */
    public const SESSION_KEY = 'lumina_staff_authenticated';

    public function handle(Request $request, Closure $next): Response
    {
        // API routes have no session middleware, so never assume a store exists.
        if ($request->hasSession() && $request->session()->get(self::SESSION_KEY) === true) {
            return $next($request);
        }

        $supplied = $request->bearerToken() ?? $request->header('X-Staff-Token');

        if (is_string($supplied) && hash_equals((string) config('lumina.staff_token'), $supplied)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['error' => 'Staff authentication required.'], 401);
        }

        // Remembered so the login page can send them where they were going.
        if ($request->hasSession()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()
            ->route('staff.login')
            ->with('error', 'Please sign in to reach the reception area.');
    }
}
