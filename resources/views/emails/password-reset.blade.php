<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #5D87FF 0%, #4570EA 100%);
            padding: 40px 30px;
            text-align: center;
        }
        .email-header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        .email-body {
            padding: 40px 30px;
        }
        .email-body h2 {
            color: #333;
            font-size: 22px;
            margin-top: 0;
            margin-bottom: 20px;
        }
        .email-body p {
            color: #555;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .reset-button {
            display: inline-block;
            padding: 14px 40px;
            background: linear-gradient(135deg, #5D87FF 0%, #4570EA 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s;
        }
        .reset-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(93, 135, 255, 0.4);
        }
        .button-container {
            text-align: center;
            margin: 30px 0;
        }
        .expiration-notice {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .expiration-notice p {
            margin: 0;
            color: #856404;
            font-size: 14px;
        }
        .alternative-link {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin: 20px 0;
        }
        .alternative-link p {
            margin: 5px 0;
            font-size: 14px;
            color: #666;
        }
        .alternative-link a {
            color: #5D87FF;
            word-break: break-all;
        }
        .email-footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .email-footer p {
            color: #6c757d;
            font-size: 14px;
            margin: 5px 0;
        }
        .security-notice {
            background-color: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .security-notice p {
            margin: 0;
            color: #0c5460;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>🔐 Password Reset</h1>
        </div>
        
        <div class="email-body">
            <h2>Reset Your Password</h2>
            
            <p>Hello,</p>
            
            <p>We received a request to reset your password. Click the button below to create a new password:</p>
            
            <div class="button-container">
                <a href="{{ $resetUrl }}" class="reset-button">Reset Password</a>
            </div>
            
            <div class="expiration-notice">
                <p><strong>⏰ Important:</strong> This password reset link will expire in <strong>{{ $expirationMinutes }} minutes</strong>.</p>
            </div>
            
            <div class="alternative-link">
                <p><strong>Can't click the button?</strong> Copy and paste this link into your browser:</p>
                <p><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
            </div>
            
            <div class="security-notice">
                <p><strong>🛡️ Security Notice:</strong> If you didn't request a password reset, please ignore this email. Your password will remain unchanged.</p>
            </div>
        </div>
        
        <div class="email-footer">
            <p><strong>LaraSafe Backup System</strong></p>
            <p>This is an automated email. Please do not reply to this message.</p>
            <p style="margin-top: 15px; font-size: 12px;">© {{ date('Y') }} LaraSafe. All rights reserved.</p>
        </div>
    </div>
</body>
</html>