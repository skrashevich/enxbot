# Дамп таблицы admins
# ------------------------------------------------------------

CREATE TABLE `admins` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `admin_username` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



# Дамп таблицы games
# ------------------------------------------------------------

CREATE TABLE `games` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` int(11) DEFAULT NULL,
  `game_id` int(5) unsigned DEFAULT NULL,
  `game_domain` varchar(255) DEFAULT NULL,
  `game_login` varchar(255) DEFAULT NULL,
  `game_pass` varchar(255) DEFAULT NULL,
  `cookies` text,
  `last_level_id` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) unsigned DEFAULT NULL,
  `payment` smallint(5) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



# Дамп таблицы log
# ------------------------------------------------------------

CREATE TABLE `log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `time` int(11) unsigned DEFAULT NULL,
  `message_id` int(11) DEFAULT NULL,
  `chat_id` int(11) DEFAULT NULL,
  `chat_title` varchar(255) DEFAULT NULL,
  `text` text,
  `type` tinyint(1) unsigned DEFAULT NULL COMMENT '1 - код, 2  - команда, 3 - текст',
  `sender_id` int(11) DEFAULT NULL,
  `sender_username` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chatid` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



# Дамп таблицы queue
# ------------------------------------------------------------

CREATE TABLE `queue` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `send_at` int(11) unsigned DEFAULT NULL,
  `added` int(11) unsigned DEFAULT NULL,
  `method` varchar(11) DEFAULT NULL,
  `parameters` text,
  `parsed` tinyint(1) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `send_at` (`send_at`),
  KEY `parsed` (`parsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



# Дамп таблицы timers
# ------------------------------------------------------------

CREATE TABLE `timers` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` int(11) DEFAULT NULL,
  `game_id` int(5) unsigned DEFAULT NULL,
  `level_id` int(11) DEFAULT NULL,
  `hint` tinyint(1) unsigned DEFAULT NULL,
  `time` int(11) unsigned DEFAULT NULL,
  `type` tinyint(1) DEFAULT NULL COMMENT '1 - подскзка, 2 - АП',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

