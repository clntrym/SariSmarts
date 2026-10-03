<?php

require_once "mailer.php";

function sendContract(
    $email,
    $fullname,
    $contractPath
) {

    $mail = getMailer();

    $mail->addAddress($email, $fullname);

    $mail->Subject = "SariSmart Employment Contract";

    $mail->Body = "

    <h2>Congratulations!</h2>

    Dear <b>$fullname</b>,<br><br>

    We are pleased to inform you that your application has been officially approved by the SariSmart HR Department.

    <br><br>

    Attached to this email is your <b>Employment Contract</b>.

    <br><br>

    Please review the contract carefully.

    <br><br>

    <b>Next Steps:</b>

    <ol>
        <li>Read the attached Employment Contract.</li>
        <li>Print and sign the contract.</li>
        <li>Submit the signed copy to the HR Department or upload it through the Employee Portal if instructed.</li>
    </ol>

    <br>

    If you have any questions regarding the contract, please contact the HR Department.

    <br><br>

    Welcome to the SariSmart family!

    <br><br>

    Regards,<br>

    <b>SariSmart HR Department</b>

    ";

    // Attach the contract PDF
    if (file_exists($contractPath)) {
        $mail->addAttachment(
            $contractPath,
            "Employment_Contract.pdf"
        );
    }

    return $mail->send();
}