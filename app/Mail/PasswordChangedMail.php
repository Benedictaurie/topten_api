<?php

namespace App\Mail;

use App\Models\User; 
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class PasswordChangedMail extends Mailable 
{
    use Queueable, SerializesModels;

    public $user;
    public $token;

    /**
     * Create a new message instance.
     * Receives the User Model and the reset token.
     */
    public function __construct($user, $token)
    {
        $this->user = $user;
        $this->token = $token;
    }

    /**
     * Define the message content using the build() method with inline HTML.
     */
    public function build()
    {
        // 1. Create the full reset URL link
        // Catatan: 'password.reset' adalah route yang MENGARAH ke form reset, 
        // BUKAN ke fungsi changePassword di controller ini.
        $resetUrl = url(route('password.reset', [
            'token' => $this->token, 
            'email' => $this->user->email
        ], false));
        
        $appName = config('app.name');
        
        // 2. Create HTML content using inline styles
        $htmlContent = "
            <!DOCTYPE html>
            <html>
            <head>
                <title>Password Reset</title>
            </head>
            <body style='font-family: sans-serif; line-height: 1.6; color: #333;'>
                <div style='max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                    <h2 style='color: #FF9800;'>Password Reset Request</h2>
                    <p>Hello <strong>{$this->user->name}</strong>,</p>
                    
                    <p>You are receiving this email because we received a password reset request for your account.</p>
                    
                    <p style='margin-bottom: 25px;'>Please click the button below to continue with your password reset process:</p>
                    
                    <div style='text-align: center;'>
                        <a href='{$resetUrl}' style='
                            background-color: #007BFF; 
                            color: white; 
                            padding: 10px 20px; 
                            text-decoration: none; 
                            border-radius: 5px; 
                            font-weight: bold;
                            display: inline-block;
                        '>Reset My Password</a>
                    </div>

                    <p style='margin-top: 25px;'>This password reset link will expire in 60 minutes.</p>
                    <p style='font-size: 12px; color: #888;'>If you did not request a password reset, you may ignore this email.</p>
                    
                    <hr style='border: none; border-top: 1px solid #eee; margin-top: 20px;'>
                    <p>Thank you,<br>The {$appName} Team</p>
                </div>
            </body>
            </html>
        ";

        return $this->subject('Your Password Reset Request')
                    ->html($htmlContent);
    }
}