CREATE TABLE IF NOT EXISTS `source_health_audit` (
  `source_hash` char(32) NOT NULL,
  `host` varchar(190) NOT NULL DEFAULT '',
  `checked_at` int(11) NOT NULL,
  `status` varchar(8) NOT NULL,
  `kind` varchar(10) DEFAULT NULL,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `fails` smallint(5) unsigned NOT NULL DEFAULT 0,
  `first_fail` int(11) NOT NULL DEFAULT 0,
  `last_ok` int(11) NOT NULL DEFAULT 0,
  `ms` int(11) DEFAULT NULL,
  PRIMARY KEY (`source_hash`),
  KEY `status` (`status`),
  KEY `host` (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
