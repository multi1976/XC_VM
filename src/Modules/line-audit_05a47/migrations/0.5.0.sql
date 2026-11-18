-- 0.5.0: the user agents of restreamer lines.
CREATE TABLE IF NOT EXISTS `line_audit_uas` (
  `user_id` int(11) NOT NULL,
  `ua_hash` char(32) NOT NULL,
  `ua` varchar(255) NOT NULL,
  `first_seen` int(11) NOT NULL,
  `last_seen` int(11) NOT NULL,
  PRIMARY KEY (`user_id`, `ua_hash`),
  KEY `last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
