-- =======================================================================
-- OPTIONAL: CREATE INITIAL SYSTEM ADMINISTRATOR VIA SQL
-- 1) Generate a real bcrypt hash using PHP on your Hostinger SSH/terminal:
--    php -r "echo password_hash('YOUR_REAL_ADMIN_PASSWORD', PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;"
-- 2) Replace the placeholders below and execute once in PostgreSQL.
-- (Alternatively, visit https://your-domain.com/setup on an empty database.)
-- =======================================================================

INSERT INTO users (
  id, name, email, password_hash, role, hire_date, is_active, is_suspended
) VALUES (
  'admin-001',
  'System Administrator',
  'admin@your-domain.com',
  'REPLACE_WITH_REAL_PHP_PASSWORD_HASH',
  'admin',
  CURRENT_DATE,
  TRUE,
  FALSE
);
