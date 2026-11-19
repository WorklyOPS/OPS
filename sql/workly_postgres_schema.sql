-- =======================================================================
-- WORKLY VIRTUAL ASSISTANT MANAGEMENT PLATFORM
-- PRODUCTION POSTGRESQL DATABASE SCHEMA (PHP 8 + POSTGRESQL)
-- Pure structural schema & views ONLY (zero demo accounts or test data).
-- =======================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS users (
  id VARCHAR(64) PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'va' CHECK (role IN ('admin', 'team_leader', 'hrm', 'va')),
  avatar_url VARCHAR(512) DEFAULT NULL,
  phone VARCHAR(64) DEFAULT NULL,
  bio TEXT DEFAULT NULL,
  hire_date DATE NOT NULL DEFAULT CURRENT_DATE,
  hourly_rate NUMERIC(10,2) NOT NULL DEFAULT 0.00,
  total_earnings NUMERIC(12,2) NOT NULL DEFAULT 0.00,
  pto_balance_days INTEGER NOT NULL DEFAULT 15,
  pto_taken_days INTEGER NOT NULL DEFAULT 0,
  team_leader_id VARCHAR(64) DEFAULT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  is_suspended BOOLEAN NOT NULL DEFAULT FALSE,
  suspended_reason VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_users_role ON users (role);
CREATE INDEX IF NOT EXISTS idx_users_tl ON users (team_leader_id);
CREATE INDEX IF NOT EXISTS idx_users_active_role ON users (is_active, role);

CREATE TABLE IF NOT EXISTS va_specialties (
  id SERIAL PRIMARY KEY,
  va_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  specialty VARCHAR(64) NOT NULL,
  CONSTRAINT uk_va_specialty UNIQUE (va_id, specialty)
);

CREATE TABLE IF NOT EXISTS va_responsibilities (
  id SERIAL PRIMARY KEY,
  va_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  responsibility VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS clients (
  id VARCHAR(64) PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  agency_name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NOT NULL,
  phone VARCHAR(64) DEFAULT NULL,
  state VARCHAR(64) DEFAULT NULL,
  country VARCHAR(64) NOT NULL DEFAULT 'United States',
  website VARCHAR(255) DEFAULT NULL,
  linkedin VARCHAR(255) DEFAULT NULL,
  additional_email VARCHAR(191) DEFAULT NULL,
  additional_phone VARCHAR(64) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  focus_va_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  team_leader_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  contract_status VARCHAR(32) NOT NULL DEFAULT 'active' CHECK (contract_status IN ('active', 'pending', 'cancelled')),
  contracted_hours_per_month NUMERIC(8,2) NOT NULL DEFAULT 40.00,
  consumed_hours_month NUMERIC(8,2) NOT NULL DEFAULT 0.00,
  contract_signed_date DATE NOT NULL DEFAULT CURRENT_DATE,
  contract_pdf_url VARCHAR(512) DEFAULT NULL,
  internal_notes TEXT DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_clients_va ON clients (focus_va_id);
CREATE INDEX IF NOT EXISTS idx_clients_tl ON clients (team_leader_id);
CREATE INDEX IF NOT EXISTS idx_clients_status ON clients (contract_status);

CREATE TABLE IF NOT EXISTS client_activities (
  id VARCHAR(64) PRIMARY KEY,
  client_id VARCHAR(64) NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  author_id VARCHAR(64) DEFAULT NULL,
  author_name VARCHAR(191) NOT NULL,
  description TEXT NOT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'note' CHECK (type IN ('contract', 'meeting', 'note', 'update')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_activity_client ON client_activities (client_id, created_at DESC);

CREATE TABLE IF NOT EXISTS tasks (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'todo' CHECK (status IN ('backlog', 'todo', 'in_progress', 'in_review', 'completed')),
  priority VARCHAR(32) NOT NULL DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high')),
  assigned_va_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  client_id VARCHAR(64) NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  created_by_id VARCHAR(64) NOT NULL,
  creator_role VARCHAR(32) NOT NULL DEFAULT 'team_leader',
  deadline DATE NOT NULL,
  estimated_hours NUMERIC(6,2) NOT NULL DEFAULT 0.00,
  extra_hours_logged NUMERIC(6,2) NOT NULL DEFAULT 0.00,
  completed_at TIMESTAMPTZ DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tasks_va ON tasks (assigned_va_id);
CREATE INDEX IF NOT EXISTS idx_tasks_client ON tasks (client_id);
CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks (status);
CREATE INDEX IF NOT EXISTS idx_tasks_va_status_deadline ON tasks (assigned_va_id, status, deadline);
CREATE INDEX IF NOT EXISTS idx_tasks_client_deadline ON tasks (client_id, deadline);

CREATE TABLE IF NOT EXISTS task_notes (
  id VARCHAR(64) PRIMARY KEY,
  task_id VARCHAR(64) NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  author_id VARCHAR(64) NOT NULL,
  author_name VARCHAR(191) NOT NULL,
  author_role VARCHAR(32) NOT NULL,
  is_instruction BOOLEAN NOT NULL DEFAULT TRUE,
  content TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_note_task ON task_notes (task_id);

CREATE TABLE IF NOT EXISTS task_comments (
  id VARCHAR(64) PRIMARY KEY,
  task_id VARCHAR(64) NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  author_id VARCHAR(64) NOT NULL,
  author_name VARCHAR(191) NOT NULL,
  author_role VARCHAR(32) NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_comment_task ON task_comments (task_id);

CREATE TABLE IF NOT EXISTS pto_requests (
  id VARCHAR(64) PRIMARY KEY,
  va_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  va_name VARCHAR(191) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  business_days_count INTEGER NOT NULL DEFAULT 1,
  reason VARCHAR(255) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
  requested_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  reviewed_by VARCHAR(191) DEFAULT NULL,
  reviewed_at TIMESTAMPTZ DEFAULT NULL,
  rejection_note TEXT DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_pto_va_status ON pto_requests (va_id, status);

CREATE TABLE IF NOT EXISTS badges (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(191) NOT NULL,
  description TEXT NOT NULL,
  icon_name VARCHAR(64) NOT NULL DEFAULT 'Award',
  tier VARCHAR(32) NOT NULL DEFAULT 'bronze' CHECK (tier IN ('bronze', 'silver', 'gold', 'diamond')),
  trigger_type VARCHAR(64) NOT NULL DEFAULT 'custom' CHECK (trigger_type IN ('tenure_months', 'tasks_completed', 'on_time_streak', 'client_praise', 'sop_authored', 'custom')),
  required_threshold INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS user_badges (
  id VARCHAR(64) PRIMARY KEY,
  user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  badge_id VARCHAR(64) NOT NULL REFERENCES badges(id) ON DELETE CASCADE,
  earned_at DATE NOT NULL DEFAULT CURRENT_DATE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT uk_user_badge UNIQUE (user_id, badge_id)
);

CREATE TABLE IF NOT EXISTS va_monthly_earnings (
  id SERIAL PRIMARY KEY,
  va_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  record_year INTEGER NOT NULL,
  record_month VARCHAR(16) NOT NULL,
  month_number INTEGER NOT NULL,
  amount NUMERIC(10,2) NOT NULL DEFAULT 0.00,
  hours_billed NUMERIC(8,2) NOT NULL DEFAULT 0.00,
  recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT uk_va_year_month UNIQUE (va_id, record_year, month_number)
);

CREATE TABLE IF NOT EXISTS va_task_metrics (
  id SERIAL PRIMARY KEY,
  va_id VARCHAR(64) NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  tasks_completed INTEGER NOT NULL DEFAULT 0,
  tasks_in_progress INTEGER NOT NULL DEFAULT 0,
  on_time_rate NUMERIC(5,2) NOT NULL DEFAULT 100.00,
  velocity_score NUMERIC(5,2) NOT NULL DEFAULT 100.00,
  stats_json JSONB DEFAULT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS extra_hours_logs (
  id VARCHAR(64) PRIMARY KEY,
  va_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  va_name VARCHAR(191) NOT NULL,
  client_id VARCHAR(64) NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  client_name VARCHAR(191) NOT NULL,
  task_id VARCHAR(64) DEFAULT NULL,
  task_title VARCHAR(255) DEFAULT 'Deliverable Overtime',
  extra_hours NUMERIC(6,2) NOT NULL,
  date DATE NOT NULL,
  justification TEXT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'acknowledged', 'approved')),
  logged_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_eh_va_status_date ON extra_hours_logs (va_id, status, date);
CREATE INDEX IF NOT EXISTS idx_eh_client ON extra_hours_logs (client_id);

CREATE TABLE IF NOT EXISTS perks (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(191) NOT NULL,
  category VARCHAR(64) NOT NULL DEFAULT 'Equipment & Hardware',
  value NUMERIC(10,2) NOT NULL DEFAULT 0.00,
  description TEXT NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS user_perks (
  id SERIAL PRIMARY KEY,
  perk_id VARCHAR(64) NOT NULL REFERENCES perks(id) ON DELETE CASCADE,
  user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  granted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT uk_perk_user UNIQUE (perk_id, user_id)
);

CREATE TABLE IF NOT EXISTS knowledge_categories (
  id SERIAL PRIMARY KEY,
  name VARCHAR(191) NOT NULL UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS knowledge_docs (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(191) NOT NULL,
  client_id VARCHAR(64) DEFAULT NULL REFERENCES clients(id) ON DELETE SET NULL,
  content TEXT NOT NULL,
  author VARCHAR(191) NOT NULL,
  file_name VARCHAR(255) DEFAULT NULL,
  file_type VARCHAR(16) DEFAULT NULL,
  file_size VARCHAR(32) DEFAULT NULL,
  last_updated DATE NOT NULL DEFAULT CURRENT_DATE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_kdoc_client ON knowledge_docs (client_id);

CREATE TABLE IF NOT EXISTS calendar_meetings (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  va_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  client_id VARCHAR(64) DEFAULT NULL REFERENCES clients(id) ON DELETE SET NULL,
  include_client BOOLEAN NOT NULL DEFAULT FALSE,
  start_time TIMESTAMPTZ NOT NULL,
  end_time TIMESTAMPTZ NOT NULL,
  meeting_type VARCHAR(32) NOT NULL DEFAULT 'team_sync' CHECK (meeting_type IN ('client_meeting', 'team_sync', 'checkpoint', 'review')),
  meeting_link VARCHAR(512) DEFAULT NULL,
  created_by_tl_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  google_synced BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_meet_va_start ON calendar_meetings (va_id, start_time);
CREATE INDEX IF NOT EXISTS idx_meet_client ON calendar_meetings (client_id);
CREATE INDEX IF NOT EXISTS idx_meet_tl ON calendar_meetings (created_by_tl_id);

CREATE TABLE IF NOT EXISTS email_messages (
  id VARCHAR(64) PRIMARY KEY,
  thread_id VARCHAR(64) NOT NULL,
  sender_name VARCHAR(191) NOT NULL,
  sender_email VARCHAR(191) NOT NULL,
  recipient_email VARCHAR(191) NOT NULL,
  cc_email VARCHAR(191) DEFAULT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  folder VARCHAR(32) NOT NULL DEFAULT 'inbox' CHECK (folder IN ('inbox', 'sent', 'drafts')),
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  client_ref_id VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_email_thread_created ON email_messages (thread_id, created_at);
CREATE INDEX IF NOT EXISTS idx_email_client ON email_messages (client_ref_id);
CREATE INDEX IF NOT EXISTS idx_email_sender ON email_messages (sender_email);
CREATE INDEX IF NOT EXISTS idx_email_recipient ON email_messages (recipient_email);

CREATE TABLE IF NOT EXISTS staff_connected_mailboxes (
  user_id VARCHAR(64) PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  provider VARCHAR(32) NOT NULL DEFAULT 'gmail' CHECK (provider IN ('gmail', 'google_workspace', 'outlook')),
  email VARCHAR(191) NOT NULL,
  oauth_status VARCHAR(32) NOT NULL DEFAULT 'active' CHECK (oauth_status IN ('active', 'needs_auth', 'syncing')),
  sync_interval_minutes INTEGER NOT NULL DEFAULT 5,
  oauth_scope TEXT DEFAULT NULL,
  last_synced_at TIMESTAMPTZ DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id VARCHAR(64) PRIMARY KEY,
  action VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'success' CHECK (status IN ('success', 'blocked', 'warning')),
  user_id VARCHAR(64) NOT NULL,
  user_role VARCHAR(32) NOT NULL DEFAULT 'system',
  ip_address VARCHAR(64) NOT NULL DEFAULT '127.0.0.1',
  details TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_status ON audit_logs (status, created_at DESC);

CREATE TABLE IF NOT EXISTS cache_store (
  cache_key VARCHAR(191) PRIMARY KEY,
  cache_value TEXT NOT NULL,
  expires_at TIMESTAMPTZ NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cache_expires ON cache_store (expires_at);

-- =======================================================================
-- ANALYTICAL VIEWS & CACHE MAINTENANCE FUNCTION
-- =======================================================================

CREATE OR REPLACE VIEW v_va_performance_summary AS
SELECT
  u.id AS va_id,
  u.name AS va_name,
  u.email AS va_email,
  u.hourly_rate,
  u.total_earnings,
  u.pto_balance_days,
  u.pto_taken_days,
  COUNT(t.id)::INTEGER AS total_tasks,
  COUNT(t.id) FILTER (WHERE t.status = 'completed')::INTEGER AS completed_tasks,
  COUNT(t.id) FILTER (WHERE t.status = 'in_progress')::INTEGER AS in_progress_tasks,
  COALESCE(SUM(t.estimated_hours), 0) AS total_estimated_hours,
  COALESCE(SUM(t.extra_hours_logged), 0) AS total_extra_hours,
  COALESCE(m.on_time_rate, 100.00) AS on_time_rate,
  COALESCE(m.velocity_score, 100.00) AS velocity_score
FROM users u
LEFT JOIN tasks t ON u.id = t.assigned_va_id
LEFT JOIN va_task_metrics m ON u.id = m.va_id
WHERE u.role = 'va'
GROUP BY u.id, u.name, u.email, u.hourly_rate, u.total_earnings, u.pto_balance_days, u.pto_taken_days, m.on_time_rate, m.velocity_score;

CREATE OR REPLACE VIEW v_client_capacity_overview AS
SELECT
  c.id AS client_id,
  c.agency_name,
  c.name AS executive_contact,
  c.contract_status,
  c.contracted_hours_per_month,
  c.consumed_hours_month,
  ROUND((c.consumed_hours_month / NULLIF(c.contracted_hours_per_month, 0)) * 100, 1) AS utilization_pct,
  c.focus_va_id,
  u.name AS focus_va_name,
  COUNT(t.id)::INTEGER AS linked_tasks_count
FROM clients c
LEFT JOIN users u ON c.focus_va_id = u.id
LEFT JOIN tasks t ON c.id = t.client_id
GROUP BY c.id, c.agency_name, c.name, c.contract_status, c.contracted_hours_per_month, c.consumed_hours_month, c.focus_va_id, u.name;

CREATE OR REPLACE VIEW v_weekly_task_metrics AS
SELECT
  assigned_va_id,
  TO_CHAR(deadline, 'YYYY-MM') AS month_key,
  DATE_TRUNC('week', deadline)::DATE AS week_start,
  COUNT(*)::INTEGER AS total_deliverables,
  COUNT(*) FILTER (WHERE status = 'completed')::INTEGER AS completed_deliverables,
  COALESCE(SUM(estimated_hours), 0) AS estimated_hours,
  COALESCE(SUM(extra_hours_logged), 0) AS extra_hours
FROM tasks
GROUP BY assigned_va_id, TO_CHAR(deadline, 'YYYY-MM'), DATE_TRUNC('week', deadline)::DATE;

CREATE OR REPLACE FUNCTION fn_purge_expired_cache()
RETURNS INTEGER
LANGUAGE plpgsql
AS $$
DECLARE
  deleted_count INTEGER;
BEGIN
  DELETE FROM cache_store WHERE expires_at <= NOW();
  GET DIAGNOSTICS deleted_count = ROW_COUNT;
  RETURN deleted_count;
END;
$$;

COMMIT;
