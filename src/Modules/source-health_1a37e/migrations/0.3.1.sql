CREATE TABLE IF NOT EXISTS `source_health_accounts` (
  `account_hash` char(32) NOT NULL,
  `host` varchar(190) NOT NULL DEFAULT '',
  `checked_at` int(11) NOT NULL,
  `api` tinyint(1) NOT NULL DEFAULT 0,
  `auth` tinyint(1) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT '',
  `max_conn` int(11) DEFAULT NULL,
  `active_cons` int(11) DEFAULT NULL,
  `exp_date` int(11) DEFAULT NULL,
  PRIMARY KEY (`account_hash`),
  KEY `host` (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
