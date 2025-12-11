<?php
session_start();
require 'config.php';
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_POST['email'])) {
    $email = $_POST['email'];
    $stmt = $conn->prepare("SELECT * FROM admins WHERE email=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $code = rand(100000, 999999);
        $expire = date("Y-m-d H:i:s", strtotime("+10 minutes"));
        $stmt = $conn->prepare("UPDATE admins SET reset_code=?, reset_expire=? WHERE email=?");
        $stmt->bind_param("sss", $code, $expire, $email);
        $stmt->execute();

        // Send email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'cfoprocureflow@gmail.com'; // your email
            $mail->Password = 'Chitalo123';   // Gmail App Password
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('cfoprocureflow@gmail.com', 'Procureflow.org');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Code';
            $mail->Body = "Your password reset code is: <b>$code</b>";

            $mail->send();
            $_SESSION['email'] = $email;
            header("Location: verify_code.php");
            exit;
        } catch (Exception $e) {
            echo "Mailer Error: " . $mail->ErrorInfo;
        }
    } else {
        echo "Email not found!";
    }
}
