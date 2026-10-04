<?php

require_once __DIR__ . "/mailer.php";

/*
|--------------------------------------------------------------------------
| COMPANY OWNER VERIFICATION EMAIL
|--------------------------------------------------------------------------
|
| Separate from send_verification.php, which belongs to the job-applicant
| flow and writes to the `applications` table. This one confirms the email
| of an Owner/Admin who just registered their business.
|
*/

function sendCompanyVerificationEmail($email, $ownerName, $companyName, $token)
{
    $mail = getMailer();

    $mail->addAddress($email, $ownerName);

    /*
    | Built from the address this request arrived on.
    |
    | This was the literal "http://localhost/platform/..." -- correct on the
    | machine it was typed on and useless anywhere else. Every owner who
    | registered on the deployed site was sent a link to their own computer,
    | so the address they were asked to confirm could never be confirmed.
    */
    require_once __DIR__ . "/../../includes/app_url.php";

    $link = appUrl("/platform/accounts/verify_company.php?token=" . urlencode($token));

    $mail->Subject = "Verify your email - RetailCore";

    $safeOwner   = htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8');
    $safeCompany = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
    $safeLink    = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    $mail->Body = "
    <div style='font-family:Arial,sans-serif;font-size:15px;color:#333;line-height:1.6;max-width:560px;'>

        <h2 style='color:#00224C;margin-bottom:8px;'>Welcome to RetailSync</h2>

        <p>Hi {$safeOwner},</p>

        <p>
            Your account for <strong>{$safeCompany}</strong> has been created.
            Confirm your email address so we can activate your subscription.
        </p>

        <p style='margin:28px 0;'>
            <a href='{$safeLink}'
               style='background:#00224C;color:#fff;padding:14px 28px;border-radius:8px;
                      text-decoration:none;font-weight:bold;display:inline-block;'>
                Verify My Email
            </a>
        </p>

        <p style='color:#666;font-size:13px;'>
            If the button does not work, paste this link into your browser:<br>
            <span style='color:#00224C;word-break:break-all;'>{$safeLink}</span>
        </p>

        <p style='color:#666;font-size:13px;'>
            This link expires in 48 hours. If you did not sign up, you can ignore this email.
        </p>

        <hr style='border:none;border-top:1px solid #eee;margin:28px 0;'>

        <p style='color:#999;font-size:12px;'>RetailSync - Unified Retail Operations Platform</p>

    </div>";

    $mail->AltBody =
        "Hi {$ownerName},\n\n"
        . "Your RetailSync account for {$companyName} has been created.\n"
        . "Verify your email to activate it: {$link}\n\n"
        . "This link expires in 48 hours.";

    $mail->send();

    return true;
}
