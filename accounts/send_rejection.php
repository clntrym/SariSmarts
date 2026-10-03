<?php

require_once "mailer.php";

function sendRejectionEmail(
    $email,
    $fullname,
    $job,
    $branch
){

    $mail = getMailer();

    $mail->addAddress($email, $fullname);

    $mail->Subject = "Application Status";

    $mail->Body = "

    <h2 style='color:#dc3545;'>Application Update</h2>

    Dear <b>$fullname</b>,<br><br>

    Thank you for taking the time to apply for the
    <b>$job</b> position at
    <b>$branch</b>.

    <br><br>

    After carefully reviewing your application and interview,
    we regret to inform you that you were not selected for this position.

    <br><br>

    We sincerely appreciate your interest in joining SariSmarts.
    We encourage you to apply again for future opportunities that match your skills and experience.

    <br><br>

    We wish you success in your future career.

    <br><br>

    Best regards,<br>

    <b>SariSmarts HR Department</b>

    ";

    return $mail->send();

}