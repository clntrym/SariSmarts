<?php

require_once __DIR__ . "/mailer.php";

/*
|--------------------------------------------------------------------------
| REVIEW RESULT EMAIL
|--------------------------------------------------------------------------
|
| Tells the owner what happened to their application. A rejection always
| carries the reviewer's reason so the owner knows exactly what to fix
| before resubmitting.
|
*/

function sendReviewResultEmail($email, $ownerName, $companyName, $decision, $reason, $approvalToken = null)
{
    $mail = getMailer();
    $mail->addAddress($email, $ownerName);

    $safeOwner = htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8');
    $safeCompany = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
    $safeReason = nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'));

    $approved = $decision === 'Approved';

    /*
    | The token lets subscribe.php recognise the owner straight from the link.
    | Without it the page opens on an email and password form, which an
    | approved owner cannot get past -- their account stays locked until the
    | subscription is paid for.
    */
    $subscribeLink = "http://localhost/platform/subscribe.php";

    if ($approvalToken) {
        $subscribeLink .= "?ref=" . urlencode($approvalToken);
    }
    $updateLink = "http://localhost/platform/resubmit.php";

    if ($approved) {

        $mail->Subject = "Your business has been approved - RetailSync";

        $body = "
            <p>Good news, {$safeOwner}.</p>

            <p>
                <strong>{$safeCompany}</strong> has been approved. The next step is to 
                confirm your choosen plan. Once payment is confirmed your account is activated
                and you can sign in.
            </p>

            " . ($safeReason !== '' ? "
            <div style='background:#f0fdf4;border-left:4px solid #198754;padding:14px 18px;margin:22px 0;'>
                <strong>Notes from our team</strong><br>{$safeReason}
            </div>" : "") . "

            <p style='margin:28px 0;'>
                <a href='{$subscribeLink}'
                   style='background:#00224C;color:#fff;padding:14px 28px;border-radius:8px;
                          text-decoration:none;font-weight:bold;display:inline-block;'>
                    Confirm Your Plan
                </a>
            </p>

            <p style='color:#666;font-size:13px;'>
                Our team will be in touch shortly to help you complete your subscription.
            </p>";

        $altBody =
            "Good news, {$ownerName}.\n\n"
            . "{$companyName} has been approved. Next, choose a plan and settle your subscription.\n\n"
            . ($reason !== '' ? "Notes from our team:\n{$reason}\n\n" : "")
            . "Continue: {$subscribeLink}";

    } else {

        $mail->Subject = "Action needed on your application - RetailSync";

        $body = "
            <p>Hi {$safeOwner},</p>

            <p>
                We reviewed the application for <strong>{$safeCompany}</strong> and we are
                not able to approve it yet. Please review the notes below, correct the
                details, and submit again.
            </p>

            <div style='background:#fef2f2;border-left:4px solid #dc3545;padding:14px 18px;margin:22px 0;'>
                <strong>What needs to be corrected</strong><br>{$safeReason}
            </div>

            <p style='margin:28px 0;'>
                <a href='{$updateLink}'
                   style='background:#00224C;color:#fff;padding:14px 28px;border-radius:8px;
                          text-decoration:none;font-weight:bold;display:inline-block;'>
                    Update My Application
                </a>
            </p>

            <p style='color:#666;font-size:13px;'>
                Sign in with the email and password you registered with to make the changes.
            </p>";

        $altBody =
            "Hi {$ownerName},\n\n"
            . "The application for {$companyName} could not be approved yet.\n\n"
            . "What needs to be corrected:\n{$reason}\n\n"
            . "Update your application: {$updateLink}";
    }

    $mail->Body = "
    <div style='font-family:Arial,sans-serif;font-size:15px;color:#333;line-height:1.6;max-width:560px;'>

        <h2 style='color:#00224C;margin-bottom:8px;'>
            " . ($approved ? 'Application approved' : 'Application needs changes') . "
        </h2>

        {$body}

        <hr style='border:none;border-top:1px solid #eee;margin:28px 0;'>

        <p style='color:#999;font-size:12px;'>RetailSync - Unified Retail Operations Platform</p>

    </div>";

    $mail->AltBody = $altBody;

    $mail->send();

    return true;
}
