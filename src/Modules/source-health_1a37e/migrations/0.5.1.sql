-- 0.5.1: failed starts of the proposed source in the day after an auto-rank record.
ALTER TABLE `source_health_rank_decisions` ADD COLUMN IF NOT EXISTS `new_start_fails` int(11) DEFAULT NULL AFTER `new_uptime`;
