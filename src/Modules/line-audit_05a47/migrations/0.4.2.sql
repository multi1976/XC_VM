-- 0.4.2: the ISP history is read again from the whole activity log. Rows recorded with a lines_activity row id taken for a time are gone with it.
DELETE FROM `line_audit_isps`;
