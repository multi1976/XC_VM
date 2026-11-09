CREATE TABLE IF NOT EXISTS `channel_audit_standards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `spec` text NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `channel_audit_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` varchar(16) NOT NULL,
  `server_id` int(11) NOT NULL DEFAULT 0,
  `target` varchar(1024) NOT NULL DEFAULT '',
  `stream_id` int(11) DEFAULT NULL,
  `label` varchar(255) NOT NULL DEFAULT '',
  `standard_name` varchar(100) NOT NULL DEFAULT '',
  `spec` text NOT NULL,
  `deep` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `total` int(11) NOT NULL DEFAULT 0,
  `done` int(11) NOT NULL DEFAULT 0,
  `passed` int(11) NOT NULL DEFAULT 0,
  `warned` int(11) NOT NULL DEFAULT 0,
  `failed` int(11) NOT NULL DEFAULT 0,
  `pid` int(11) DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `started_at` int(11) DEFAULT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `channel_audit_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  `position` int(11) NOT NULL,
  `source` varchar(2048) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(8) NOT NULL DEFAULT 'queued',
  `duration` decimal(12,3) DEFAULT NULL,
  `report` mediumtext DEFAULT NULL,
  `checked_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_position` (`job_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `channel_audit_standards` (`name`, `spec`, `created_at`, `updated_at`)
SELECT 'Full HD 25p (H.264 / AAC stereo)', '{"video_codec":"h264","video_profile":"","width":1920,"height":1080,"sar":"1:1","pix_fmt":"yuv420p","fps":"25","scan":"progressive","gop":50,"audio_codec":"aac","audio_rate":48000,"audio_channels":2,"audio_streams":1,"av_start_ms":100,"av_end_ms":100}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `channel_audit_standards`);

INSERT INTO `channel_audit_standards` (`name`, `spec`, `created_at`, `updated_at`)
SELECT 'HD Ready 25p, joined chunks (H.264 / AAC stereo)', '{"video_codec":"h264","video_profile":"","width":1280,"height":720,"sar":"1:1","pix_fmt":"yuv420p","fps":"25","scan":"progressive","gop":100,"gop_mode":"max","audio_codec":"aac","audio_rate":48000,"audio_channels":2,"audio_streams":1,"av_start_ms":100,"av_end_ms":100,"max_glitches":10,"max_glitch_ms":150}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `channel_audit_standards` WHERE `name` = 'HD Ready 25p, joined chunks (H.264 / AAC stereo)');
