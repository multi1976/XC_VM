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

-- Auto-rank, shadow mode: where each stream's evaluation stands. A proposal
-- (pending_hash) must hold over AutoRank::CONFIRM evaluations in a row before
-- it is recorded, at most once per AutoRank::MIN_INTERVAL_SEC per stream.
CREATE TABLE IF NOT EXISTS `source_health_rank_state` (
  `stream_id` int(11) NOT NULL,
  `last_eval` int(11) NOT NULL DEFAULT 0,
  `pending_hash` char(32) NOT NULL DEFAULT '',
  `pending_count` int(11) NOT NULL DEFAULT 0,
  `pending_since` int(11) NOT NULL DEFAULT 0,
  `last_decision_at` int(11) NOT NULL DEFAULT 0,
  `last_top_hash` varchar(65) NOT NULL DEFAULT '',
  PRIMARY KEY (`stream_id`),
  KEY `last_eval` (`last_eval`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What auto-rank would have changed, and, a day later, how the two sources
-- did since. Sources by md5 only: no URL, no account, in this table.
CREATE TABLE IF NOT EXISTS `source_health_rank_decisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_id` int(11) NOT NULL,
  `decided_at` int(11) NOT NULL,
  `old_hash` char(32) NOT NULL,
  `new_hash` char(32) NOT NULL,
  `old_index` int(11) NOT NULL DEFAULT 0,
  `new_index` int(11) NOT NULL DEFAULT 0,
  `old_score` int(11) DEFAULT NULL,
  `new_score` int(11) DEFAULT NULL,
  `old_value` float NOT NULL DEFAULT 0,
  `new_value` float NOT NULL DEFAULT 0,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `on_air` tinyint(1) NOT NULL DEFAULT 0,
  `outcome_at` int(11) DEFAULT NULL,
  `old_incidents` int(11) DEFAULT NULL,
  `old_uptime` int(11) DEFAULT NULL,
  `old_start_fails` int(11) DEFAULT NULL,
  `new_incidents` int(11) DEFAULT NULL,
  `new_uptime` int(11) DEFAULT NULL,
  `new_probe_ok` int(11) DEFAULT NULL,
  `new_probe_total` int(11) DEFAULT NULL,
  `verdict` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stream_id` (`stream_id`),
  KEY `decided_at` (`decided_at`),
  KEY `outcome_at` (`outcome_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
