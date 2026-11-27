<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/security_headers.php';
require_once __DIR__ . '/../../utils/check_report_public.php';
require_once __DIR__ . '/../../utils/check_reports.php';
send_security_headers(true);
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$message = ''; $error = ''; $context = null; $report = null; $reportNeedsReview = false;
try {
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET','POST'], true)) { http_response_code(405); throw new DomainException('Use the invoice link to report your check.'); }
    if (!rate_limit_check($pdo, 'mailed_check_view', 30, 60, false)) { http_response_code(429); throw new DomainException('Please wait before trying again.'); }
    $context = pa_check_report_public_context($pdo, $token, $appConfig);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!csrf_validate()) { http_response_code(403); throw new DomainException('Reload this page before continuing.'); }
        if (!rate_limit_check($pdo, 'mailed_check_action', 15, 900, false)) { http_response_code(429); throw new DomainException('Please wait before trying again.'); }
        $client = $context['client'];
        $challengeAnchor = pa_check_report_public_anchor($context, $token);
        if ($challengeAnchor === '') throw new DomainException('Contact the business to report your mailed check.');
        $challengeSession = 'mailed-check:' . session_id() . ':' . $challengeAnchor;
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'send_code') {
            require_once __DIR__ . '/../../services/EmailService.php';
            $_SESSION['mailed_check_challenge'] = pa_autopay_send_code($pdo, $client, $challengeSession, static function ($email, $code): void {
                [$ok] = EmailService::sendEmail($email, 'Verify your mailed-check report', '<p>Your mailed-check verification code is <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong>. It expires in ten minutes. Do not share it.</p>');
                if (!$ok) throw new DomainException('The verification email could not be sent. Contact the business.');
            });
            $_SESSION['mailed_check_challenge_token'] = hash('sha256', $token);
            $_SESSION['mailed_check_challenge_client_public_id'] = (string)$client['public_id'];
            $_SESSION['mailed_check_challenge_anchor'] = $challengeAnchor;
            $message = 'A verification code was sent to the client email on file.';
        } elseif ($action === 'verify') {
            if (!hash_equals(hash('sha256', $token), (string)($_SESSION['mailed_check_challenge_token'] ?? ''))
                || !hash_equals((string)$client['public_id'], (string)($_SESSION['mailed_check_challenge_client_public_id'] ?? ''))
                || !hash_equals($challengeAnchor, (string)($_SESSION['mailed_check_challenge_anchor'] ?? ''))
                || !pa_autopay_verify_code($pdo, $client, (int)($_SESSION['mailed_check_challenge'] ?? 0), (string)($_POST['code'] ?? ''), $challengeSession)) {
                throw new DomainException('Invalid or expired verification code.');
            }
            $_SESSION['mailed_check_verified'] = ['client_id'=>(int)$client['id'],'client_public_id'=>(string)$client['public_id'],'document_public_id'=>(string)$context['document']['public_id'],'email'=>$client['email'],'until'=>time()+600,'token_hash'=>hash('sha256', $token)];
            $message = 'Email verified. Enter the number of the check you mailed.';
        } elseif ($action === 'report') {
            if (!pa_check_report_public_authority($context, $token, $_SESSION)) { http_response_code(403); throw new DomainException('Verify your email before reporting a mailed check.'); }
            $report = pa_check_report_submit($pdo, $context['type'], $context['document_id'], (int)$client['id'], (string)($_POST['check_number'] ?? ''), $appConfig);
            $message = 'Your mailed-check report is saved. This invoice is not paid until the business confirms receipt.';
        } else throw new DomainException('Choose a valid action.');
    }
    $report = pa_check_report_lookup($pdo, $context['type'], $context['document_id']);
    if ($report && !pa_check_report_same_client($report, $context['client'])) {
        $report = null; $reportNeedsReview = true;
        $error = 'This invoice has a mailed-check hold that needs staff review. Contact the business before making another payment.';
    }
} catch (DomainException | InvalidArgumentException $failure) {
    if (http_response_code() < 400) http_response_code(409);
    $error = $failure->getMessage();
} catch (Throwable $failure) {
    http_response_code(503); $error = 'Mailed-check reporting is unavailable. Contact the business before sending another payment.';
}
require __DIR__ . '/../../views/pages/public/mailed-check.php';
exit;
