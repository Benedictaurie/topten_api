<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\ApiResponseResources;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return new ApiResponseResources(false, 'Unauthorized. Please login first.', null, 401);
        }

        $user = Auth::user();
        
        // Check if user has any of the required roles
        if (!in_array($user->role, $roles)) {
            $requiredRoles = implode(', ', $roles);
            return new ApiResponseResources(
                false, 
                "Access denied. Required role: {$requiredRoles}. Your role: {$user->role}.", 
                null, 
                403
            );
        }

        return $next($request);
    }
}