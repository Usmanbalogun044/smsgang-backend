<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMSGang Email Verification</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:24px;">
                <tr>
                    <td style="font-size:22px;font-weight:700;color:#0f172a;">Verify your SMSGang account</td>
                </tr>
                <tr>
                    <td style="padding-top:12px;font-size:14px;line-height:1.6;color:#334155;">
                        Use the verification code below to complete your signup.
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 0;">
                        <div style="display:inline-block;padding:12px 20px;font-size:30px;letter-spacing:8px;font-weight:700;background:#f1f5f9;border-radius:10px;color:#0f172a;">
                            {{ $otp }}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:14px;line-height:1.6;color:#334155;">
                        This code expires in {{ $ttlMinutes }} minutes.
                    </td>
                </tr>
                <tr>
                    <td style="padding-top:14px;font-size:12px;line-height:1.6;color:#64748b;">
                        If you did not request this, you can ignore this email.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
