<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XBeez Password Reset</title>
</head>
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.6;">
    <h2>Password Reset Request</h2>

    <p>Hello {{ $userName }},</p>

    <p>
        Use the verification code below to reset your XBeez password.
    </p>

    <div style="font-size: 30px; font-weight: bold; letter-spacing: 8px;">
        {{ $otp }}
    </div>

    <p>This code expires in <strong>5 minutes</strong>.</p>

    <p>
        If you did not request a password reset, you can safely ignore
        this email.
    </p>

    <p>Regards,<br>XBeez,Xavier Media Team</p>
</body>
</html>
