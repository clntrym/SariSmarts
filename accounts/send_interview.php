<?php

require_once "mailer.php";

function sendInterviewInvitation(
    $email,
    $fullname,
    $job,
    $branch,
    $date,
    $time,
    $location
){

    $mail = getMailer();

    $mail->addAddress($email,$fullname);

    $mail->Subject = "Interview Invitation";

    $mail->Body = "

    <h2>Interview Invitation</h2>

    Dear <b>$fullname</b>,<br><br>

    Congratulations!

    <br><br>

    You have been shortlisted for the position of

    <b>$job</b>.

    <br><br>

    <b>Branch:</b> $branch<br>
    <b>Date:</b> $date<br>
    <b>Time:</b> $time<br>
    <b>Location:</b> $location<br><br>

    Please arrive 15 minutes before your interview.

    <br><br>

    Regards,<br>

    RetailCore HR Department

    ";

    return $mail->send();

}