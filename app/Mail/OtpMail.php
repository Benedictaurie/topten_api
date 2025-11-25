<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;

    public function __construct(string $otp) 
    {
        $this->otp = $otp;
    }

    public function build()
    {
        $otp = $this->otp;
        
        $htmlContent = "
            <!DOCTYPE html>
            <html>
            <head>
                <title>OTP Code</title>
            </head>
            <body style='font-family: sans-serif; line-height: 1.6; color: #333;'>
                <div style='max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                    <h2 style='color: #007BFF;'>Your OTP Verification Code</h2>
                    <p>Hello,</p>
                    <p>Your OTP code is:</p>
                    
                    <div style='text-align: center; margin: 20px 0;'>
                        <span style='font-size: 28px; font-weight: bold; color: #fff; background-color: #007BFF; padding: 15px 25px; border-radius: 6px; display: inline-block;'>{$otp}</span>
                    </div>

                    <p>This code will expire in 5 minutes. Please do not share this code with anyone.</p>
                    <p style='margin-top: 20px;'>Thank you.</p>
                </div>
            </body>
            </html>
        ";

        return $this->subject('Your OTP Code')->html($htmlContent);
    }
}