<?php
declare(strict_types=1);

/**
 * pdf_helper.php
 *
 * Funkcje do generowania i zarządzania PDF z danymi rezerwacji.
 * Wymaga: dompdf/dompdf (Composer). Autoload Composera musi być załadowany w aplikacji.
 *
 * Główne funkcje:
 *  - generate_reservation_pdf(array $reservation, ?string $savePath = null): string
 *      -> generuje PDF i zwraca ścieżkę do zapisanego pliku (jeśli $savePath podano) lub tymczasowy plik
 *  - stream_reservation_pdf(array $reservation, string $filename = null): void
 *      -> wysyła PDF do przeglądarki (z nagłówkami)
 *  - generate_reservation_pdf_by_id(PDO $pdo, int $reservationId, ?string $savePath = null): string
 *      -> pobiera rezerwację z DB i generuje PDF
 *  - ensure_pdf_dir(): string
 *      -> zwraca bezpieczny katalog do zapisu PDF (tworzy jeśli potrzeba)
 *  - delete_pdf(string $path): bool
 *
 * Bezpieczeństwo i praktyki:
 *  - Pliki PDF zapisywane są domyślnie poza webroot (katalog configurable przez env PDF_DIR).
 *  - Nie wykonujemy żadnych operacji przy include; wszystko jest w funkcjach.
 *  - Pamiętaj by zabezpieczyć dostęp do wygenerowanych plików (tokeny / admin only).
 *
 * Konfiguracja środowiska (opcjonalna):
 *  - PDF_DIR (pełna ścieżka do katalogu zapisu PDF)
 *  - APP_TZ (strefa czasowa do formatowania dat)
 *  - SITE_URL (używane w szablonie np. dla logo)
 *
 * Uwaga: funkcje oczekują, że dane rezerwacji będą w postaci tablicy asocjacyjnej, np:
 *  [
 *    'id' => 123,
 *    'reservation_code' => 'VECTRON-20251102-1A2B',
 *    'name' => 'Jan Kowalski',
 *    'email' => 'jan.kowalski@example.com',
 *    'phone' => '+48 600 000 000',
 *    'start_at' => 1730764800, // unix timestamp
 *    'duration_minutes' => 60,
 *    'status' => 'paid',
 *    'created_at' => 1730761200,
 *  ]
 */

use Dompdf\Dompdf;
use Dompdf\Options;

if (!function_exists('env')) {
    /**
     * Proste pobranie zmiennej środowiskowej (fallbacky)
     */
    function env(string $key, $default = null) {
        $v = getenv($key);
        return $v === false ? $default : $v;
    }
}

/**
 * Zwraca katalog do zapisu PDF - domyślnie poza webroot (/storage/pdfs).
 * Tworzy katalog z bezpiecznymi uprawnieniami jeśli nie istnieje.
 *
 * @return string Pełna ścieżka do katalogu (bez końcowego slash)
 * @throws RuntimeException
 */
function ensure_pdf_dir(): string
{
    // preferowana ścieżka z env, albo katalog storage/pdfs obok projektu
    $dir = env('PDF_DIR', __DIR__ . '/../storage/pdfs');
    $dir = rtrim($dir, "/\\");
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create PDF directory: {$dir}");
        }
        // jeśli fs supports, ustaw właściciela/grupy w deployie
    }
    // zabezpieczenie przed listowaniem (dla pewności)
    $indexFile = $dir . '/.noindex';
    if (!file_exists($indexFile)) {
        @file_put_contents($indexFile, "prevent directory listing\n");
    }
    return $dir;
}

/**
 * Bezpieczne html-escape (UTF-8)
 */
function pdf_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Renderuje prosty HTML szablon PDF (możesz go dostosować do własnego stylu).
 *
 * @param array $r reservation array
 * @return string HTML ready for Dompdf
 */
function render_reservation_html(array $r): string
{
    // ustawienie strefy czasowej do formatowania dat
    $tz = env('APP_TZ', date_default_timezone_get() ?: 'Europe/Bucharest');
    $dt = new DateTime('@' . ((int)($r['start_at'] ?? $r['created_at'] ?? time())));
    $dt->setTimezone(new DateTimeZone($tz));
    $startFormatted = $dt->format('Y-m-d H:i');

    $duration = (int)($r['duration_minutes'] ?? 0);
    $endAt = (int)($r['start_at'] ?? 0) + $duration * 60;
    $dtEnd = new DateTime('@' . $endAt);
    $dtEnd->setTimezone(new DateTimeZone($tz));
    $endFormatted = $dtEnd->format('Y-m-d H:i');

    $createdAt = isset($r['created_at']) ? (new DateTime('@' . (int)$r['created_at']))->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i') : '';

    $siteUrl = env('SITE_URL', '');
    $logoUrl = $siteUrl ? rtrim($siteUrl, '/') . '/assets/logo.png' : ''; // opcjonalne

    // Sanitizuj pola przed wstawieniem
    $code = pdf_h((string)($r['reservation_code'] ?? ''));
    $name = pdf_h((string)($r['name'] ?? ''));
    $email = pdf_h((string)($r['email'] ?? ''));
    $phone = pdf_h((string)($r['phone'] ?? ''));
    $status = pdf_h((string)($r['status'] ?? ''));

    // Minimalny szablon HTML - łatwy do stylizacji
    $html = <<<HTML
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Potwierdzenie rezerwacji {$code}</title>
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color:#222; }
    .header { display:flex; align-items:center; margin-bottom:20px; }
    .logo { height:60px; margin-right:15px; }
    .title { font-size:20px; font-weight:700; }
    .meta { margin-top:10px; margin-bottom:20px; }
    .box { border:1px solid #ddd; padding:12px; border-radius:6px; margin-bottom:12px; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:left; padding:6px 8px; border-bottom:1px solid #f0f0f0; }
    .small { font-size:11px; color:#666; }
    .center { text-align:center; }
  </style>
</head>
<body>
  <div class="header">
    <!-- logo optional: Dompdf może wymagać absolutnego URL lub obrazek w base64 -->
    HTML;

    if ($logoUrl !== '') {
        // jeśli logo jest zdalne i dostępne przez https, dompdf je załaduje; w razie problemów użyj base64 embed
        $html .= '<img class="logo" src="' . pdf_h($logoUrl) . '" alt="logo" />';
    }

    $html .= <<<HTML
    <div>
      <div class="title">Potwierdzenie rezerwacji — Vectron UTK SIM</div>
      <div class="small">Numer rezerwacji: <strong>{$code}</strong></div>
    </div>
  </div>

  <div class="box">
    <table>
      <tr><th>Imię i nazwisko</th><td>{$name}</td></tr>
      <tr><th>E-mail</th><td>{$email}</td></tr>
      <tr><th>Telefon</th><td>{$phone}</td></tr>
      <tr><th>Data rozpoczęcia</th><td>{$startFormatted}</td></tr>
      <tr><th>Data zakończenia</th><td>{$endFormatted}</td></tr>
      <tr><th>Czas trwania</th><td>{$duration} minut</td></tr>
      <tr><th>Status</th><td>{$status}</td></tr>
      <tr><th>Utworzono</th><td>{$createdAt}</td></tr>
    </table>
  </div>

  <div class="box small">
    <strong>Informacje:</strong>
    <p>Zachowaj ten dokument jako potwierdzenie rezerwacji. Prosimy o zabranie ze sobą dokumentu tożsamości. {$siteUrl}</p>
    <p>" . pdf_h(env('GDPR_NOTICE', 'Informujemy, że Twoje dane osobowe są przetwarzane zgodnie z polityką prywatności.')) . "</p>
  </div>

  <div class="center small">Dziękujemy za skorzystanie z Vectron UTK SIM</div>
</body>
</html>
HTML;

    return $html;
}

/**
 * Generuje PDF z tablicy rezerwacji (array) i zapisuje do pliku (jeśli $savePath podano)
 * lub do tymczasowego pliku i zwraca ścieżkę.
 *
 * @param array $reservation
 * @param string|null $savePath jeśli null -> zapis do wygenerowanego pliku w ensure_pdf_dir()
 * @return string pełna ścieżka do wygenerowanego pliku PDF
 * @throws RuntimeException
 */
function generate_reservation_pdf(array $reservation, ?string $savePath = null): string
{
    // załaduj dompdf (Composer autoload powinien być załadowany wcześniej w aplikacji)
    if (!class_exists('Dompdf\Dompdf')) {
        throw new RuntimeException('Dompdf not found. Install with: composer require dompdf/dompdf and include vendor/autoload.php');
    }

    // Przygotuj HTML
    $html = render_reservation_html($reservation);

    // Opcje Dompdf
    $options = new Options();
    $options->set('isRemoteEnabled', true); // jeśli używasz zdalnych obrazków (logo)
    $options->set('isHtml5ParserEnabled', true);
    // Możesz dostosować fonty, marginesy itp.
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);

    // Dompdf: format strony
    $dompdf->setPaper('A4', 'portrait');

    // renderuj
    $dompdf->render();

    // przygotuj nazwę pliku
    $code = preg_replace('/[^A-Z0-9_\-]/i', '_', (string)($reservation['reservation_code'] ?? 'reservation'));
    $filename = $code . '-' . date('Ymd_His') . '.pdf';

    // docelowy folder
    $dir = ensure_pdf_dir();

    // jeśli użytkownik podał ścieżkę pliku -> użyj jej (upewnij się, że katalog istnieje)
    if ($savePath !== null) {
        // jeśli podano katalog, a nie pełną ścieżkę pliku
        if (is_dir($savePath)) {
            $target = rtrim($savePath, "/\\") . DIRECTORY_SEPARATOR . $filename;
        } else {
            // jeśli savePath kończy się slash lub wygląda jak katalog, inaczej traktuj jako plik
            $target = $savePath;
        }
    } else {
        $target = $dir . DIRECTORY_SEPARATOR . $filename;
    }

    // zapisz plik
    $pdfOutput = $dompdf->output();
    $written = @file_put_contents($target, $pdfOutput, LOCK_EX);
    if ($written === false) {
        throw new RuntimeException("Failed to write PDF to {$target}");
    }

    // ustaw bezpieczne uprawnienia
    @chmod($target, 0640);

    return $target;
}

/**
 * Pobiera rezerwację z DB po id i generuje PDF.
 * Zwraca ścieżkę do zapisanego pliku.
 *
 * @param PDO $pdo
 * @param int $reservationId
 * @param string|null $savePath
 * @return string
 * @throws RuntimeException
 */
function generate_reservation_pdf_by_id(PDO $pdo, int $reservationId, ?string $savePath = null): string
{
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $reservationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException("Reservation not found: {$reservationId}");
    }

    // Ensure numeric fields are integers
    if (isset($row['start_at'])) $row['start_at'] = (int)$row['start_at'];
    if (isset($row['duration_minutes'])) $row['duration_minutes'] = (int)$row['duration_minutes'];
    if (isset($row['created_at'])) $row['created_at'] = (int)$row['created_at'];

    return generate_reservation_pdf($row, $savePath);
}

/**
 * Streamuje PDF do przeglądarki (nagłówki + treść).
 * Nie zapisuje pliku na dysku (chyba że wcześniej wygenerowany).
 *
 * @param array $reservation
 * @param string|null $downloadFilename opcjonalna nazwa pliku do pobrania (np. "ticket.pdf")
 * @return void
 */
function stream_reservation_pdf(array $reservation, ?string $downloadFilename = null): void
{
    $temp = generate_reservation_pdf($reservation, null); // zapis do default PDF_DIR
    if (!is_file($temp)) {
        throw new RuntimeException('Temporary PDF not found after generation.');
    }

    // nagłówki
    if (!headers_sent()) {
        header('Content-Type: application/pdf');
        $disposition = $downloadFilename ? 'attachment' : 'inline';
        $name = $downloadFilename ?? basename($temp);
        header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($name) . '"');
        header('Content-Length: ' . filesize($temp));
        header('Cache-Control: private, max-age=0, must-revalidate');
    }

    // wysyłka pliku
    readfile($temp);
    // opcjonalnie usuń tymczasowy plik po strumieniowaniu jeśli chcesz:
    // unlink($temp);
    exit;
}

/**
 * Usuń plik PDF (bezpiecznie).
 *
 * @param string $path
 * @return bool
 */
function delete_pdf(string $path): bool
{
    // zabezpieczenie: upewnij się, że plik znajduje się w katalogu PDF_DIR
    try {
        $dir = realpath(ensure_pdf_dir());
        $real = realpath($path);
        if ($real === false || strpos($real, $dir) !== 0) {
            // nie pozwalamy usuwać plików poza katalogiem PDF_DIR
            return false;
        }
        return @unlink($real);
    } catch (Exception $e) {
        error_log('delete_pdf error: ' . $e->getMessage());
        return false;
    }
}

/* EOF */
