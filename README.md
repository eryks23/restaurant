# Vectron UTK SIM — Reservation & Payment Platform

> [DO UZUPEŁNIENIA: confirm final project name] This repository is currently named `restaurant` on GitHub, but the codebase itself implements a time-slot booking, voucher, and online payment system for a locomotive driving simulator experience ("Vectron UTK SIM", operated by **KG Rail**, Katowice, Poland — see `index.html`, `Rodo.html`, `database.sql`). The title above describes what the code actually does; rename the repository or update this header once the final product name is confirmed.

A self-service booking system for a single-location experience business: customers pick a time slot, apply a voucher, and pay online via Przelewy24; staff manage reservations from a back office.

## Table of Contents

- [Description](#description)
- [Key Features](#key-features)
- [Tech Stack](#tech-stack)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage / Quick Start](#usage--quick-start)
- [API Documentation](#api-documentation)
- [Project Structure](#project-structure)
- [Testing](#testing)
- [Known Limitations](#known-limitations)
- [Contributing](#contributing)
- [Contact / Author](#contact--author)
- [License](#license)

## Description

This project is a PHP/MySQL web application for taking online reservations for a fixed-capacity, slot-based experience (a Vectron locomotive cab simulator). Visitors browse available time slots on a static HTML/JS booking page, optionally apply a discount voucher, and are redirected to the Przelewy24 (P24) payment gateway to complete payment. A set of JSON API endpoints under `api/` handles availability lookups, voucher validation, booking creation, and payment lifecycle (initiation, gateway callback, confirmation). A back-office area under `admin/` provides reservation filtering, CSV/PDF export, and voucher management for staff. It is built for a single Polish business that needs GDPR-compliant consent tracking (`Rodo.html`, `includes/consent.php`) alongside the booking flow, without depending on a larger e-commerce or booking-engine framework.

## Key Features

- **Slot-based availability** — `api/get-availability.php` and `get_resefed_slot.php` return real-time, capacity-aware availability per day/month so the calendar UI never shows a fully booked slot as free.
- **Voucher / coupon discounts** — percentage or fixed-amount discounts with validity windows and redemption caps, applied both in the booking API (`api/create-booking.php`) and at the database level via the `book_slot` stored procedure (`database.sql`).
- **Przelewy24 payment integration** — transaction registration with SHA-384 signed payloads (`api/start-payment.php`), an MD5-signed legacy callback handler (`api/payment-callback.php`), and a separate confirmation endpoint with idempotent status updates (`payment-confirm.php`).
- **GDPR/RODO consent capture** — a reusable consent checkbox renderer, validator, and audit-trail writer (`includes/consent.php`) backed by a dedicated `consents` table, plus a full Polish-language privacy policy page (`Rodo.html`).
- **Transactional email** — HTML confirmation, admin-notification, and payment-failed templates (`templates/email/`, `templates/payment-*.html`) sent through a PHPMailer wrapper with retry/back-off (`includes/mailer.php`).
- **PDF generation hooks** — Dompdf-based helpers to render and stream a reservation confirmation as a PDF (`includes/pdf_helper.php`).
- **Back-office reservation dashboard** — filterable booking list with revenue/discount totals and CSV/PDF export links (`admin/dashboard.php`, `admin/export.php`).
- **Auditable schema** — UUID primary keys, automatic UUID-generation triggers, a coupon-redemption trigger, and the `book_slot` stored procedure for atomic, race-condition-safe booking (`database.sql`).
- **Optional zero-dependency frontend preview** — a tiny Flask server (`serwer.py`) serves the static site and a mocked availability endpoint without requiring PHP or MySQL, useful for quick UI iteration.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend language | PHP 8.0+ (procedural, with some OOP in `includes/smtp.php`) |
| Database | MySQL 5.7+ / MariaDB 10.2+ |
| Frontend | Static HTML5, vanilla CSS, vanilla JavaScript — no build step, no framework |
| Payments | Przelewy24 (P24) Transaction Register API, sandbox & production |
| Email | PHPMailer (installed via Composer, not vendored) |
| PDF generation | Dompdf (installed via Composer, not vendored) |
| Optional local preview server | Python 3 + Flask (`serwer.py`) |

## Requirements

- PHP **8.0 or later** (the code uses `str_starts_with()` / `str_contains()`, which require PHP 8.0+), with the `pdo_mysql`, `mysqli`, `mbstring`, `openssl`, `curl`, and `json` extensions enabled.
- MySQL **5.7+** or MariaDB **10.2+** (the schema in `database.sql` uses `JSON` columns and `SIGNAL`, which need these minimum versions).
- A web server capable of running PHP (Apache/Nginx + PHP-FPM, or PHP's built-in server for local development).
- A Przelewy24 merchant account (sandbox is sufficient for development) if you intend to test the payment flow.
- An SMTP account if you intend to test outgoing email.
- Python **3.9+** and `pip`, **only** if you use the optional static-preview server (`serwer.py`).

## Installation

```bash
# 1. Clone the repository
git clone https://github.com/eryks23/restaurant.git
cd restaurant
```

```bash
# 2. Create the database and import the schema
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS vectron CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p vectron < database.sql
```

> `database.sql` already contains a `CREATE DATABASE` statement for a database named `vectron`. Make sure the `DB_NAME` value you set in `.env` (next step) matches whatever database you actually import the schema into — see [Known Limitations](#known-limitations) for why this matters.

```bash
# 3. Copy and edit the environment file
cp .env.example .env
# then edit .env with your DB / SMTP / Przelewy24 credentials
```

```bash
# 4. (Optional) Install PHP dependencies needed for email and PDF generation
composer install
```

```bash
# 5a. Run the full PHP application locally
php -S localhost:8000
# open http://localhost:8000/index.html
```

```bash
# 5b. OR run the static frontend only, with mocked availability data (no PHP/MySQL needed)
python -m venv .venv && source .venv/bin/activate   # optional, recommended
pip install -r requirements.txt
python serwer.py
# open http://127.0.0.1:5000/
```

## Configuration

Configuration is read from environment variables, typically loaded from a `.env` file in the project root (several scripts — `includes/config.php`, `includes/db_connect.php`, `form-submit.php`, `api/start-payment.php` — implement their own minimal `.env` parser; there is no single shared loader). A reference file is provided at `.env.example`.

### Application

| Variable | Default in code | Description |
|---|---|---|
| `APP_ENV` | `production` | `development` enables verbose error display and file-based error logging. |
| `APP_TZ` | `Europe/Bucharest` | Timezone used for date/time formatting. **Verify this** — the business is based in Katowice, Poland (`Europe/Warsaw`); the literal default in `includes/config.php` is `Europe/Bucharest`, which is almost certainly a leftover from a different project. |
| `SITE_URL` | *(none, must be set)* | Public base URL; used to build P24 return/notify URLs and the privacy-policy link. |
| `SITE_NAME` | `Vectron UTK SIM` | Display name used in emails. |
| `APP_LOG_DIR` | `includes/logs` | Directory for application log files. |
| `APP_CSRF_TTL` | `3600` | CSRF token lifetime, in seconds. |
| `APP_RESERVATION_PREFIX` | `VECTRON` | Prefix used when generating reservation codes. |
| `SESSION_COOKIE_DOMAIN` | host part of `SITE_URL` | Cookie domain for PHP sessions. |
| `SESSION_SAMESITE` | `Lax` | `SameSite` attribute for the session cookie. |

### Database

| Variable | Default in code | Description |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL host. |
| `DB_PORT` | `3306` | MySQL port. |
| `DB_NAME` | varies by file (`vectron_sim`, `v`, `booking_db`, `vectron`) | **Set this explicitly** — see [Known Limitations](#known-limitations). |
| `DB_USER` | `root` / `vectron_user` (varies by file) | MySQL user. |
| `DB_PASS` | *(empty)* | MySQL password. |
| `DB_CHARSET` | `utf8mb4` | Connection charset. |

### SMTP / Mail

| Variable | Notes |
|---|---|
| `SMTP_HOST` | SMTP server hostname. |
| `SMTP_PORT` | Defaults to `587`. |
| `SMTP_USERNAME` | Some files instead check `SMTP_USER` — set both if unsure. |
| `SMTP_PASSWORD` | Some files instead check `SMTP_PASS` — set both if unsure. |
| `SMTP_FROM_EMAIL` | Falls back to `MAIL_FROM`. |
| `SMTP_FROM_NAME` | Falls back to `MAIL_FROM_NAME`. |
| `ADMIN_EMAIL` | Recipient for admin booking/payment notifications. |
| `GDPR_NOTICE` | Optional free-text legal notice appended to confirmation emails. |

### Przelewy24 (P24)

| Variable | Used by |
|---|---|
| `P24_MERCHANT_ID`, `P24_POS_ID`, `P24_CRC`, `P24_SANDBOX` | `includes/config.php` |
| `PRZELEWY24_MERCHANT_ID`, `PRZELEWY24_POS_ID`, `PRZELEWY24_CRC`, `PRZELEWY24_API_KEY` | `api/start-payment.php` (note the different variable name prefix) |
| `P24_SIGNATURE_KEY`, `P24_SECRET_KEY`, `P24_GATEWAY_IP` | `api/payment-callback.php` |

> The codebase uses **two different naming conventions** (`P24_*` vs `PRZELEWY24_*`) for what is conceptually the same configuration, because the payment flow is implemented twice (see [Known Limitations](#known-limitations)). Set both sets of variables until the implementations are unified.

## Usage / Quick Start

Check availability for a date range:

```bash
curl "http://localhost:8000/api/get-availability.php?from=2026-07-01&to=2026-07-07"
```

```json
{
  "ok": true,
  "count": 2,
  "slots": [
    {
      "id": 1,
      "date": "2026-07-01",
      "time": "10:00:00",
      "capacity": 1,
      "reserved_count": 0,
      "available": true
    }
  ]
}
```

Validate a voucher code:

```bash
curl -X POST http://localhost:8000/api/apply-voucher.php \
  -H "Content-Type: application/json" \
  -d '{"code": "SAVE10"}'
```

Create a booking (the booking form on `index.html` posts the same fields):

```bash
curl -X POST http://localhost:8000/api/create-booking.php \
  -d "firstName=Jan" \
  -d "lastName=Kowalski" \
  -d "email=jan.kowalski@example.com" \
  -d "phone=+48600000000" \
  -d "participants=1" \
  -d "slot_id=<calendar_slots.id from the availability response>" \
  -d "gdpr=1"
```

A successful response includes the new `reservation_id` and `booking_code`, which the frontend (`assets/js/booking.js`) then uses to kick off payment.

## API Documentation

All endpoints under `api/` return `application/json` unless noted otherwise.

### `GET /api/get-availability.php`

Returns slot availability, optionally filtered by date range.

| Param | Type | Required | Notes |
|---|---|---|---|
| `from`, `to` | `string` (`YYYY-MM-DD`) | No | Inclusive date range. `date` is also accepted as a single-day shorthand. |
| `debug` | `1` | No | Includes DB connection diagnostics in the response. |

**Response:** `{ ok, count, slots: [{ id, date, time, capacity, reserved_count, available }] }`

### `GET /get_resefed_slot.php`

Returns availability grouped by day and time for a given month, with timezone conversion.

| Param | Type | Required | Notes |
|---|---|---|---|
| `month` | `string` (`YYYY-MM`) | Yes | Returns `400` if malformed. |
| `location_id` | `int` | No | Defaults to `1`. |
| `tz` | `string` (IANA timezone) | No | Defaults to `UTC`; falls back silently to `UTC` if invalid. |

**Response:** `{ "<date>": { "<HH:MM>": { slot_id, is_full, booked_count, capacity, available_spots } } }`

### `POST /api/apply-voucher.php`

Validates a voucher code. **Note:** this endpoint currently checks a hardcoded list (`SAVE10`, `FIX50`, `EXPIRED`) rather than the `coupons` table — see [Known Limitations](#known-limitations).

| Param | Type | Required |
|---|---|---|
| `code` | `string` (JSON body) | Yes |

**Response:** `{ valid, code, type: "percent"|"fixed", amount, message }`

### `POST /api/create-booking.php`

Creates a user (if new), validates slot capacity, applies a voucher from the `coupons` table, and inserts a `pending` reservation.

| Param | Type | Required |
|---|---|---|
| `firstName`, `lastName` | `string` (min 2 chars) | Yes |
| `email` | `string` | Yes |
| `phone` | `string` | Yes |
| `participants` | `int` (1–50) | Yes |
| `slot_id` | `string` (UUID) | Yes |
| `applied_voucher` | `string` | No |
| `gdpr` | `1` | Yes |

**Response (200):** `{ success: true, reservation_id, booking_code, total_amount, currency }`
**Response (422):** `{ success: false, errors: { field: message } }`

### `POST /api/create-payment.php`

Creates/looks up a user, inserts a reservation and a `payments` row, and returns the form fields needed to redirect the browser to the P24 sandbox.

| Param | Type | Required |
|---|---|---|
| `name`, `email`, `phone` | `string` | `name`, `email` required |
| `amount_pln` | `float` | Yes, must be `> 0` |

**Response:** `{ ok, booking_id, amount_pln, amount_grosze, p24_url, p24_fields }`

### `POST /api/start-payment.php`

Registers a transaction with the live Przelewy24 Transaction Register API (not the sandbox redirect used by `create-payment.php`) and returns a redirect URL.

| Param | Type | Required |
|---|---|---|
| `booking_id`, `token` | `string` | Yes — `token` must match the stored booking token |
| `amount` | `numeric` (PLN) | Yes |
| `currency` | `string` (e.g. `PLN`) | Yes |
| `return_url`, `notify_url` | `string` (URL) | Yes |
| `return_json` | `1`/`true`/`yes` | No — forces a JSON response instead of an HTTP redirect |

**Response:** `{ redirect_url, gateway_token, payment_request_id, status }`, or a `302` redirect to `redirect_url`.

### `POST /api/payment-callback.php`

Server-to-server webhook consumed by Przelewy24 to confirm payment status. Validates the caller's IP against `P24_GATEWAY_IP` and the request signature against `P24_SECRET_KEY` before updating the booking status. Not intended to be called directly by the frontend.

### `GET|POST /payment-confirm.php`

User-facing payment confirmation page/endpoint. Verifies a `booking_id` + `token` pair against the `payments` table and reports whether the payment has been confirmed. Returns JSON if the request's `Accept` header includes `application/json` or `?format=json` is passed; otherwise renders a minimal HTML page.

### Core helper functions (`includes/`)

These are loaded via `includes/bootstrap.php` and used throughout the API/admin layers.

| Function | File | Signature |
|---|---|---|
| `env()` | `config.php`, `functions.php` | `env(string $key, mixed $default = null): mixed` |
| `json_response()` | `functions.php` | `json_response(mixed $data, int $code = 200): void` — sends JSON and exits |
| `generate_csrf_token()` | `functions.php` | `generate_csrf_token(): string` |
| `verify_csrf_token()` | `functions.php` | `verify_csrf_token(?string $token, bool $invalidateAfterUse = false): bool` |
| `sanitize_input()` | `functions.php` | `sanitize_input(mixed $data): mixed` — recursive trim + strip_tags |
| `validate_email()` | `functions.php` | `validate_email(string $email): bool` |
| `format_price()` | `functions.php` | `format_price(float\|int\|string $amount, string $currency = 'zł'): string` |
| `generate_reservation_code()` | `functions.php` | `generate_reservation_code(?string $prefix = null): string` |
| `send_email()` | `mailer.php` | `send_email($to, string $subject, string $body_html, string $body_text = '', array $attachments = [], array $opts = []): bool` |
| `reservation_confirmation_client()` | `mailer.php` | `reservation_confirmation_client(array $reservation): bool` |
| `reservation_notification_admin()` | `mailer.php` | `reservation_notification_admin(array $reservation): bool` |
| `validate_consent()` | `consent.php` | `validate_consent(array $post, string $name = 'consent'): bool` |
| `record_consent()` | `consent.php` | `record_consent(PDO $pdo, array $data): bool` |
| `admin_login()` | `auth.php` | `admin_login(string $username, string $password, ?string $csrf = null): array` |
| `is_admin()` | `auth.php` | `is_admin(): bool` |

## Project Structure

```
.
├── index.html                  # Public booking page (static HTML/CSS/JS)
├── login.html / login.php      # Customer login form + handler (separate from admin auth)
├── register.php                # Customer registration handler
├── Rodo.html                   # GDPR / privacy policy page (Polish)
├── form-submit.php             # Alternate booking form handler (mysqli-based)
├── get_resefed_slot.php        # Monthly slot-availability endpoint (PDO-based)
├── payment-confirm.php         # Customer-facing payment confirmation page
├── serwer.py                   # Optional Flask dev server (static frontend + mocked availability)
├── database.sql                # Full schema, triggers, stored procedure, sample data
├── server/
│   └── env.example             # Original, unconsolidated environment reference
├── api/                        # JSON API endpoints (see API Documentation)
│   ├── get-availability.php
│   ├── apply-voucher.php
│   ├── create-booking.php
│   ├── create-payment.php
│   ├── start-payment.php
│   └── payment-callback.php
├── includes/                   # Shared PHP libraries, loaded via bootstrap.php
│   ├── bootstrap.php           # Single entry point: loads config, db, functions, auth, etc.
│   ├── config.php               # Env loading, constants, security headers, session setup
│   ├── db_connect.php           # mysqli connection helper (db_connect())
│   ├── functions.php            # CSRF, JSON responses, sanitization, dates, templates
│   ├── auth.php                 # PDO-based admin auth, lockout, login attempts
│   ├── security.php             # Independent CSRF/rate-limit/session-hardening helpers
│   ├── consent.php              # GDPR consent rendering, validation, and audit trail
│   ├── mailer.php               # PHPMailer wrapper + high-level email helpers
│   ├── smtp.php                 # Alternative OOP `Mailer` class (PHPMailer)
│   ├── pdf_helper.php           # Dompdf-based reservation PDF generation
│   └── webhook_validator.php    # Standalone P24 webhook handler (see Known Limitations)
├── admin/                      # Back-office (work in progress — see Known Limitations)
│   ├── index.php                # Self-contained SQLite-based admin login
│   ├── dashboard.php            # Reservation list, filters, totals
│   ├── export.php               # CSV/PDF export
│   ├── vouchers.php             # Voucher management
│   ├── settings.php             # P24/app settings editor
│   ├── booking-edit.php         # Booking status/notes editor
│   └── logout.php
├── assets/
│   ├── css/style.css
│   ├── js/
│   │   ├── main.js              # Nav, general page behaviour
│   │   ├── calendar.js          # Slot picker / calendar UI
│   │   ├── booking.js           # Form validation + booking submission + payment handoff
│   │   ├── payment.js           # P24 redirect helper(s)
│   │   └── wyswietlanie-ceny.js # Price display logic
│   └── image/
└── templates/
    ├── email/
    │   ├── booking-confirmation.html
    │   └── admin-notification.html
    ├── payment-success.html
    └── payment-failed.html
```

## Testing

No automated test suite (PHPUnit, Pest, pytest, etc.) is included in this repository, and no test runner is configured.

To verify the application manually:

1. Import `database.sql` into a scratch database and confirm the sample rows in `reservations`, `prices`, and `coupons` are present.
2. Call `GET /api/get-availability.php` and confirm the sample slots from `database.sql` are returned.
3. Submit the booking form on `index.html` (or replay the `curl` example in [Usage / Quick Start](#usage--quick-start)) and confirm a row appears in `reservations` with `status = 'pending'`.
4. Use the Przelewy24 **sandbox** credentials to exercise `api/start-payment.php` end-to-end, then confirm the callback in `api/payment-callback.php` updates the booking status.
5. If you wire up Composer dependencies, send a test email via `reservation_confirmation_client()` against a sandbox SMTP account (e.g. Mailtrap) before pointing `SMTP_HOST` at a production mailbox.

If you add PHPUnit, a reasonable starting point is unit tests for the pure-logic helpers in `includes/functions.php` (`validate_email()`, `format_price()`, `generate_reservation_code()`) and `includes/consent.php` (`validate_consent()`), since they have no I/O side effects.

## Known Limitations

This codebase shows signs of having been assembled from multiple, independently written drafts of the same features. Before deploying, review the following:

- **Mixed database layers.** Some files use `mysqli` (`includes/db_connect.php`, `form-submit.php`, `api/create-payment.php`, `api/get-availability.php`), others assume a PDO connection in a `$pdo` variable (`api/create-booking.php`, `payment-confirm.php`, `includes/auth.php`, `includes/consent.php`). `get_db()` (expected to return a `PDO` instance) is referenced in `includes/auth.php` and `includes/webhook_validator.php` but is **never defined** anywhere in the repository — code paths that call it will fatal until you add it.
- **OS-specific path separators.** `api/create-payment.php`, `api/get-availability.php`, and `api/payment-callback.php` `require`/`include` `__DIR__ . '\includes\db_connect.php'` using a Windows-style backslash, which will fail on Linux/macOS. Use `__DIR__ . '/includes/db_connect.php'` (and make sure the path is actually correct relative to `api/` — see next point).
- **Inconsistent include paths.** `api/create-booking.php` requires `__DIR__ . '/includes/db_connect.php'`, i.e. `api/includes/db_connect.php` — but `db_connect.php` actually lives in the top-level `includes/` directory, not inside `api/`.
- **Possible function redeclaration.** `includes/functions.php` and `includes/security.php` both define `generate_csrf_token()`, `verify_csrf_token()`, `sanitize_input()`, and `json_response()` without `function_exists()` guards in `security.php`. Loading both in the same request (as `includes/bootstrap.php` would, if `security.php` were added to it) raises a fatal "cannot redeclare function" error.
- **Side-effecting include.** `includes/webhook_validator.php` calls `handle_webhook()` unconditionally at the bottom of the file, as soon as it is included — it is not safe to load it from `includes/bootstrap.php` on a normal page request, since it expects POST data and will short-circuit the response.
- **`admin/` is a work in progress.** Several files reference helpers that don't exist in this repository (`admin/bootstrap.php` in `export.php`, `admin/init.php` in `vouchers.php`/`settings.php`, `admin/admin_auth.php` in `logout.php`, `admin/db.php` in `dashboard.php`), and query a `bookings` table that isn't part of the schema in `database.sql` (which defines `reservations`). `admin/index.php` also implements its own, separate SQLite-based login, unrelated to the MySQL-based `includes/auth.php`. Treat `admin/` as a scaffold to finish, not a ready-to-deploy back office.
- **Undefined constant in `webhook_validator.php`.** `validate_webhook_signature($data, P24_CRC_KEY)` references `P24_CRC_KEY`, which is never defined anywhere (`includes/config.php` defines `P24_CRC` instead).
- **Inconsistent default database name.** Different files default `DB_NAME` to `vectron_sim`, `v`, or `booking_db`, while `database.sql` creates a database called `vectron`. Set `DB_NAME` explicitly in `.env` and don't rely on any in-code default.
- **Duplicate payment implementations.** The P24 flow exists in two parallel forms — a simple sandbox-redirect flow (`api/create-payment.php`) and a full Transaction Register API flow (`api/start-payment.php`) — using different environment variable prefixes (`P24_*` vs `PRZELEWY24_*`) and different signature algorithms (MD5 vs SHA-384). Pick one before going to production.
- **No dependency manifests were included in the original repository.** `composer.json` and `requirements.txt` in this repository were added to make `composer install` / `pip install -r requirements.txt` work for the dependencies the code actually imports (PHPMailer, Dompdf, Flask); verify version constraints against your target PHP/Python versions.
- **Missing asset.** `index.html` references `assets/image/logo.png`, which is not present in `assets/image/` (only a hero photo is included).

## Contributing

There is no `CONTRIBUTING.md` or coding-style configuration in this repository yet. Until one is added:

1. Fork the repository and create a feature branch (`git checkout -b feature/short-description`).
2. Keep changes focused — fix one inconsistency from [Known Limitations](#known-limitations) per pull request where possible, rather than large rewrites.
3. Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding style for PHP, and `declare(strict_types=1);` for new files, matching most existing files in `includes/`.
4. Add a short manual test (curl example or steps) in your PR description, since there is no automated test suite to rely on yet.
5. Open a pull request describing the change and, if relevant, which Known Limitation it addresses.

## Contact / Author

- Repository: <https://github.com/eryks23/restaurant>
- Author: GitHub [@eryks23](https://github.com/eryks23)

## License

This project is licensed under the MIT License — see [LICENSE](LICENSE) for the full text.
