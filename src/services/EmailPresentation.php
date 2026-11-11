<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailProviders.php';

/** Shared presentation, applied after transport and document issuer resolution. */
final class EmailPresentation
{
    public static function apply(EmailMessage $message, array $config): void
    {
        $title = preg_replace('/^\[Automated\]\s*/i', '', $message->subject) ?? $message->subject;
        $name = preg_replace('/^(?:AUTOMATED|NO-REPLY)\s*\|\s*/i', '', $message->fromName) ?? $message->fromName;
        $name = trim($name) ?: 'Project Alpha';
        $hasReplyTo = trim((string)$message->replyTo) !== '';
        $noReply = !empty($config['email_no_reply_notice_enabled']) && !$hasReplyTo;
        $message->subject = '[Automated] ' . $title;
        $message->fromName = ($noReply ? 'NO-REPLY | ' : 'AUTOMATED | ') . $name;
        if ($message->presentationApplied) return;

        $guidance = $hasReplyTo
            ? 'This message was delivered automatically. Replies go to your document contact at ' . $message->replyTo . '.'
            : 'This message was delivered automatically by ' . $name . '.';
        if ($noReply) {
            $guidance = trim((string)($config['email_no_reply_notice_text'] ?? ''))
                ?: 'This is an automated message. Please do not reply to this email.';
        }
        $noticeTitle = $noReply
            ? 'Automated notification — please do not reply'
            : 'Automated notification';
        if (!$message->isHtml) {
            $message->body = mb_strtoupper($noticeTitle) . "\n" . $guidance . "\n\n" . $title . "\n\n" . $message->body;
        } else {
            $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $body = $message->body;
            // Callers normally supply fragments. Keep full-document content inside
            // one valid email document rather than nesting html/head/body elements.
            $headStyles = '';
            if (preg_match('/<body\b[^>]*>(.*)<\/body\s*>/is', $body, $match)) {
                if (preg_match('/<head\b[^>]*>(.*?)<\/head\s*>/is', $body, $head)
                    && preg_match_all('/<style\b[^>]*>.*?<\/style\s*>/is', $head[1], $styles)) {
                    $headStyles = implode("\n", $styles[0]);
                }
                $body = $match[1];
            }
            $message->body = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">' . $headStyles
                . '</head><body style="margin:0;padding:0;background-color:#f4f6f8;color:#172b45;font-family:Arial,Helvetica,sans-serif">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8"><tr><td align="center" style="padding:24px 12px">'
                . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background-color:#ffffff;border:1px solid #dce2e8;border-radius:8px">'
                . '<tr><td style="padding:20px 24px 0">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fff4ce;border:1px solid #e8b339;border-radius:8px"><tr>'
                . '<td style="padding:16px;text-align:center;color:#664d03;font-size:14px;line-height:1.6;overflow-wrap:anywhere">'
                . '<strong>' . $esc($noticeTitle) . '</strong><br>' . $esc($guidance)
                . '</td></tr></table></td></tr>'
                . '<tr><td style="padding:24px 24px 16px;border-bottom:1px solid #e5e7eb">'
                . '<p style="margin:0 0 12px;font-size:14px;color:#475569">' . $esc($name) . '</p>'
                . '<h1 style="margin:0;font-size:22px;line-height:1.4;color:#172b45;overflow-wrap:anywhere">' . $esc($title) . '</h1>'
                . '</td></tr>'
                . '<tr><td style="padding:24px;font-size:15px;line-height:1.7;overflow-wrap:anywhere">' . $body . '</td></tr>'
                . '</table></td></tr></table></body></html>';
        }
        $message->presentationApplied = true;
    }
}
