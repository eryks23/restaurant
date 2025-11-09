<?php
// smtp.php
// Bezpieczny i zgodny zlecenie klienta wrapper do wysyłania maili przez SMTP

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php'; // PHPMailer autoload

class Mailer
{
    private PHPMailer $mail;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);

        try {
            // Ustawienia serwera SMTP
            $this->mail->isSMTP();
            $this->mail->Host       = getenv('SMTP_HOST') ?: 'smtp.example.com';
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = getenv('SMTP_USERNAME') ?: 'user@example.com';
            $this->mail->Password   = getenv('SMTP_PASSWORD') ?: 'secret';
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // STARTTLS
            $this->mail->Port       = getenv('SMTP_PORT') ?: 587;

            // Ustawienia wiadomości
            $this->mail->CharSet = 'UTF-8';
            $this->mail->setFrom(
                getenv('SMTP_FROM_EMAIL') ?: 'noreply@example.com',
                getenv('SMTP_FROM_NAME') ?: 'Reservation System'
            );
        } catch (Exception $e) {
            error_log('Mailer init error: ' . $e->getMessage());
            throw new Exception('Błąd inicjalizacji SMTP');
        }
    }

    /**
     * Wysyła maila
     * @param string|array $to Email lub tablica emaili
     * @param string $subject Temat wiadomości
     * @param string $body HTML treść
     * @param string|null $altBody Treść tekstowa (opcjonalnie)
     * @return bool
     * @throws Exception
     */
    public function sendMail($to, string $subject, string $body, ?string $altBody = null): bool
    {
        try {
            $this->mail->clearAddresses(); // czyść poprzednie adresy
            if (is_array($to)) {
                foreach ($to as $email) {
                    $this->mail->addAddress($email);
                }
            } else {
                $this->mail->addAddress($to);
            }

            $this->mail->isHTML(true);
            $this->mail->Subject = $subject;
            $this->mail->Body    = $body;
            $this->mail->AltBody = $altBody ?? strip_tags($body);

            return $this->mail->send();
        } catch (Exception $e) {
            error_log('Mailer send error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Możliwość dodania załącznika
     * @param string $filePath
     * @param string|null $name
     * @return void
     */
    public function addAttachment(string $filePath, ?string $name = null): void
    {
        if (file_exists($filePath)) {
            $this->mail->addAttachment($filePath, $name);
        } else {
            error_log("Mailer: Attachment file not found: $filePath");
        }
    }
}

// Przykładowe użycie (do testów, usuń w produkcji):
// $mailer = new Mailer();
// $mailer->sendMail('klient@example.com', 'Potwierdzenie rezerwacji', '<h1>Twoja rezerwacja jest potwierdzona</h1>');
