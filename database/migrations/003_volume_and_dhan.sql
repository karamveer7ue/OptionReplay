ALTER TABLE candles_1m MODIFY volume BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE candles_1m MODIFY oi BIGINT UNSIGNED NULL;

-- Dhan backfill mapping and expiry rules per underlying
ALTER TABLE underlyings
  ADD COLUMN dhan_segment VARCHAR(16) NULL AFTER spot_neosymbol,
  ADD COLUMN dhan_security_id INT UNSIGNED NULL AFTER dhan_segment,
  ADD COLUMN weekly_expiry_dow TINYINT UNSIGNED NULL AFTER dhan_security_id,   -- 1=Mon .. 7=Sun, NULL = no weeklies
  ADD COLUMN monthly_expiry_dow TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER weekly_expiry_dow;

UPDATE underlyings SET dhan_segment='NSE_FNO', dhan_security_id=13, weekly_expiry_dow=2, monthly_expiry_dow=2 WHERE symbol='NIFTY';
UPDATE underlyings SET dhan_segment='NSE_FNO', dhan_security_id=25, weekly_expiry_dow=NULL, monthly_expiry_dow=2 WHERE symbol='BANKNIFTY';
UPDATE underlyings SET dhan_segment='BSE_FNO', dhan_security_id=51, weekly_expiry_dow=4, monthly_expiry_dow=4 WHERE symbol='SENSEX';

-- Known expiry dates (filled from Kotak discovery going forward, and by rule for the past).
CREATE TABLE IF NOT EXISTS expiry_calendar (
  underlying_id INT UNSIGNED NOT NULL,
  expiry        DATE NOT NULL,
  kind          ENUM('weekly','monthly') NOT NULL,
  source        VARCHAR(16) NOT NULL DEFAULT 'rule',
  PRIMARY KEY (underlying_id, expiry),
  CONSTRAINT fk_expcal_underlying FOREIGN KEY (underlying_id) REFERENCES underlyings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE fetch_jobs MODIFY kind ENUM('discover','spot_1m','spot_1d','inst_1m','inst_1d','dhan_backfill') NOT NULL;
ALTER TABLE fetch_jobs ADD COLUMN params VARCHAR(255) NULL AFTER to_date;
