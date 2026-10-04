<?php

require_once "mailer.php";

function sendEmployeeCredentials(
    $email,
    $fullname,
    $password
) {

    $mail = getMailer();

    $mail->addAddress($email, $fullname);

    $mail->Subject = "Welcome to RetailCore - Your Employee Account";

    $mail->Body = "

    <h2>Welcome to RetailCore!</h2>

    Dear <b>$fullname</b>,<br><br>

    Congratulations! Your employment has been approved by the HR Department.

    <br><br>

    Your employee account has been successfully created.

    <br><br>

    <table cellpadding='8' cellspacing='0' border='1' style='border-collapse:collapse;'>
        <tr>
            <td><b>Email</b></td>
            <td>$email</td>
        </tr>
        <tr>
            <td><b>Password</b></td>
            <td>123456</td>
        </tr>
    </table>

    <br>

    You may now log in to the RetailCore Employee Portal to:

    <ul>
        <li>Time In / Time Out</li>
        <li>View Attendance</li>
        <li>View Payroll</li>
        <li>Submit Leave Requests</li>
        <li>Update Employee Information</li>
    </ul>

    <br>

    Please keep your account credentials confidential.

    <br><br>

    Regards,<br>

    <b>RetailCore HR Department</b>

    ";

    return $mail->send();

}