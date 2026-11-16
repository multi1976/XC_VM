-- 0.5.0: auto-rank in shadow mode.
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
