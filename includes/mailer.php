<?php
declare(strict_types=1);

/**
 * mailer.php
 *
 * Bezpieczny wrapper dla PHPMailer używany w projekcie Vectron UTK SIM.
 * - Wymaga PHPMailer (composer autoload).
 * - Korzysta z ENV (patrz config.php) lub stałych: SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL, SMTP_FROM_NAME.
 * - Funkcje:
 *     send_email($to, $subject, $body_html, $body_text = '', $attachments = [], $opts = [])
 *     reservation_confirmation_client(array $reservation)
 *     reservation_notification_admin(array $reservation)
 *     payment_failed(array $reservation)
 *
 * Uwaga:
 * - Plik NIE wykonuje wysyłki przy include.
 * - Logi błędów zapisywane do pliku zdefiniowanego przez MAIL_LOG_PATH env lub APP_LOG_DIR . '/mail.log'.
 */

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    // Nie fatalnie — pozwalamy na include, ale funkcje będą rzucać, jeśli PHPMailer nie jest dostępny.
    // Rekomendacja: zainstaluj phpmailer/phpmailer przez Composer.
    // composer require phpmailer/phpmailer
}

// -- helper env (bez nadpisywania jeśli już zdefiniowane) ----------------
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $v = getenv($key);
        if ($v !== false) return $v;
        if (isset($_ENV[$key])) return $_ENV[$key];
        if (isset($_SERVER[$key])) return $_SERVER[$key];
        return $default;
    }
}

// -- helper esc (bezpieczne escape do szablonów) -------------------------
if (!function_exists('mailer_esc')) {
    function mailer_esc($s): string {
        if ($s === null) return '';
        if (is_array($s)) return '';
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// -- LOG file path -------------------------------------------------------
$MAIL_LOG_PATH = env('MAIL_LOG_PATH', (defined('APP_LOG_DIR') ? rtrim(APP_LOG_DIR, '/\\') . '/mail.log' : __DIR__ . '/logs/mail.log'));

// ensure log dir exists (best-effort)
$logDir = dirname($MAIL_LOG_PATH);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

// -- require PHPMailer if available -------------------------------------
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// -- main send_email implementation -------------------------------------
if (!function_exists('send_email')) {
    /**
     * send_email
     *
     * @param string|array $to Single email or array of emails.
     * @param string $subject
     * @param string $body_html
     * @param string $body_text
     * @param array $attachments array of file paths or ['path'=>'/x.pdf','name'=>'x.pdf']
     * @param array $opts optional:
     *    - from_email, from_name, reply_to (string or [email,name]), cc, bcc, smtp_options (array), max_retries (int)
     * @return bool
     */
    function send_email($to, string $subject, string $body_html, string $body_text = '', array $attachments = [], array $opts = []): bool
    {
        global $MAIL_LOG_PATH;

        // Ensure PHPMailer exists
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log("[mailer] PHPMailer not found. Install phpmailer/phpmailer via Composer.");
            return false;
        }

        // Normalize recipients
        $recipients = [];
        if (is_array($to)) {
            foreach ($to as $t) {
                if (is_string($t) && trim($t) !== '') $recipients[] = $t;
                elseif (is_array($t) && !empty($t['email'])) $recipients[] = $t['email'];
            }
        } elseif (is_string($to) && trim($to) !== '') {
            $recipients[] = $to;
        }

        if (count($recipients) === 0) {
            error_log("[mailer] No recipients provided.");
            return false;
        }

        $maxRetries = (int)($opts['max_retries'] ?? 3);
        $smtpHost = env('SMTP_HOST', env('MAIL_HOST', ''));
        $smtpPort = (int)env('SMTP_PORT', env('MAIL_PORT', 587));
        $smtpUser = env('SMTP_USERNAME', env('SMTP_USER', ''));
        $smtpPass = env('SMTP_PASSWORD', env('SMTP_PASS', ''));
        $smtpSecure = env('SMTP_SECURE', 'tls'); // 'tls' or 'ssl' or empty
        $fromEmail = $opts['from_email'] ?? env('SMTP_FROM_EMAIL', env('MAIL_FROM', 'noreply@example.com'));
        $fromName = $opts['from_name'] ?? env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'Vectron UTK SIM'));
        $replyTo = $opts['reply_to'] ?? env('SMTP_REPLY_TO', $fromEmail);
        $cc = $opts['cc'] ?? [];
        $bcc = $opts['bcc'] ?? [];

        // Instantiate PHPMailer
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        // Configure SMTP
        try {
            $mail->isSMTP();
            $mail->SMTPAuth = true;
            $mail->Host = $smtpHost;
            $mail->Port = $smtpPort;
            if (!empty($smtpSecure) && in_array(strtolower($smtpSecure), ['ssl','tls'])) {
                $mail->SMTPSecure = $smtpSecure === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            if ($smtpUser !== '') $mail->Username = $smtpUser;
            if ($smtpPass !== '') $mail->Password = $smtpPass;

            // Optional SMTP options (e.g. allow self-signed certs in dev)
            if (!empty($opts['smtp_options']) && is_array($opts['smtp_options'])) {
                $mail->SMTPOptions = $opts['smtp_options'];
            }

            $mail->setFrom($fromEmail, $fromName);
            // Reply-to
            if (!empty($replyTo)) {
                if (is_array($replyTo) && !empty($replyTo[0])) {
                    $mail->addReplyTo($replyTo[0], $replyTo[1] ?? '');
                } elseif (is_string($replyTo)) {
                    $mail->addReplyTo($replyTo);
                }
            }

            // Recipients
            foreach ($recipients as $r) {
                $mail->addAddress($r);
            }

            // CC & BCC
            if (!empty($cc)) {
                foreach ((array)$cc as $c) {
                    if (is_string($c)) $mail->addCC($c);
                    elseif (is_array($c) && !empty($c['email'])) $mail->addCC($c['email'], $c['name'] ?? '');
                }
            }
            if (!empty($bcc)) {
                foreach ((array)$bcc as $b) {
                    if (is_string($b)) $mail->addBCC($b);
                    elseif (is_array($b) && !empty($b['email'])) $mail->addBCC($b['email'], $b['name'] ?? '');
                }
            }

            // Subject & body
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $body_html;
            $mail->AltBody = $body_text ?: strip_tags($body_html);

            // Attachments (validate file paths)
            foreach ($attachments as $att) {
                if (is_string($att) && is_file($att)) {
                    $mail->addAttachment($att);
                } elseif (is_array($att) && !empty($att['path']) && is_file($att['path'])) {
                    $mail->addAttachment($att['path'], $att['name'] ?? null);
                }
                // if file doesn't exist -> skip silently (don't expose paths in logs)
            }

            // Send with retries (exponential backoff)
            $attempt = 0;
            $lastExceptionMessage = null;
            while ($attempt < $maxRetries) {
                try {
                    $attempt++;
                    if ($mail->send()) {
                        // success
                        return true;
                    }
                } catch (\PHPMailer\PHPMailer\Exception $e) {
                    $lastExceptionMessage = $e->getMessage();
                    // wait a bit before retry (avoid long sleeps in web request; cap small)
                    if ($attempt < $maxRetries) {
                        usleep(100000 * (2 ** ($attempt - 1))); // 100ms, 200ms, 400ms...
                    }
                }
            }

            // If we get here - all retries failed
            $logLine = sprintf("[%s] Mail send failed to %s subject=%s attempts=%d error=%s\n",
                gmdate('c'),
                implode(',', $recipients),
                $subject,
                $attempt,
                $lastExceptionMessage ?? 'unknown'
            );
            @file_put_contents($GLOBALS['MAIL_LOG_PATH'] ?? $MAIL_LOG_PATH, $logLine, FILE_APPEND | LOCK_EX);
            return false;

        } catch (\Exception $e) {
            // catastrophic config error
            $msg = sprintf("[%s] Mailer configuration error: %s\n", gmdate('c'), $e->getMessage());
            @file_put_contents($GLOBALS['MAIL_LOG_PATH'] ?? $MAIL_LOG_PATH, $msg, FILE_APPEND | LOCK_EX);
            return false;
        }
    }
}

// -- High-level mail helpers (use in app) --------------------------------
if (!function_exists('reservation_confirmation_client')) {
    /**
     * reservation_confirmation_client
     * Sends reservation confirmation to client.
     * $reservation keys expected: name, email, reservation_code, date, pdf_link (optional), phone (optional)
     */
    function reservation_confirmation_client(array $reservation): bool
    {
        // Basic sanitization
        $to = $reservation['email'] ?? null;
        if (empty($to)) return false;

        $name = mailer_esc($reservation['name'] ?? '');
        $code = mailer_esc($reservation['reservation_code'] ?? '');
        $date = mailer_esc($reservation['date'] ?? '');
        $pdf = $reservation['pdf_link'] ?? null;

        $subject = "Potwierdzenie rezerwacji - {$code}";

        $body_html = "<!doctype html><html><body>";
        $body_html .= "<h2>Potwierdzenie rezerwacji</h2>";
        $body_html .= "<p>Dzień dobry {$name},</p>";
        $body_html .= "<p>Dziękujemy za rezerwację jazdy na symulatorze Vectron UTK SIM. Oto szczegóły rezerwacji:</p>";
        $body_html .= "<ul>";
        $body_html .= "<li><strong>Data:</strong> {$date}</li>";
        $body_html .= "<li><strong>Numer rezerwacji:</strong> {$code}</li>";
        if ($reservation['phone'] ?? false) $body_html .= "<li><strong>Telefon:</strong> " . mailer_esc($reservation['phone']) . "</li>";
        $body_html .= "</ul>";
        if ($pdf) {
            $body_html .= "<p>Twój dokument (bilet/potwierdzenie) dostępny jest tutaj: <a href=\"" . htmlspecialchars($pdf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\">Pobierz PDF</a></p>";
        }
        $body_html .= "<p>" . (defined('GDPR_NOTICE') ? mailer_esc(GDPR_NOTICE) : mailer_esc(env('GDPR_NOTICE', 'Informujemy, że Twoje dane osobowe są przetwarzane zgodnie z polityką prywatności.'))) . "</p>";
        $body_html .= "<p>Pozdrawiamy,<br/>" . mailer_esc(env('SITE_NAME', 'Vectron UTK SIM')) . "</p>";
        $body_html .= "</body></html>";

        $body_text = "Potwierdzenie rezerwacji\n\nData: {$date}\nNumer rezerwacji: {$code}\n";
        if ($pdf) $body_text .= "PDF: {$pdf}\n\n";
        $body_text .= (defined('GDPR_NOTICE') ? GDPR_NOTICE : env('GDPR_NOTICE', ''));

        // Send
        return send_email($to, $subject, $body_html, $body_text);
    }
}

if (!function_exists('reservation_notification_admin')) {
    /**
     * reservation_notification_admin
     * Notify admin about a new reservation.
     * $reservation keys expected: name, email, reservation_code, date, phone
     */
    function reservation_notification_admin(array $reservation): bool
    {
        $adminEmail = env('ADMIN_EMAIL', 'admin@example.com');
        $subject = "Nowa rezerwacja: " . ($reservation['reservation_code'] ?? '---');

        $body_html = "<!doctype html><html><body>";
        $body_html .= "<h2>Nowa rezerwacja</h2>";
        $body_html .= "<ul>";
        $body_html .= "<li><strong>Imię i nazwisko:</strong> " . mailer_esc($reservation['name'] ?? '') . "</li>";
        $body_html .= "<li><strong>E-mail:</strong> " . mailer_esc($reservation['email'] ?? '') . "</li>";
        $body_html .= "<li><strong>Data:</strong> " . mailer_esc($reservation['date'] ?? '') . "</li>";
        $body_html .= "<li><strong>Numer rezerwacji:</strong> " . mailer_esc($reservation['reservation_code'] ?? '') . "</li>";
        if (!empty($reservation['phone'])) $body_html .= "<li><strong>Telefon:</strong> " . mailer_esc($reservation['phone']) . "</li>";
        $body_html .= "</ul>";
        $body_html .= "<p>Sprawdź panel administracyjny, aby zarządzać rezerwacjami.</p>";
        $body_html .= "</body></html>";

        $body_text = "Nowa rezerwacja\n\nImię i nazwisko: " . ($reservation['name'] ?? '') . "\nE-mail: " . ($reservation['email'] ?? '') . "\nData: " . ($reservation['date'] ?? '') . "\nKod: " . ($reservation['reservation_code'] ?? '') . "\n";

        return send_email($adminEmail, $subject, $body_html, $body_text);
    }
}

if (!function_exists('payment_failed')) {
    /**
     * payment_failed
     * Notify client about payment failure (and optionally admin).
     */
    function payment_failed(array $reservation): bool
    {
        $to = $reservation['email'] ?? null;
        if (empty($to)) return false;

        $code = mailer_esc($reservation['reservation_code'] ?? '');
        $subject = "Problem z płatnością - rezerwacja {$code}";

        $body_html = "<!doctype html><html><body>";
        $body_html .= "<h2>Problem z płatnością</h2>";
        $body_html .= "<p>Wystąpił problem z płatnością związanym z Twoją rezerwacją <strong>{$code}</strong>. Prosimy o kontakt lub ponowną próbę dokonania płatności.</p>";
        $body_html .= "<p>" . (defined('GDPR_NOTICE') ? mailer_esc(GDPR_NOTICE) : mailer_esc(env('GDPR_NOTICE', ''))) . "</p>";
        $body_html .= "</body></html>";

        $body_text = "Problem z płatnością\n\nNumer rezerwacji: {$code}\n";

        $clientSent = send_email($to, $subject, $body_html, $body_text);

        // Optionally notify admin as well
        $adminEmail = env('ADMIN_EMAIL', 'admin@example.com');
        $adminSubject = "Problem z płatnością dla rezerwacji {$code}";
        $adminBody = "Problem z płatnością dla rezerwacji: {$code}\nKlient: " . ($reservation['name'] ?? '') . " (" . ($reservation['email'] ?? '') . ")\n";

        $adminSent = send_email($adminEmail, $adminSubject, nl2br(htmlspecialchars($adminBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), $adminBody);

        return ($clientSent || $adminSent);
    }
}

// -- end of file ---------------------------------------------------------
