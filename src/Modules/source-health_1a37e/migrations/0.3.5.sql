CREATE TABLE IF NOT EXISTS `source_health_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch` char(16) NOT NULL,
  `created_at` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `stream_id` int(11) NOT NULL,
  `action` varchar(10) NOT NULL,
  `source` text NOT NULL,
  `position` int(11) NOT NULL,
  `undone_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `batch` (`batch`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
