<?php
// notify.php — centralized email + status notifications (friendly From name)

date_default_timezone_set('Africa/Lusaka');

/* ===== App config =====
 * Make sure FROM_EMAIL matches your sendmail.ini auth_username/force_sender.
 */
$APP_URL     = $APP_URL     ?? 'http://localhost//'; // app URL
$FROM_EMAIL  = $FROM_EMAIL  ?? 'kennychitalo45@gmail.com';   // <-- OG email address here
$FROM_NAME   = $FROM_NAME   ?? ' Tech Support';            // <-- School system tech support
$ADMIN_EMAIL = $ADMIN_EMAIL ?? '';         // optional email: for BCC
$LOGIN_URL   = rtrim($APP_URL, '/') . '/Login.php'; // change to suit your scripts
$RESET_URL   = rtrim($APP_URL, '/') . '/forgot-password.php'; // change to suit your scripts

/* RFC 2047 encode non-ASCII display names */
function encode_name(string $name): string {
  return preg_match('/[^\x20-\x7E]/', $name)
    ? '=?UTF-8?B?' . base64_encode($name) . '?='
    : $name;
}

/**
 * Send an email safely (won’t crash app if SMTP fails).
 * $to        string   Recipient email
 * $subject   string   Subject line
 * $text      string   Plain-text body
 * $bcc       ?string  Optional BCC (e.g., admin)
 * $html      ?string  Optional HTML body (if provided, mail is sent as text/html)
 */
function send_mail_safely(string $to, string $subject, string $text, ?string $bcc = null, ?string $html = null): void {
  global $FROM_EMAIL, $FROM_NAME, $ADMIN_EMAIL;

  $fromHeader = encode_name($FROM_NAME) . " <{$FROM_EMAIL}>";
  $headers  = "From: {$fromHeader}\r\n";
  $headers .= "Reply-To: {$ADMIN_EMAIL}\r\n";
  if ($bcc) { $headers .= "Bcc: {$bcc}\r\n"; }

  if ($html !== null) {
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8";
    $body = $html;
  } else {
    $headers .= "Content-Type: text/plain; charset=UTF-8";
    $body = $text;
  }

  // Don’t throw if SMTP fails in local dev
  @mail($to, $subject, $body, $headers);
}

/**
 * Status notifications
 * $status ∈ { active, rejected, suspended }
 * ctx: ['lockout_until' => 'Y-m-d H:i:s'|null, 'manual' => bool, 'bcc' => string|null]
 */
function notify_user_status(string $email, string $status, array $ctx = []): void {
  global $LOGIN_URL, $RESET_URL, $ADMIN_EMAIL;

  $bcc   = $ctx['bcc']   ?? null;
  $until = $ctx['lockout_until'] ?? null;
  $isMan = !empty($ctx['manual']);

  switch ($status) {
    case 'active':
      $subject = "JMS account approved";
      $text = "Your JMS account has been approved.\n\nSign in: {$LOGIN_URL}\n\nIf you didn’t request this, contact the administrator.";
      send_mail_safely($email, $subject, $text, $bcc);
      break;

    case 'rejected':
      $subject = "JMS account request rejected";
      $text = "Your JMS account request was not approved.\n\nIf this is an error, reply to this email or contact the administrator.";
      send_mail_safely($email, $subject, $text, $bcc);
      break;

    case 'suspended':
      if ($isMan) {
        $subject = "JMS account suspended";
        $text = "Your JMS account has been suspended by an administrator.\n\nIf you need access restored, contact the administrator.";
        send_mail_safely($email, $subject, $text, $bcc);
      } else {
        $when = $until ? " until {$until}" : "";
        $subject = "JMS account locked due to failed sign-in attempts";
        $text = "For your security, your JMS account was temporarily locked{$when} after multiple failed sign-in attempts.\n\n"
              . "Reset your password here:\n{$RESET_URL}\n\nIf this wasn’t you, please reset your password and inform the administrator.";
        send_mail_safely($email, $subject, $text, $bcc);
      }
      break;
  }
}
