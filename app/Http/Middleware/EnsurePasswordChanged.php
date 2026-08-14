<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->must_change_password === true) {
            return response()->json([
                'message' => 'Password change required.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'must_change_password' => true,
            ], 403);
        }

        return $next($request);
    }
}
