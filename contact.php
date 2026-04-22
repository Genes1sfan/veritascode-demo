<?php
/**
 * contact.php — server-side handler for the Veritas Code contact form.
 *
 * Receives a JSON POST from onboarding.jsx, validates it, and sends the
 * message via the local mail transport (php.ini's sendmail_path / SMTP).
 *
 * Deploy:
 *   1. Upload this file to the same host that serves the site (or to any
 *      host reachable from the browser — then set window.CONTACT_ENDPOINT
 *      to its full URL in index.html and uncomment the CORS header below).
 *   2. Edit the three CONFIG constants below:
 *        TO_EMAIL    — mailbox that receives leads
 *        FROM_EMAIL  — envelope sender on YOUR domain (required by SPF/DMARC)
 *        FROM_NAME   — display name
 *   3. On Hostinger / cPanel shared hosting, PHP mail() already uses the
 *      account's SMTP server — no extra config needed.
 *   4. For remote SMTP (Gmail, Office 365, custom MX) swap mail() out for
 *      PHPMailer — see the comment block at the bottom.
 */

// ----- CONFIG ---------------------------------------------------------------
const TO_EMAIL   = 'info@veritascode.net';
const FROM_EMAIL = 'no-reply@veritascode.net'; // must be on a domain you own
const FROM_NAME  = 'Veritas Code website form';
// ----------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
// If the form lives on a different origin than this PHP file, uncomment:
// header('Access-Control-Allow-Origin: https://veritascode.net');
// header('Access-Control-Allow-Methods: POST, OPTIONS');
// header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

$name    = trim((string)($data['name']    ?? ''));
$email   = trim((string)($data['email']   ?? ''));
$company = trim((string)($data['company'] ?? ''));
$size    = trim((string)($data['size']    ?? ''));
$message = trim((string)($data['message'] ?? ''));
$source  = trim((string)($data['source']  ?? 'contact form'));

// Validation
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'name and valid email are required']);
    exit;
}
// Reject header-injection attempts
foreach ([$name, $email, $company, $size] as $field) {
    if (preg_match('/[\r\n]/', $field)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid characters']);
        exit;
    }
}
// Length caps
if (strlen($message) > 5000 || strlen($name) > 200 || strlen($company) > 200) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'payload too large']);
    exit;
}

// Build message
$subject = 'Veritas Code enquiry — ' . ($company !== '' ? $company : $name);
$body  = "Name:     $name\n";
$body .= "Email:    $email\n";
$body .= "Company:  $company\n";
$body .= "Size:     $size\n\n";
$body .= "What they would delegate first:\n";
$body .= ($message !== '' ? $message : '(not provided)') . "\n\n";
$body .= "— Sent from $source\n";
$body .= 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\n";
$body .= 'UA: ' . substr($_SERVER['HTTP_USER_AGENT'] ?? '?', 0, 300) . "\n";

$fromHeader = sprintf('%s <%s>', addslashes(FROM_NAME), FROM_EMAIL);
$headers  = "From: $fromHeader\r\n";
$headers .= "Reply-To: " . addslashes($name) . " <$email>\r\n";
$headers .= "X-Mailer: veritascode.net\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$ok = @mail(TO_EMAIL, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . FROM_EMAIL);

if (!$ok) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'mail transport failed']);
    exit;
}

echo json_encode(['ok' => true]);

/* -----------------------------------------------------------------------------
 * If your host doesn't run a local MTA (e.g. you want Gmail / M365 / SES via
 * SMTP with credentials), replace the mail() call above with PHPMailer:
 *
 *   composer require phpmailer/phpmailer
 *
 *   use PHPMailer\PHPMailer\PHPMailer;
 *   require 'vendor/autoload.php';
 *   $m = new PHPMailer(true);
 *   $m->isSMTP();
 *   $m->Host       = 'smtp.yourprovider.com';
 *   $m->SMTPAuth   = true;
 *   $m->Username   = 'smtp-user';
 *   $m->Password   = getenv('SMTP_PASS');     // set in env, never commit
 *   $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
 *   $m->Port       = 465;
 *   $m->setFrom(FROM_EMAIL, FROM_NAME);
 *   $m->addAddress(TO_EMAIL);
 *   $m->addReplyTo($email, $name);
 *   $m->Subject = $subject;
 *   $m->Body    = $body;
 *   $m->send();
 * -------------------------------------------------------------------------- */
