<?php
/**
 * Outgoing mail, over SMTP, through PHPMailer.
 *
 * One entry point — send_mail() — so every message the app sends is built,
 * authenticated and logged the same way. The credentials live in
 * config/config.local.php (see SMTP_* in config/config.php); nothing here reads
 * anything from the request.
 *
 * PHP's own mail() is deliberately not used as a fallback: on XAMPP it is not
 * configured at all, and on shared hosting it lands in spam often enough that a
 * "we sent you a link" that quietly went nowhere is worse than an honest error.
 */
require_once __DIR__ . '/../config/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Is there enough configuration here to attempt a send at all?
 *
 * SMTP_FROM is checked as an address, not merely as non-empty. Putting a
 * display name there — "Univo Chat" rather than the mailbox — is an easy
 * mistake to make, and PHPMailer's answer to it is an exception at send time
 * that reaches the user as "could not be sent, please try again later". That
 * reads as a hiccup worth retrying, when nothing about it will ever succeed.
 * Failing here instead says it is the configuration, not the network.
 */
function mail_configured(): bool {
    return SMTP_ENABLED
        && SMTP_HOST !== ''
        && filter_var(SMTP_FROM, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Composer's autoloader, loaded once and only when a mail is actually being
 * sent. Absent when somebody has copied the app without running `composer
 * install`, which mail_available() reports rather than fatalling.
 */
function mail_autoload(): bool {
    static $loaded = null;
    if ($loaded !== null) return $loaded;

    $autoload = APP_ROOT . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    return $loaded = class_exists(PHPMailer::class);
}

/** Can this installation send mail right now — configured *and* installed? */
function mail_available(): bool {
    return mail_configured() && mail_autoload();
}

/**
 * Send one HTML mail.
 *
 * @param string $to      Recipient address.
 * @param string $toName  Display name for the recipient.
 * @param string $subject
 * @param string $html    The body. A plain-text alternative is derived from it.
 * @return array{ok: bool, msg: string}
 */
function send_mail(string $to, string $toName, string $subject, string $html): array {
    if (!mail_configured()) {
        return ['ok' => false, 'msg' => 'Email is not configured on this server.'];
    }
    if (!mail_autoload()) {
        error_log('[UnivoChat] PHPMailer is missing — run `composer install` in ' . APP_ROOT);
        return ['ok' => false, 'msg' => 'The mail library is not installed on this server.'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'That email address is not valid.'];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = SMTP_USER !== '';
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->CharSet    = PHPMailer::CHARSET_UTF8;
        $mail->Timeout    = SMTP_TIMEOUT;
        $mail->SMTPDebug  = SMTP_DEBUG ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
        // The conversation with the server goes to the error log, never to the
        // page: a debug trace printed into a response would leak the password.
        $mail->Debugoutput = static function (string $line): void {
            error_log('[UnivoChat SMTP] ' . rtrim($line));
        };

        // '' means "plain SMTP on port 25" — valid for a local relay, and the
        // only case where neither STARTTLS nor implicit TLS is wanted.
        if (SMTP_SECURE === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (SMTP_SECURE === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to, $toName !== '' ? $toName : $to);
        if (SMTP_REPLY_TO !== '') {
            $mail->addReplyTo(SMTP_REPLY_TO, SMTP_FROM_NAME);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = mail_html_to_text($html);

        $mail->send();
        return ['ok' => true, 'msg' => 'Sent.'];
    } catch (MailerException $e) {
        error_log('[UnivoChat] Mail to ' . $to . ' failed: ' . $mail->ErrorInfo);
        return ['ok' => false, 'msg' => 'The email could not be sent. Please try again later.'];
    } catch (Throwable $e) {
        error_log('[UnivoChat] Mail to ' . $to . ' failed: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'The email could not be sent. Please try again later.'];
    }
}

/**
 * Plain-text alternative for an HTML body.
 *
 * PHPMailer ships html2text of its own, but it drops the href behind a link —
 * and in a password-reset mail the link *is* the message, so a text part that
 * says "Reset my password" and nothing else is useless.
 */
function mail_html_to_text(string $html): string {
    $text = preg_replace('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2: $1', $html) ?? $html;
    $text = preg_replace('#</(p|div|tr|h[1-6]|li)>#i', "\n", $text) ?? $text;
    $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Collapse runs of blank lines, but keep the paragraph breaks.
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text) ?? $text;

    return trim($text);
}

// ─── Templates ────────────────────────────────────────────────────────────────
/**
 * The shell every message shares.
 *
 * Table-based and inline-styled on purpose: mail clients strip <style> blocks,
 * ignore flexbox, and Outlook still lays out with tables.
 */
function mail_layout(string $heading, string $bodyHtml): string {
    $app = e(APP_NAME);

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      . '<body style="margin:0;padding:0;background:#f0f2f5;">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f5;padding:32px 12px;">'
      . '<tr><td align="center">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
      . 'style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;'
      . 'font-family:Segoe UI,system-ui,-apple-system,sans-serif;box-shadow:0 4px 24px rgba(0,0,0,.08);">'
      . '<tr><td style="background:#2450DC;padding:22px 28px;color:#ffffff;font-size:20px;font-weight:700;">'
      . '⚡ ' . $app . '</td></tr>'
      . '<tr><td style="padding:28px;color:#1C1C1E;font-size:15px;line-height:1.65;">'
      . '<h1 style="margin:0 0 14px;font-size:19px;color:#1C1C1E;">' . e($heading) . '</h1>'
      . $bodyHtml
      . '</td></tr>'
      . '<tr><td style="padding:16px 28px 24px;color:#8696A0;font-size:12px;line-height:1.6;border-top:1px solid #E9EAEB;">'
      . 'This is an automated message from ' . $app . '. Please do not reply to it.'
      . '</td></tr>'
      . '</table></td></tr></table></body></html>';
}

/** The password-reset mail: one link, one deadline, one way to ignore it. */
function password_reset_email(string $name, string $link, int $minutes): string {
    $safeLink = e($link);

    return mail_layout('Reset your password',
        '<p style="margin:0 0 14px;">Hi ' . e($name !== '' ? $name : 'there') . ',</p>'
      . '<p style="margin:0 0 20px;">We received a request to reset the password for your '
      . e(APP_NAME) . ' account. Choose a new one using the button below.</p>'
      . '<p style="margin:0 0 22px;">'
      . '<a href="' . $safeLink . '" '
      . 'style="display:inline-block;background:#2450DC;color:#ffffff;text-decoration:none;'
      . 'padding:13px 26px;border-radius:10px;font-weight:600;font-size:15px;">Reset my password</a></p>'
      . '<p style="margin:0 0 14px;color:#54656F;font-size:13px;">'
      . 'This link expires in <strong>' . (int)$minutes . ' minutes</strong> and can only be used once.</p>'
      . '<p style="margin:0 0 8px;color:#54656F;font-size:13px;">'
      . 'If the button does not work, copy this address into your browser:</p>'
      . '<p style="margin:0 0 20px;word-break:break-all;font-size:12px;">'
      . '<a href="' . $safeLink . '" style="color:#2450DC;">' . $safeLink . '</a></p>'
      . '<p style="margin:0;color:#54656F;font-size:13px;">'
      . 'Did not request this? You can safely ignore this email — your password will not change.</p>'
    );
}
