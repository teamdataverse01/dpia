-- 0002 make audit_log append-only at the database level (defence in depth; the app never updates/deletes it).
-- PostgreSQL-only syntax (PL/pgSQL trigger). MySQL/MariaDB equivalent:
--   CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
--     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';
--   (and the same for BEFORE DELETE)
-- Better still, grant the application DB user only INSERT/SELECT on audit_log.

CREATE OR REPLACE FUNCTION audit_log_block_changes() RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'audit_log is append-only';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
  FOR EACH ROW EXECUTE FUNCTION audit_log_block_changes();
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
  FOR EACH ROW EXECUTE FUNCTION audit_log_block_changes();
