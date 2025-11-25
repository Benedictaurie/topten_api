<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\ApiResponseResources;

class EmailVerified
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return new ApiResponseResources(false, 'Unauthorized. Please login first.', null, 401);
        }

        // Check using email_verified_at (more standard)
        if (!Auth::user()->email_verified_at) {
            return new ApiResponseResources(
                false, 
                "Your email is not verified. Please verify your email to access this feature.", 
                null, 
                403
            );
        }

        return $next($request);
    }
}