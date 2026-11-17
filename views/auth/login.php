<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — Workly Virtual Assistant Platform</title>
  <meta name="description" content="Secure authentication for Workly Virtual Assistant Operations.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/workly.css">
  <?php if (!empty($secretsStatus['turnstile_configured'])): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <?php endif; ?>
</head>
<body class="auth-body">
  <main class="auth-split">
    <section class="auth-hero-pane">
      <div>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
          <div class="brand-mark">W</div>
          <div>
            <div style="font-weight:800;font-size:19px;letter-spacing:-0.02em;">Workly</div>
            <div class="muted" style="font-size:12px;">Virtual Assistant Operations Suite</div>
          </div>
        </div>

        <h1 style="font-size:28px;line-height:1.2;margin-bottom:12px;">Precision pod orchestration, SLA tracking &amp; client CRM.</h1>
        <p class="muted" style="font-size:13.5px;margin-bottom:22px;">
          Unified operational workspace powered by PostgreSQL 16 and Native PHP 8 with strict role-scoped access control.
        </p>

        <div class="auth-pillar">
          <span class="auth-pillar-icon">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/></svg>
          </span>
          <div>
            <strong style="font-size:13px;">Weekly Deliverable Kanban</strong>
            <div class="muted" style="font-size:12px;">Drag-and-drop stages, right-click quick actions, VA execution notes &amp; overtime logs.</div>
          </div>
        </div>

        <div class="auth-pillar">
          <span class="auth-pillar-icon">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </span>
          <div>
            <strong style="font-size:13px;">Hardened Perimeter Security</strong>
            <div class="muted" style="font-size:12px;">Strict CSP, CSRF rotation, brute-force lockout, and CAPTCHA human verification.</div>
          </div>
        </div>

        <div class="auth-pillar">
          <span class="auth-pillar-icon">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
          </span>
          <div>
            <strong style="font-size:13px;">Automated PTO &amp; SLA Triggers</strong>
            <div class="muted" style="font-size:12px;">Instant Team Leader PostgreSQL notifications, status audit trails &amp; PDF executive reporting.</div>
          </div>
        </div>
      </div>

      <div class="muted" style="font-size:11.5px;margin-top:24px;">
        Production Workspace • Encrypted TLS Session &amp; PostgreSQL Audit Trail
      </div>
    </section>

    <section class="auth-form-pane">
      <h2 style="font-size:22px;margin-bottom:6px;">Sign in to your workspace</h2>
      <p class="muted" style="margin-bottom:20px;font-size:13px;">Enter your Workly staff credentials and complete security verification.</p>

      <?php if (!empty($pageError)): ?>
        <div class="notice notice-error"><?= SecurityHelper::e($pageError) ?></div>
      <?php endif; ?>

      <form method="POST" action="/login" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= SecurityHelper::e($csrfToken) ?>">

        <div class="form-group">
          <label class="form-label" for="login-email">Work Email</label>
          <input class="input" id="login-email" name="email" type="email" required autocomplete="email" placeholder="name@company.com">
        </div>

        <div class="form-group">
          <label class="form-label" for="login-password">Password</label>
          <div class="password-wrap">
            <input class="input" id="login-password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password" style="padding-right:48px;">
            <button type="button" class="password-toggle" data-toggle-password="login-password" aria-label="Toggle password visibility" aria-pressed="false" title="Toggle password visibility">
              <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <?php if (!empty($secretsStatus['turnstile_configured'])): ?>
          <div class="form-group">
            <label class="form-label">Human Verification</label>
            <div class="cf-turnstile" data-sitekey="<?= SecurityHelper::e((string) $secretsStatus['turnstile_site_key']) ?>"></div>
          </div>
        <?php else: ?>
          <div class="form-group">
            <label class="form-label" for="login-captcha">Security Verification Code</label>
            <div class="captcha-box" style="margin-bottom:8px;">
              <img id="captcha-svg-img" class="captcha-img" src="/captcha.svg" alt="Security CAPTCHA Challenge" width="170" height="48">
              <button type="button" id="btn-refresh-captcha" class="btn btn-secondary btn-sm">Refresh Code</button>
            </div>
            <input class="input mono" id="login-captcha" name="captcha_code" type="text" required autocomplete="off" maxlength="8" placeholder="Enter 5 characters shown above" style="text-transform:uppercase;letter-spacing:0.15em;">
          </div>
        <?php endif; ?>

        <button class="btn btn-primary" type="submit" style="width:100%;padding:11px;margin-top:6px;">Sign In to Workspace</button>
      </form>

      <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);font-size:12px;" class="muted">
        Staff accounts are provisioned exclusively by your System Administrator in PostgreSQL.
      </div>
    </section>
  </main>

  <script src="/assets/js/app.js"></script>
</body>
</html>
