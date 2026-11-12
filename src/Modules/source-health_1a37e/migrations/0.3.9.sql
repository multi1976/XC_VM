-- 0.3.9: an account is now keyed by host, port, user and password, however the source URL spells its path.
-- The answers stored under the old keys are dropped, the audit asks each account again within a few runs.
DELETE FROM `source_health_accounts`;
