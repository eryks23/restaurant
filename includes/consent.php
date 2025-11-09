<?php
declare(strict_types=1);

/**
 * consent.php
 *
 * Komponent RODO/zgody używany przy rezerwacjach.
 *
 * Eksportowane funkcje:
 *  - consent_get_client_ip(): string
 *  - consent_h(string): string
 *  - consent_default_policy_url(): string
 *  - consent_policy_version(): string
 *  - render_consent_checkbox(string $name = 'consent', array $opts = []): string
 *  - validate_consent(array $post, string $name = 'consent'): bool
 *  - consent_error_message(string $name = 'consent'): string
 *  - init_consent_table(PDO $pdo): void
 *  - record_consent(PDO $pdo, array $data): bool
 *  - record_consent_for_reservation(PDO $pdo, ?int $reservationId, array $post, string $name = 'consent'): bool
 *
 * Uwagi:
 *  - Plik nie wykonuje żadnych akcji przy include (brak efektów ubocznych).
 *  - Zapisuje 'meta' jako JSON (jeżeli przekazano tablicę).
 *  - Działa zarówno na SQLite jak i MySQL — dobiera odpowiednią składnię CREATE TABLE.
 */

/* --- ZABEZPIECZENIE: brak efektów ubocznych przy include --- */
if (php_sapi_name() !== 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit('403 - Access denied');
}

/* ---------- Helpers ---------- */

/**
 * Pobiera IP klienta z uwzględnieniem proxy.
 * @return string
 */
function consent_get_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return trim((string)$_SERVER['HTTP_X_REAL_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Może zawierać listę IP — bierzemy pierwszy (najbliższy klientowi)
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        return trim((string)$_SERVER['REMOTE_ADDR']);
    }
    return '0.0.0.0';
}

/**
 * Bezpieczne escape HTML.
 * @param string $s
 * @return string
 */
function consent_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Domyślny URL do polityki prywatności.
 * @return string
 */
function consent_default_policy_url(): string
{
    if (defined('SITE_URL') && filter_var(SITE_URL, FILTER_VALIDATE_URL)) {
        return rtrim(SITE_URL, '/') . '/polityka';
    }
    return '/polityka';
}

/**
 * Wersja polityki prywatności (stała POLICY_VERSION lub dzisiejsza data).
 * @return string
 */
function consent_policy_version(): string
{
    if (defined('POLICY_VERSION') && is_string(POLICY_VERSION) && POLICY_VERSION !== '') {
        return POLICY_VERSION;
    }
    return date('Y-m-d');
}

/* ---------- Render / Walidacja ---------- */

/**
 * Renderuje HTML checkboxa zgody RODO (accessible).
 *
 * Opcje (array $opts):
 *  - 'policy_url' => string
 *  - 'required' => bool
 *  - 'id' => string
 *  - 'label_before' => string
 *  - 'link_text' => string
 *  - 'target_blank' => bool
 *
 * @param string $name
 * @param array $opts
 * @return string
 */
function render_consent_checkbox(string $name = 'consent', array $opts = []): string
{
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    $id = $opts['id'] ?? ('consent_' . $safeName);
    $policyUrl = $opts['policy_url'] ?? consent_default_policy_url();
    $required = array_key_exists('required', $opts) ? (bool)$opts['required'] : true;
    $labelBefore = $opts['label_before'] ?? 'Wyrażam zgodę na przetwarzanie danych osobowych zgodnie z ';
    $linkText = $opts['link_text'] ?? 'polityką prywatności';
    $targetBlank = array_key_exists('target_blank', $opts) ? (bool)$opts['target_blank'] : true;

    $attrRequired = $required ? ' required' : '';
    $attrTarget = $targetBlank ? ' target="_blank" rel="noopener noreferrer"' : '';

    $html = '<div class="consent">';
    $html .= '<input type="checkbox" id="' . consent_h($id) . '" name="' . consent_h($name) . '" value="1" aria-describedby="' . consent_h($id) . '_desc"' . $attrRequired . ' />';
    $html .= '<label for="' . consent_h($id) . '">';
    $html .= consent_h($labelBefore);
    $html .= '<a href="' . consent_h($policyUrl) . '"' . $attrTarget . '>' . consent_h($linkText) . '</a>.';
    $html .= '</label>';
    $html .= '<span id="' . consent_h($id) . '_desc" class="consent-desc" aria-hidden="true"></span>';
    $html .= '</div>';

    return $html;
}

/**
 * Waliduje, czy podana zgoda została zaznaczona.
 *
 * Akceptowane wartości: "1", "on", "true", true
 *
 * @param array $post
 * @param string $name
 * @return bool
 */
function validate_consent(array $post, string $name = 'consent'): bool
{
    if (!array_key_exists($name, $post)) {
        return false;
    }
    $value = $post[$name];

    // Nie akceptujemy tablic (możliwy atak)
    if (is_array($value)) {
        return false;
    }

    // Przyjmij typowe wartości checkboxów
    if (is_bool($value)) {
        return $value === true;
    }

    // FILTER_VALIDATE_BOOLEAN dopuszcza "1","true","on","yes"
    $bool = filter_var((string)$value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $bool === true;
}

/**
 * Komunikat błędu gdy zgoda nie została udzielona (sanityzowany).
 *
 * @param string $name
 * @return string
 */
function consent_error_message(string $name = 'consent'): string
{
    return consent_h('Musisz wyrazić zgodę na przetwarzanie danych osobowych zgodnie z polityką prywatności.');
}

/* ---------- Persistencja zgody (dowód) ---------- */

/**
 * Inicjalizuje tabelę 'consents' jeśli nie istnieje.
 * Obsługa SQLite i MySQL (dobiera odpowiednią składnię autoincrement).
 *
 * Struktura kolumn:
 *  - id (PRIMARY KEY)
 *  - reservation_id (NULLABLE)
 *  - field_name
 *  - field_value
 *  - consent_at (UNIX TIMESTAMP)
 *  - ip
 *  - user_agent
 *  - policy_version
 *  - meta (tekst JSON lub JSON typ w MySQL)
 *
 * @param PDO $pdo
 * @return void
 * @throws PDOException
 */
function init_consent_table(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS consents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reservation_id INTEGER NULL,
    field_name TEXT NOT NULL,
    field_value TEXT,
    consent_at INTEGER NOT NULL,
    ip TEXT,
    user_agent TEXT,
    policy_version TEXT,
    meta TEXT
);
SQL;
    } else {
        // MySQL / MariaDB
        // meta jako JSON jeśli wersja MySQL to wspiera, inaczej TEXT
        $jsonType = 'JSON';
        // Nie zakładamy wersji, ale JSON jest powszechnie wspierany; w razie błędu można zmienić na TEXT.
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS consents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NULL,
    field_name VARCHAR(191) NOT NULL,
    field_value TEXT,
    consent_at INT NOT NULL,
    ip VARCHAR(45),
    user_agent TEXT,
    policy_version VARCHAR(64),
    meta {$jsonType} NULL,
    INDEX (consent_at),
    INDEX (reservation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    $pdo->exec($sql);
}

/**
 * Zapisuje dowód zgody w tabeli 'consents'.
 *
 * Wymagane klucze $data:
 *  - field_name (string)
 * Opcjonalne:
 *  - field_value (string|null)
 *  - reservation_id (int|null)
 *  - policy_version (string|null)
 *  - meta (array|null) - zostanie zserializowane do JSON
 *
 * Zwraca true jeżeli zapis powiódł się.
 *
 * @param PDO $pdo
 * @param array $data
 * @return bool
 * @throws InvalidArgumentException
 */
function record_consent(PDO $pdo, array $data): bool
{
    $fieldName = isset($data['field_name']) ? (string)$data['field_name'] : '';
    if ($fieldName === '') {
        throw new InvalidArgumentException('field_name is required for record_consent');
    }

    $fieldValue = array_key_exists('field_value', $data) && $data['field_value'] !== null ? (string)$data['field_value'] : null;
    $reservationId = isset($data['reservation_id']) && $data['reservation_id'] !== null ? (int)$data['reservation_id'] : null;
    $policyVersion = isset($data['policy_version']) && $data['policy_version'] !== null ? (string)$data['policy_version'] : consent_policy_version();
    $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : null;

    $ip = consent_get_client_ip();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $now = time();

    // Upewnij się, że tabela istnieje (bezpieczne w środowiskach bez migracji)
    init_consent_table($pdo);

    // Serializacja meta
    $metaJson = null;
    if ($meta !== null) {
        $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            // W razie problemu z JSON (np. cykliczne struktury) zapisujemy informację diagnostyczną
            $metaJson = json_encode(['error' => 'meta_encode_failed']);
        } else {
            $metaJson = $encoded;
        }
    }

    // Przygotuj zapytanie; używamy parametrów by uniknąć SQLi
    $sql = "INSERT INTO consents (reservation_id, field_name, field_value, consent_at, ip, user_agent, policy_version, meta)
            VALUES (:reservation_id, :field_name, :field_value, :consent_at, :ip, :user_agent, :policy_version, :meta)";

    try {
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':reservation_id' => $reservationId,
            ':field_name'     => $fieldName,
            ':field_value'    => $fieldValue,
            ':consent_at'     => $now,
            ':ip'             => $ip,
            ':user_agent'     => $ua,
            ':policy_version' => $policyVersion,
            ':meta'           => $metaJson,
        ]);
        return (bool)$result;
    } catch (PDOException $e) {
        // Loguj minimalnie - nie logujemy wrażliwych danych
        if (function_exists('error_log')) {
            error_log('record_consent PDOException: ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * Pomocnicza funkcja do szybkiego zapisu zgody powiązanej z rezerwacją
 * po walidacji formularza.
 *
 * @param PDO $pdo
 * @param int|null $reservationId
 * @param array $post $_POST-like
 * @param string $name nazwa pola checkbox
 * @return bool true jeśli zapisano (false jeśli brak zgody)
 */
function record_consent_for_reservation(PDO $pdo, ?int $reservationId, array $post, string $name = 'consent'): bool
{
    if (!validate_consent($post, $name)) {
        return false;
    }

    // Przykładowe meta — nie zapisujemy w meta wrażliwych danych bez potrzeby
    $meta = [
        'note' => 'consent during booking',
    ];

    try {
        return record_consent($pdo, [
            'reservation_id' => $reservationId,
            'field_name'     => $name,
            'field_value'    => '1',
            'policy_version' => consent_policy_version(),
            'meta'           => $meta,
        ]);
    } catch (InvalidArgumentException $e) {
        // Nie powinno mieć miejsca, bo wcześniej walidujemy
        return false;
    }
}

/* ---------- Koniec pliku consent.php ---------- */
