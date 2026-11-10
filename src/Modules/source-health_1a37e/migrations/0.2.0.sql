CREATE TABLE IF NOT EXISTS `source_health_hosts` (
  `stream_id` int(11) NOT NULL,
  `source_hash` char(32) NOT NULL,
  `host` varchar(190) NOT NULL,
  `last_seen` int(11) NOT NULL,
  PRIMARY KEY (`stream_id`, `source_hash`, `host`),
  KEY `last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
