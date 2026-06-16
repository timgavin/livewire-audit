<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsVerified
{
    /**
     * Block users who have not completed account verification from
     * reaching protected areas.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasVerifiedAccount()) {
            return redirect()->route('account.settings')
                ->with('status', 'Please verify your account to continue.');
        }

        return $next($request);
    }
}
