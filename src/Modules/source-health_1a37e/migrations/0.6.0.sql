-- 0.6.0: auto-rank by profile group. A record is FIRST (another source first) or ORDER (the next in line),
-- with the profile letters of the two sources, the order before and proposed, and, a day later, how many
-- failovers the stream had and how many of them changed profile.
ALTER TABLE `source_health_rank_decisions`
  ADD COLUMN IF NOT EXISTS `kind` varchar(8) NOT NULL DEFAULT 'first' AFTER `decided_at`,
  ADD COLUMN IF NOT EXISTS `old_profile` varchar(2) NOT NULL DEFAULT '' AFTER `new_index`,
  ADD COLUMN IF NOT EXISTS `new_profile` varchar(2) NOT NULL DEFAULT '' AFTER `old_profile`,
  ADD COLUMN IF NOT EXISTS `order_before` varchar(190) NOT NULL DEFAULT '' AFTER `new_profile`,
  ADD COLUMN IF NOT EXISTS `order_after` varchar(190) NOT NULL DEFAULT '' AFTER `order_before`,
  ADD COLUMN IF NOT EXISTS `failovers` int(11) DEFAULT NULL AFTER `new_probe_total`,
  ADD COLUMN IF NOT EXISTS `profile_switches` int(11) DEFAULT NULL AFTER `failovers`;
