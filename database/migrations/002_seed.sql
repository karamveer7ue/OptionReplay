INSERT IGNORE INTO underlyings (symbol, name, kind, fo_exchange, spot_neosymbol, lot_size, strike_step, sort_order) VALUES
  ('NIFTY',     'Nifty 50',   'index', 'nse_fo', 'nse_cm|Nifty 50',   65, 50,  1),
  ('BANKNIFTY', 'Nifty Bank', 'index', 'nse_fo', 'nse_cm|Nifty Bank', 30, 100, 2),
  ('SENSEX',    'Sensex',     'index', 'bse_fo', 'bse_cm|SENSEX',     20, 100, 3);

-- NSE trading holidays 2025 and 2026 (equity / F&O segment)
INSERT IGNORE INTO holidays (day, description) VALUES
  ('2025-02-26','Mahashivratri'),('2025-03-14','Holi'),('2025-03-31','Id-Ul-Fitr'),('2025-04-10','Shri Mahavir Jayanti'),
  ('2025-04-14','Dr. Ambedkar Jayanti'),('2025-04-18','Good Friday'),('2025-05-01','Maharashtra Day'),('2025-08-15','Independence Day'),
  ('2025-08-27','Ganesh Chaturthi'),('2025-10-02','Mahatma Gandhi Jayanti / Dussehra'),('2025-10-21','Diwali Laxmi Pujan'),
  ('2025-10-22','Diwali Balipratipada'),('2025-11-05','Prakash Gurpurb Sri Guru Nanak Dev'),('2025-12-25','Christmas'),
  ('2026-01-26','Republic Day'),('2026-02-15','Mahashivratri'),('2026-03-03','Holi'),('2026-03-20','Id-Ul-Fitr'),
  ('2026-03-26','Shri Ram Navami'),('2026-03-31','Shri Mahavir Jayanti'),('2026-04-03','Good Friday'),('2026-04-14','Dr. Ambedkar Jayanti'),
  ('2026-05-01','Maharashtra Day'),('2026-05-28','Bakri Id'),('2026-06-26','Muharram'),('2026-09-14','Ganesh Chaturthi'),
  ('2026-10-02','Mahatma Gandhi Jayanti'),('2026-10-20','Dussehra'),('2026-11-08','Diwali Laxmi Pujan'),('2026-11-10','Diwali Balipratipada'),
  ('2026-11-24','Prakash Gurpurb Sri Guru Nanak Dev'),('2026-12-25','Christmas');
