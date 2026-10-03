<?php

require_once "mailer.php";

function sendVerificationEmail(
    $email,
    $fullname,
    $verification_token
) {

    $mail = getMailer();

    $mail->isHTML(true);
    $mail->CharSet = "UTF-8";

    $mail->addAddress($email, $fullname);

    $baseUrl = "http://localhost/SariSmarts";

    $verificationLink =
        $baseUrl .
        "/accounts/verify_email.php?token=" .
        urlencode($verification_token);

    $mail->Subject = "Verify Your Email - SariSmart Careers";

    $mail->Body = "

    <div style='
        font-family:Arial,sans-serif;
        font-size:15px;
        color:#333;
        line-height:1.6;
    '>

        <h2 style='
            color:#00224C;
            margin-bottom:20px;
        '>
            Verify Your Email Address
        </h2>

        <p>
            Hello <strong>$fullname</strong>,
        </p>

        <p>
            Thank you for applying to
            <strong>SariSmart Retail OS</strong>.
        </p>

        <p>
            Before our Human Resources team reviews your application,
            please verify your email address by clicking the button below.
        </p>

        <div style='
            text-align:center;
            margin:30px 0;
        '>

            <a href='$verificationLink'
                style='
                    background:#00224C;
                    color:#ffffff;
                    padding:14px 30px;
                    text-decoration:none;
                    border-radius:8px;
                    display:inline-block;
                    font-weight:bold;
                '>

                Verify Email

            </a>

        </div>

        <p>
            If the button above does not work,
            copy and paste the link below into your browser:
        </p>

        <p>
            <a href='$verificationLink'>
                $verificationLink
            </a>
        </p>

        <hr>

        <p style='font-size:13px;color:#777;'>

            If you did not submit an application to
            SariSmart, you may safely ignore this email.

        </p>

        <p>

            Regards,<br>

            <strong>
                SariSmart Human Resources Department
            </strong>

        </p>

        <p>
            Once your email is verified, our Human Resources team
            will begin reviewing your application.
        </p>

        <hr>

        <p style='font-size:12px;color:#999;'>

            This is an automated email from SariSmart Careers.
            Please do not reply to this message.

        </p>

    </div>

    ";

    try {

        return $mail->send();

    } catch (Exception $e) {

        error_log(
            "Verification Email Error: " .
            $mail->ErrorInfo
        );

        return false;
    }
}