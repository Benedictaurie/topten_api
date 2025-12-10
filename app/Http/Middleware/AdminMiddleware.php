<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\ApiResponseResources;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Cek jika user sudah login
        if (!Auth::check()) {
            return new ApiResponseResources(false, 'Unauthorized. Please login first.', null, 401);
        }

        // Cek jika user memiliki role 'admin'
        if (Auth::user()->role !== 'admin') {
            return new ApiResponseResources(
                false, 
                'Access denied. This route is for admin only.', 
                null, 
                403
            );
        }

        return $next($request);
    }
}