-- Move option candles from 1-minute to 5-minute storage. Existing 1-minute rows are aggregated in place
-- (first open, max high, min low, last close, summed volume, last OI); buckets align to 09:15 IST.
CREATE TABLE IF NOT EXISTS candles_5m (
  instrument_id INT UNSIGNED NOT NULL,
  ts            DATETIME NOT NULL,
  o INT UNSIGNED NOT NULL, h INT UNSIGNED NOT NULL, l INT UNSIGNED NOT NULL, c INT UNSIGNED NOT NULL,
  volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
  oi     BIGINT UNSIGNED NULL,
  PRIMARY KEY (instrument_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPRESSED;

INSERT IGNORE INTO candles_5m (instrument_id, ts, o, h, l, c, volume, oi)
SELECT b.instrument_id, b.bts,
       fo.o, b.h, b.l, lc.c, b.vol, lc.oi
FROM (
  SELECT instrument_id, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / 300) * 300) bts,
         MIN(ts) first_ts, MAX(ts) last_ts, MAX(h) h, MIN(l) l, SUM(volume) vol
  FROM candles_1m GROUP BY instrument_id, bts
) b
JOIN candles_1m fo ON fo.instrument_id = b.instrument_id AND fo.ts = b.first_ts
JOIN candles_1m lc ON lc.instrument_id = b.instrument_id AND lc.ts = b.last_ts;

DROP TABLE candles_1m;

-- Rename the job kind and recompute keys so already-finished windows are not fetched again.
ALTER TABLE fetch_jobs MODIFY kind ENUM('discover','spot_1m','spot_1d','inst_1m','inst_5m','inst_1d','dhan_backfill') NOT NULL;
UPDATE fetch_jobs SET kind = 'inst_5m' WHERE kind = 'inst_1m';
UPDATE fetch_jobs SET job_key = SHA1(CONCAT_WS('|', source, kind, underlying_id, COALESCE(instrument_id, 0), COALESCE(from_date, ''), COALESCE(to_date, ''), COALESCE(params, ''))) WHERE kind = 'inst_5m';
ALTER TABLE fetch_jobs MODIFY kind ENUM('discover','spot_1m','spot_1d','inst_5m','inst_1d','dhan_backfill') NOT NULL;
