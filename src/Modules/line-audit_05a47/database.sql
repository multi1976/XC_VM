-- line-audit master schema: the full current state, safe to re-run.

-- What the live connections of a line looked like, every five minutes, for the
-- lines worth a look: two connections or more, or at their limit. IP addresses
-- are not kept: only how many distinct ones, the countries and ISPs, and the
-- player families (uas counts families, not raw user agents).
CREATE TABLE IF NOT EXISTS `line_audit_samples` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sampled_at` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `conns` smallint(5) unsigned NOT NULL,
  `max_conn` smallint(5) unsigned NOT NULL DEFAULT 0,
  `ips` smallint(5) unsigned NOT NULL,
  `countries` smallint(5) unsigned NOT NULL,
  `isps` smallint(5) unsigned NOT NULL,
  `uas` smallint(5) unsigned NOT NULL,
  `country_list` varchar(64) NOT NULL DEFAULT '',
  `isp_list` varchar(255) NOT NULL DEFAULT '',
  `ua_list` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `sampled_at` (`sampled_at`),
  KEY `user_sampled` (`user_id`, `sampled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
