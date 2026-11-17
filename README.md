# Workly — Virtual Assistant Operations Platform (PHP 8 + PostgreSQL)

Workly is a production Virtual Assistant (VA) operations, client CRM, SLA performance reporting, PTO governance, and internal email collaboration platform built with **100% Native PHP 8 + PostgreSQL**, separated CSS (`/assets/css/workly.css`), and vanilla JavaScript (`/assets/js/app.js`).


---

## Architecture Overviews - UPDATESs

- **Root Front Controller**: `/index.php` + Apache/LiteSpeed rules in `/.htaccess`
- **PostgreSQL Connection & Secrets**:
  - `/config/env_loader.php` — Zero-dependency `.env` loader
  - `/config/secrets.php` — Central `Secrets::get()` key/token registry
  - `/config/database.php` — Singleton `PDO` (`pgsql:`) with prepared statements (`ATTR_EMULATE_PREPARES => false`)
  - `/config/.env.example` — Template for `/config/.env` (protected from HTTP by `/config/.htaccess`)
- **PostgreSQL Schema**:
  - `/sql/workly_postgres_schema.sql` — 23 normalized tables, 3 analytical views, and `fn_purge_expired_cache()` (pure schema, zero sample data)
  - `/sql/create_initial_admin.sql` — Optional CLI/SQL bootstrap template (alternatively use browser `/setup` once)
- **Middleware & Core Services**:
  - `/middleware/AuthMiddleware.php` — Strict PostgreSQL session/JWT verification + `requireRoles()` RBAC
  - `/middleware/CsrfMiddleware.php` — Cryptographic CSRF verification on all forms & API mutations
  - `/services/SecurityHelper.php` — CSP/XSS headers, session IP-subnet/UA fingerprint integrity, input/URL sanitization, Turnstile check
  - `/services/CacheService.php` — Two-tier cache (APCu RAM + PostgreSQL `cache_store` + HTTP `ETag`)
  - `/services/AuditLogger.php` — Persistent PostgreSQL `audit_logs` + `.txt` download exporter

- **Controllers & Server-Rendered Views**:
  - `/controllers/AuthController.php` & `/controllers/PageController.php`
  - `/controllers/api/*` — 11 domain REST API controllers (`/api/v1/*`)
  - `/views/**` — Clean modular `.php` views
  - `/assets/css/workly.css` & `/assets/js/app.js` — Separated presentation and client interactivity

---


