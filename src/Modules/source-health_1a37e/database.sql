CREATE TABLE IF NOT EXISTS `source_health_profiles` (
  `stream_id` int(11) NOT NULL,
  `source_hash` char(32) NOT NULL,
  `server_id` int(11) NOT NULL,
  `fingerprint` varchar(190) NOT NULL DEFAULT '',
  `label` varchar(190) NOT NULL DEFAULT '',
  `video_codec` varchar(32) NOT NULL DEFAULT '',
  `video_profile` varchar(64) NOT NULL DEFAULT '',
  `width` int(11) NOT NULL DEFAULT 0,
  `height` int(11) NOT NULL DEFAULT 0,
  `fps` decimal(7,3) NOT NULL DEFAULT 0.000,
  `scan` char(1) NOT NULL DEFAULT '?',
  `audio_codec` varchar(32) NOT NULL DEFAULT '',
  `audio_rate` int(11) NOT NULL DEFAULT 0,
  `audio_channels` tinyint(4) NOT NULL DEFAULT 0,
  `bitrate` int(11) DEFAULT NULL,
  `first_seen` int(11) NOT NULL,
  `last_seen` int(11) NOT NULL,
  PRIMARY KEY (`stream_id`, `source_hash`, `server_id`),
  KEY `last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `source_health_probe_streams` (
  `stream_id` int(11) NOT NULL,
  `enabled_at` int(11) NOT NULL,
  `enabled_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`stream_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `source_health_probes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_id` int(11) NOT NULL,
  `source_hash` char(32) NOT NULL,
  `checked_at` int(11) NOT NULL,
  `kind` varchar(8) NOT NULL DEFAULT 'probe',
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `open_ms` int(11) DEFAULT NULL,
  `speed` decimal(6,2) DEFAULT NULL,
  `label` varchar(190) NOT NULL DEFAULT '',
  `error` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `stream_source` (`stream_id`, `source_hash`, `checked_at`),
  KEY `checked_at` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `source_health_hosts` (
  `stream_id` int(11) NOT NULL,
  `source_hash` char(32) NOT NULL,
  `host` varchar(190) NOT NULL,
  `last_seen` int(11) NOT NULL,
  PRIMARY KEY (`stream_id`, `source_hash`, `host`),
  KEY `last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
