-- M/2026/05/11/39 — Table app_settings globale (priceVariant + taux EUR/MAD + futurs settings)
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
  ('price_display_variant', 'A'),
  ('exchange_rate_eur_mad', '10.84');
