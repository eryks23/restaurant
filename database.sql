-- VECTRON UTK SIM - COMPLETE DATABASE SCHEMA + SAMPLE DATA (MySQL / MariaDB compatible)
-- Charset: utf8mb4
-- Usage:
-- 1) In phpMyAdmin import this file, or
-- 2) mysql -u user -p < vectron_schema_mysql.sql
-- NOTE: Designed for MySQL 5.7+ / MariaDB 10.2+. Some features (JSON, SIGNAL) vary by version.
-- If any 'DEFAULT (UUID())' expressions fail on older versions, triggers below set UUIDs automatically.

CREATE DATABASE IF NOT EXISTS `vectron` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `vectron`;

-- USERS (clients)
CREATE TABLE IF NOT EXISTS `users` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `first_name` VARCHAR(150) NOT NULL,
  `last_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50),
  `password_hash` VARCHAR(255),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `marketing_consent` TINYINT(1) DEFAULT 0,
  `gdpr_consent` TINYINT(1) DEFAULT 0,
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ADMINS
CREATE TABLE IF NOT EXISTS `admins` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255),
  `full_name` VARCHAR(255),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LOCATIONS
CREATE TABLE IF NOT EXISTS `locations` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `address` VARCHAR(512),
  `city` VARCHAR(150),
  `postal_code` VARCHAR(20),
  `google_iframe` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FILES (metadata)
CREATE TABLE IF NOT EXISTS `files` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `owner_user_id` CHAR(36),
  `type` VARCHAR(50) NOT NULL,
  `original_name` VARCHAR(255),
  `storage_path` VARCHAR(1024) NOT NULL,
  `mime_type` VARCHAR(100),
  `size_bytes` BIGINT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`type`),
  CONSTRAINT `fk_files_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SIMULATORS
CREATE TABLE IF NOT EXISTS `simulators` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(150) UNIQUE,
  `title` VARCHAR(255),
  `description` TEXT,
  `main_image_file_id` CHAR(36),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_sim_main_image` FOREIGN KEY (`main_image_file_id`) REFERENCES `files`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PRICES (cennik)
CREATE TABLE IF NOT EXISTS `prices` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `label` VARCHAR(100) NOT NULL,
  `minutes` INT NOT NULL,
  `price_gross` DECIMAL(10,2) NOT NULL,
  `promo_price_gross` DECIMAL(10,2) DEFAULT NULL,
  `active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- COUPONS / VOUCHERS
CREATE TABLE IF NOT EXISTS `coupons` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `code` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT,
  `discount_percent` INT DEFAULT NULL,
  `discount_amount` DECIMAL(10,2) DEFAULT NULL,
  `valid_from` DATE DEFAULT NULL,
  `valid_to` DATE DEFAULT NULL,
  `single_use` TINYINT(1) DEFAULT 0,
  `redeemed_count` INT DEFAULT 0,
  `max_redemptions` INT DEFAULT 1,
  `active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CALENDAR SLOTS
CREATE TABLE IF NOT EXISTS `calendar_slots` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `location_id` INT,
  `start_ts` DATETIME NOT NULL,
  `end_ts` DATETIME NOT NULL,
  `capacity` INT DEFAULT 1,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`start_ts`),
  INDEX `idx_loc_start` (`location_id`,`start_ts`),
  CONSTRAINT `fk_slot_location` FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RESERVATIONS
CREATE TABLE IF NOT EXISTS `reservations` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `user_id` CHAR(36),
  `slot_id` CHAR(36),
  `price_id` INT,
  `coupon_id` CHAR(36),
  `participants_count` INT DEFAULT 1,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `total_amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(8) DEFAULT 'PLN',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `code` VARCHAR(100) UNIQUE,
  `notes` TEXT,
  `gdpr_consent` TINYINT(1) DEFAULT 0,
  INDEX `idx_res_status` (`status`),
  INDEX `idx_res_user` (`user_id`),
  INDEX `idx_res_slot` (`slot_id`),
  CONSTRAINT `fk_res_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_res_slot` FOREIGN KEY (`slot_id`) REFERENCES `calendar_slots`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_res_price` FOREIGN KEY (`price_id`) REFERENCES `prices`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_res_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENTS
CREATE TABLE IF NOT EXISTS `payments` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `reservation_id` CHAR(36),
  `provider` VARCHAR(100) NOT NULL,
  `provider_payment_id` VARCHAR(255),
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(8) DEFAULT 'PLN',
  `status` VARCHAR(30) NOT NULL DEFAULT 'initiated',
  `raw_payload` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_pay_res` (`reservation_id`),
  INDEX `idx_pay_status` (`status`),
  CONSTRAINT `fk_pay_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- REVIEWS
CREATE TABLE IF NOT EXISTS `reviews` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `reservation_id` CHAR(36),
  `user_id` CHAR(36),
  `rating` TINYINT,
  `comment` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_review_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_review_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CMS PAGES
CREATE TABLE IF NOT EXISTS `cms_pages` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `slug` VARCHAR(200) NOT NULL UNIQUE,
  `title` VARCHAR(255),
  `body` LONGTEXT,
  `last_edited_by` CHAR(36),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_cms_admin` FOREIGN KEY (`last_edited_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUDIT LOGS
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `entity` VARCHAR(100),
  `entity_id` VARCHAR(255),
  `action` VARCHAR(100),
  `performed_by` VARCHAR(255),
  `details` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TRIGGERS: set UUID on INSERT if not provided
DELIMITER $$
CREATE TRIGGER trg_users_uuid_before_insert
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
  IF NEW.id IS NULL OR NEW.id = '' THEN
    SET NEW.id = UUID();
  END IF;
END$$
CREATE TRIGGER trg_admins_uuid_before_insert
BEFORE INSERT ON admins
FOR EACH ROW
BEGIN
  IF NEW.id IS NULL OR NEW.id = '' THEN
    SET NEW.id = UUID();
  END IF;
END$$
CREATE TRIGGER trg_files_uuid_before_insert
BEFORE INSERT ON files
FOR EACH ROW
BEGIN
  IF NEW.id IS NULL OR NEW.id = '' THEN
    SET NEW.id = UUID();
  END IF;
END$$
CREATE TRIGGER trg_coupons_uuid_before_insert
BEFORE INSERT ON coupons
FOR EACH ROW
BEGIN
  IF NEW.id IS NULL OR NEW.id = '' THEN
    SET NEW.id = UUID();
  END IF;
END$$
CREATE TRIGGER trg_calendar_uuid_before_insert
BEFORE INSERT ON calendar_slots
FOR EACH ROW
BEGIN
  IF NEW.id IS NULL OR NEW.id = '' THEN
    SET NEW.id = UUID();
  END IF;
END$$
CREATE TRIGGER trg_reservations_uuid_before_insert
BEFORE INSERT ON reservations
FOR EACH ROW
BEGIN
  IF NEW.id IS NULL OR NEW.id = '' THEN
    SET NEW.id = UUID();
  END IF;
END$$
CREATE TRIGGER trg_payments_uuid_before_insert
BEFORE INSERT ON payments
FOR EACH ROW
BEGIN
  IF NEW.id IS NULL OR NEW.id = '' THEN
    SET NEW.id = UUID();
  END IF;
END$$
CREATE TRIGGER trg_reviews_uuid_before_insert
BEFORE INSERT ON reviews
FOR EACH ROW
BEGIN
  IF NEW.id IS NULL OR NEW.id = '' THEN
    SET NEW.id = UUID();
  END IF;
END$$
CREATE TRIGGER trg_cms_uuid_before_insert
BEFORE INSERT ON cms_pages
FOR EACH ROW
BEGIN
  IF NEW.id IS NULL OR NEW.id = '' THEN
    SET NEW.id = UUID();
  END IF;
END$$
DELIMITER ;

-- TRIGGER: increment coupon redeemed_count AFTER INSERT reservation
DELIMITER $$
CREATE TRIGGER trg_reservation_coupon_after_insert
AFTER INSERT ON reservations
FOR EACH ROW
BEGIN
  IF NEW.coupon_id IS NOT NULL THEN
    UPDATE coupons SET redeemed_count = redeemed_count + 1 WHERE id = NEW.coupon_id;
  END IF;
END$$
DELIMITER ;

-- STORED PROCEDURE: book_slot
-- Returns reservation id through OUT parameter p_reservation_id
DELIMITER $$
CREATE PROCEDURE book_slot(
  IN p_user_id CHAR(36),
  IN p_slot_id CHAR(36),
  IN p_price_id INT,
  IN p_participants INT,
  IN p_gdpr TINYINT,
  IN p_coupon_id CHAR(36),
  OUT p_reservation_id CHAR(36)
)
BEGIN
  DECLARE v_count INT DEFAULT 0;
  DECLARE v_capacity INT DEFAULT 0;
  DECLARE v_amount DECIMAL(10,2) DEFAULT 0.00;
  DECLARE v_disc_percent INT DEFAULT NULL;
  DECLARE v_disc_amount DECIMAL(10,2) DEFAULT NULL;
  DECLARE v_code VARCHAR(120);

  -- lock the slot row
  SELECT capacity INTO v_capacity FROM calendar_slots WHERE id = p_slot_id FOR UPDATE;

  SELECT COUNT(*) INTO v_count FROM reservations WHERE slot_id = p_slot_id AND status IN ('pending','paid');

  IF v_capacity IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Slot not found';
  END IF;

  IF v_count >= v_capacity THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Slot is full';
  END IF;

  SELECT IFNULL(promo_price_gross, price_gross) INTO v_amount FROM prices WHERE id = p_price_id LIMIT 1;

  IF p_coupon_id IS NOT NULL THEN
    SELECT discount_percent, discount_amount INTO v_disc_percent, v_disc_amount
    FROM coupons
    WHERE id = p_coupon_id AND active = 1 AND (valid_from IS NULL OR valid_from <= CURDATE()) AND (valid_to IS NULL OR valid_to >= CURDATE())
    LIMIT 1;

    IF v_disc_amount IS NOT NULL THEN
      SET v_amount = v_amount - v_disc_amount;
    ELSEIF v_disc_percent IS NOT NULL THEN
      SET v_amount = v_amount * (1 - (v_disc_percent / 100));
    END IF;
  END IF;

  IF v_amount < 0 THEN SET v_amount = 0; END IF;

  SET v_code = CONCAT('RERV-', DATE_FORMAT(NOW(), '%Y%m%d-%H%i'), '-', LEFT(UUID(),8));

  INSERT INTO reservations (id, user_id, slot_id, price_id, coupon_id, participants_count, status, total_amount, currency, code, gdpr_consent)
  VALUES (UUID(), p_user_id, p_slot_id, p_price_id, p_coupon_id, p_participants, 'pending', v_amount, 'PLN', v_code, p_gdpr);

  SET p_reservation_id = (SELECT id FROM reservations WHERE code = v_code LIMIT 1);
END$$
DELIMITER ;

-- SAMPLE DATA
-- Location
INSERT INTO locations (name, address, city, postal_code, google_iframe) VALUES
('KG Rail - Katowice', 'ul. Słoneczna 4/B/4', 'Katowice', '40-135', '<iframe src=\"https://maps.google.com/...\" />');

-- Prices
INSERT INTO prices (label, minutes, price_gross, promo_price_gross) VALUES
('30 minut', 30, 299.00, 199.00),
('60 minut', 60, 399.00, 249.00),
('90 minut', 90, 499.00, 299.00);

-- Admin
INSERT INTO admins (id, username, password_hash, email, full_name) VALUES
(UUID(), 'admin', 'pbkdf2$examplehash', 'admin@kgrail.pl', 'Administrator KG Rail');

-- Sample user
INSERT INTO users (id, first_name, last_name, email, phone, gdpr_consent) VALUES
(UUID(), 'Michał', 'Dworak', 'michal@example.com', '+48606175220', 1);

-- Sample file
INSERT INTO files (id, owner_user_id, type, original_name, storage_path, mime_type, size_bytes) VALUES
(UUID(), (SELECT id FROM users WHERE email = 'michal@example.com' LIMIT 1), 'image', 'kabina.jpg', 's3://vectron-bucket/images/2025-11/kabina.jpg', 'image/jpeg', 524000);

-- Simulator record
INSERT INTO simulators (slug, title, description, main_image_file_id) VALUES
('vectron-utk-sim', 'Vectron UTK SIM', 'Profesjonalny symulator lokomotywy Vectron UTK SIM. Realistyczne trasy i instruktorzy.', (SELECT id FROM files LIMIT 1));

-- Create a few calendar slots (example dates)
INSERT INTO calendar_slots (id, location_id, start_ts, end_ts, capacity) VALUES
(UUID(), (SELECT id FROM locations LIMIT 1), '2025-11-10 10:00:00', '2025-11-10 10:30:00', 1),
(UUID(), (SELECT id FROM locations LIMIT 1), '2025-11-10 10:30:00', '2025-11-10 11:00:00', 1),
(UUID(), (SELECT id FROM locations LIMIT 1), '2025-11-10 11:00:00', '2025-11-10 11:30:00', 1),
(UUID(), (SELECT id FROM locations LIMIT 1), '2025-11-11 14:00:00', '2025-11-11 14:30:00', 1);

-- Sample reservation (pending)
INSERT INTO reservations (id, user_id, slot_id, price_id, participants_count, total_amount, code, gdpr_consent) VALUES
(UUID(), (SELECT id FROM users WHERE email='michal@example.com' LIMIT 1), (SELECT id FROM calendar_slots ORDER BY start_ts LIMIT 1), (SELECT id FROM prices WHERE minutes=30 LIMIT 1), 1, 199.00, 'RERV-20251110-0001', 1);

-- Sample payment (initiated)
INSERT INTO payments (id, reservation_id, provider, provider_payment_id, amount, currency, status) VALUES
(UUID(), (SELECT id FROM reservations LIMIT 1), 'przelewy24', 'P24-EXAMPLE-0001', 199.00, 'PLN', 'initiated');

-- Sample review
INSERT INTO reviews (id, reservation_id, user_id, rating, comment) VALUES
(UUID(), (SELECT id FROM reservations LIMIT 1), (SELECT id FROM users WHERE email='michal@example.com' LIMIT 1), 5, 'Niesamowite przeżycie! Realizm jak w prawdziwej kabinie!');

-- CMS pages
INSERT INTO cms_pages (id, slug, title, body) VALUES
(UUID(), 'regulamin', 'Regulamin jazd na symulatorze', 'Tu wpisz pełny tekst regulaminu...'),
(UUID(), 'polityka-prywatnosci', 'Polityka prywatności', 'Tu wpisz politykę prywatności i informacje RODO...');

-- Audit log sample
INSERT INTO audit_logs (entity, entity_id, action, performed_by, details) VALUES
('reservation', 'manual-sample', 'seed-data', 'system', JSON_OBJECT('note','sample seed data inserted'));

-- End of file
