-- 0.4.0: the ISPs each line has been used from.
CREATE TABLE IF NOT EXISTS `line_audit_isps` (
  `user_id` int(11) NOT NULL,
  `isp_hash` char(32) NOT NULL,
  `isp` varchar(255) NOT NULL,
  `first_seen` int(11) NOT NULL,
  `last_seen` int(11) NOT NULL,
  PRIMARY KEY (`user_id`, `isp_hash`),
  KEY `first_seen` (`first_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
