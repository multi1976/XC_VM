-- What the hourly provider sync fetches for each Stream Provider: its live
-- channels, its VOD, its series. A panel that keeps its own VOD and series
-- only needs the live list. Existing providers keep fetching all three.
ALTER TABLE `providers`
      ADD COLUMN IF NOT EXISTS `sync_live` tinyint(1) NOT NULL DEFAULT '1' AFTER `hls`,
      ADD COLUMN IF NOT EXISTS `sync_vod` tinyint(1) NOT NULL DEFAULT '1' AFTER `sync_live`,
      ADD COLUMN IF NOT EXISTS `sync_series` tinyint(1) NOT NULL DEFAULT '1' AFTER `sync_vod`;
