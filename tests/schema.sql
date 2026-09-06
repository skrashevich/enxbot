CREATE TABLE IF NOT EXISTS admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_username TEXT
);

CREATE TABLE IF NOT EXISTS games (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id INTEGER,
  game_id INTEGER,
  game_domain TEXT,
  game_login TEXT,
  game_pass TEXT,
  cookies TEXT,
  last_level_id INTEGER NOT NULL DEFAULT 0,
  status INTEGER,
  infochannel TEXT,
  shtab_id TEXT
);

CREATE TABLE IF NOT EXISTS log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  time INTEGER,
  message_id INTEGER,
  chat_id INTEGER,
  chat_title TEXT,
  text TEXT,
  type INTEGER,
  sender_id INTEGER,
  sender_username TEXT
);
CREATE INDEX IF NOT EXISTS log_chatid ON log (chat_id);

CREATE TABLE IF NOT EXISTS queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  send_at INTEGER,
  added INTEGER,
  method TEXT,
  parameters TEXT,
  parsed INTEGER
);
CREATE INDEX IF NOT EXISTS queue_send_at ON queue (send_at);
CREATE INDEX IF NOT EXISTS queue_parsed ON queue (parsed);

CREATE TABLE IF NOT EXISTS timers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id INTEGER,
  game_id INTEGER,
  level_id INTEGER,
  hint INTEGER,
  time INTEGER,
  type INTEGER
);

CREATE TABLE IF NOT EXISTS settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id INTEGER NOT NULL DEFAULT 0,
  name TEXT,
  value TEXT
);
CREATE INDEX IF NOT EXISTS settings_chat_name ON settings (chat_id, name);

CREATE TABLE IF NOT EXISTS codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id INTEGER,
  level INTEGER,
  time INTEGER,
  code_number INTEGER,
  code_status INTEGER NOT NULL DEFAULT 0,
  code TEXT,
  code_type INTEGER NOT NULL DEFAULT 1,
  find INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS codes_chat_level ON codes (chat_id, level);

CREATE TABLE IF NOT EXISTS codeslog (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id INTEGER,
  level INTEGER,
  code TEXT,
  comment TEXT,
  time INTEGER,
  sender TEXT,
  return TEXT
);
CREATE INDEX IF NOT EXISTS codeslog_chat_level ON codeslog (chat_id, level);

CREATE TABLE IF NOT EXISTS locations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id INTEGER,
  time INTEGER,
  sender_username TEXT,
  sender_name TEXT,
  lat REAL,
  lon REAL,
  title TEXT,
  level INTEGER,
  type INTEGER
);

CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id INTEGER,
  time INTEGER,
  whom TEXT,
  message TEXT
);
CREATE INDEX IF NOT EXISTS messages_chat ON messages (chat_id);

CREATE TABLE IF NOT EXISTS coords (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id INTEGER,
  level INTEGER,
  lat REAL,
  lon REAL,
  time INTEGER
);
CREATE INDEX IF NOT EXISTS coords_chat_level ON coords (chat_id, level);

CREATE TABLE IF NOT EXISTS geocache (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lat REAL,
  lon REAL,
  added INTEGER,
  address TEXT
);
CREATE INDEX IF NOT EXISTS geocache_latlon ON geocache (lat, lon);

CREATE TABLE IF NOT EXISTS directionscache (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lat1 REAL,
  lon1 REAL,
  lat2 REAL,
  lon2 REAL,
  added INTEGER,
  length REAL,
  time REAL
);
