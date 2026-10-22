CREATE TABLE IF NOT EXISTS settings (
  `key`   VARCHAR(64)  NOT NULL PRIMARY KEY,
  `value` TEXT         NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS underlyings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  symbol         VARCHAR(32)  NOT NULL UNIQUE,        -- NIFTY, BANKNIFTY, SENSEX, RELIANCE
  name           VARCHAR(64)  NOT NULL,
  kind           ENUM('index','stock') NOT NULL DEFAULT 'index',
  fo_exchange    VARCHAR(16)  NOT NULL,               -- nse_fo / bse_fo
  spot_neosymbol VARCHAR(64)  NOT NULL,               -- nse_cm|Nifty 50
  lot_size       INT UNSIGNED NOT NULL DEFAULT 0,     -- refreshed from option chain
  strike_step    DECIMAL(10,2) NOT NULL DEFAULT 50,   -- refreshed from option chain
  enabled        TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order     INT          NOT NULL DEFAULT 0,
  lookback_days  INT UNSIGNED NULL,                   -- NULL = use global setting
  strike_band    INT UNSIGNED NULL,
  expiry_window_days INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS instruments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  underlying_id  INT UNSIGNED NOT NULL,
  kind           ENUM('CE','PE','FUT') NOT NULL,
  strike         DECIMAL(12,2) NOT NULL DEFAULT 0,    -- 0 for futures
  expiry         DATE NOT NULL,
  exchange_seg   VARCHAR(16) NOT NULL,
  token          VARCHAR(32) NOT NULL,                -- broker token at time of discovery
  trading_symbol VARCHAR(64) NOT NULL,
  lot_size       INT UNSIGNED NOT NULL DEFAULT 0,
  source         VARCHAR(16) NOT NULL DEFAULT 'kotak',
  first_seen_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contract (underlying_id, kind, strike, expiry),
  KEY idx_expiry (underlying_id, expiry),
  CONSTRAINT fk_inst_underlying FOREIGN KEY (underlying_id) REFERENCES underlyings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prices stored in paise (INT) to keep the big tables compact.
CREATE TABLE IF NOT EXISTS candles_1m (
  instrument_id INT UNSIGNED NOT NULL,
  ts            DATETIME NOT NULL,
  o INT UNSIGNED NOT NULL, h INT UNSIGNED NOT NULL, l INT UNSIGNED NOT NULL, c INT UNSIGNED NOT NULL,
  volume INT UNSIGNED NOT NULL DEFAULT 0,
  oi     INT UNSIGNED NULL,
  PRIMARY KEY (instrument_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPRESSED;

CREATE TABLE IF NOT EXISTS candles_1d (
  instrument_id INT UNSIGNED NOT NULL,
  day           DATE NOT NULL,
  o INT UNSIGNED NOT NULL, h INT UNSIGNED NOT NULL, l INT UNSIGNED NOT NULL, c INT UNSIGNED NOT NULL,
  volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
  oi     BIGINT UNSIGNED NULL,
  PRIMARY KEY (instrument_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS spot_candles_1m (
  underlying_id INT UNSIGNED NOT NULL,
  ts            DATETIME NOT NULL,
  o INT UNSIGNED NOT NULL, h INT UNSIGNED NOT NULL, l INT UNSIGNED NOT NULL, c INT UNSIGNED NOT NULL,
  PRIMARY KEY (underlying_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPRESSED;

CREATE TABLE IF NOT EXISTS spot_candles_1d (
  underlying_id INT UNSIGNED NOT NULL,
  day           DATE NOT NULL,
  o INT UNSIGNED NOT NULL, h INT UNSIGNED NOT NULL, l INT UNSIGNED NOT NULL, c INT UNSIGNED NOT NULL,
  volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (underlying_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trading calendar: every weekday is assumed trading unless listed here.
CREATE TABLE IF NOT EXISTS holidays (
  day         DATE NOT NULL PRIMARY KEY,
  description VARCHAR(120) NOT NULL DEFAULT '',
  source      VARCHAR(16) NOT NULL DEFAULT 'manual'   -- manual / inferred
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fetch_jobs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_key       CHAR(40) NOT NULL UNIQUE,              -- sha1 of kind+ids+range, prevents duplicates
  kind          ENUM('discover','spot_1m','spot_1d','inst_1m','inst_1d') NOT NULL,
  source        VARCHAR(16) NOT NULL DEFAULT 'kotak',
  underlying_id INT UNSIGNED NOT NULL,
  instrument_id INT UNSIGNED NULL,
  from_date     DATE NULL,
  to_date       DATE NULL,
  priority      INT NOT NULL DEFAULT 100,               -- lower runs first
  status        ENUM('pending','running','done','empty','failed') NOT NULL DEFAULT 'pending',
  attempts      INT UNSIGNED NOT NULL DEFAULT 0,
  rows_written  INT UNSIGNED NOT NULL DEFAULT 0,
  last_error    TEXT NULL,
  not_before    DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  finished_at   TIMESTAMP NULL,
  KEY idx_queue (status, not_before, priority, id),
  KEY idx_underlying (underlying_id, kind, status),
  KEY idx_instrument (instrument_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fetch_runs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  started_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at  TIMESTAMP NULL,
  trigger_type VARCHAR(16) NOT NULL DEFAULT 'cron',      -- cron / manual / cli
  jobs_done    INT UNSIGNED NOT NULL DEFAULT 0,
  jobs_failed  INT UNSIGNED NOT NULL DEFAULT 0,
  requests     INT UNSIGNED NOT NULL DEFAULT 0,
  rows_written INT UNSIGNED NOT NULL DEFAULT 0,
  note         VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
