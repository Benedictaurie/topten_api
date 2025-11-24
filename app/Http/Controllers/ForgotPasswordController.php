<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApiResponseResources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class ForgotPasswordController extends Controller
{
    /**
     * Sends the password reset link to the user's email.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return new ApiResponseResources(true, 'The password reset link has been sent to your email.', null, 200);
        }

        return new ApiResponseResources(false, 'Email not found.', null, 404);
    }

    /**
     * Displays the password reset form from the clicked link.
     * This is typically a web route, not an API.
     */
    public function showResetForm($token)
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    /**
     * Processes the password reset.
     */
    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ]);

        $status = Password::reset($request->all());

        if ($status === Password::PASSWORD_RESET) {
            return new ApiResponseResources(true, 'Password has been successfully reset. Please log in.', null, 200);
        }

        return new ApiResponseResources(false, 'Failed to reset password. The token may be invalid or expired.', null, 400);
    }
}