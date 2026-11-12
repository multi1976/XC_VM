ALTER TABLE `line_audit_samples` ADD COLUMN IF NOT EXISTS `ua_list` varchar(255) NOT NULL DEFAULT '' AFTER `isp_list`;
