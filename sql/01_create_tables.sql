-- Schema for avito-autoload-reports
-- Run in a MySQL database configured in DB_NAME.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS avito_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(255) NULL,
  client_id VARCHAR(255) NOT NULL,
  client_secret VARCHAR(255) NOT NULL,
  access_token TEXT NULL,
  token_expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_client_id (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS avito_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  report_external_id VARCHAR(255) NOT NULL,
  fetched_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_account_report (account_id, report_external_id),
  KEY idx_account_id (account_id),
  CONSTRAINT fk_reports_account
    FOREIGN KEY (account_id) REFERENCES avito_accounts(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS avito_error_ads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id BIGINT UNSIGNED NOT NULL,
  ad_external_id VARCHAR(255) NOT NULL,
  error_type VARCHAR(255) NULL,
  fetched_at DATETIME NOT NULL,
  status ENUM('NEW', 'COMPLETED') NOT NULL DEFAULT 'NEW',
  rx_good_items INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ad_external_id (ad_external_id),
  KEY idx_report_id (report_id),
  CONSTRAINT fk_error_ads_report
    FOREIGN KEY (report_id) REFERENCES avito_reports(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
