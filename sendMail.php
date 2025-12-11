<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

function sendVerificationEmail($email, $name, $verificationToken) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'cfoprocureflow@gmail.com'; // Replace with your credentials
        $mail->Password = 'nwie dmub ugkf uqpd'; // Replace with your credentials
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('noreply@yourdomain.com', 'ProcureFlow Support');
        $mail->addAddress($email, $name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your ProcureFlow Account';
        
        $verificationLink = "http://yourdomain.com/verify.php?token=" . $verificationToken;
        
        $mail->Body = "
            <h2>Welcome to ProcureFlow!</h2>
            <p>Hi $name,</p>
            <p>Please verify your email by clicking the link below:</p>
            <a href='$verificationLink' style='background:#2c6bed; color:white; padding:10px 15px; text-decoration:none; border-radius:5px;'>Verify Email</a>
            <p>Or copy this link: $verificationLink</p>
        ";
        
        $mail->AltBody = "Verify your ProcureFlow account: $verificationLink";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>