<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Initial Administrator Setup — Workly</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/workly.css">
</head>
<body class="auth-body">
  <main class="auth-split">
    <section class="auth-hero-pane">
      <div>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
          <div class="brand-mark">W</div>
          <div>
            <div style="font-weight:800;font-size:19px;">Workly</div>
            <div class="muted" style="font-size:12px;">First-Time Production Setup</div>
          </div>
        </div>
        <h1 style="font-size:26px;line-height:1.25;margin-bottom:12px;">Initialize Primary System Administrator</h1>
        <p class="muted" style="font-size:13.5px;">
          Your workspace currently contains zero user accounts. Provision your root System Administrator account to unlock Workly. Once saved, this installer locks permanently.
        </p>
      </div>
      <div class="muted" style="font-size:11.5px;">
        Zero Demo Accounts • Direct Admin Provisioning
      </div>
    </section>

    <section class="auth-form-pane">
      <h2 style="font-size:21px;margin-bottom:6px;">Create Primary Administrator</h2>
      <p class="muted" style="margin-bottom:20px;font-size:13px;">Minimum 8-character password with bcrypt encryption.</p>

      <?php if (!empty($pageError)): ?>
        <div class="notice notice-error"><?= SecurityHelper::e($pageError) ?></div>
      <?php endif; ?>

      <form method="POST" action="/setup" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= SecurityHelper::e($csrfToken) ?>">

        <div class="form-group">
          <label class="form-label" for="setup-name">Administrator Full Name</label>
          <input class="input" id="setup-name" name="name" type="text" required placeholder="Full name">
        </div>

        <div class="form-group">
          <label class="form-label" for="setup-email">Administrator Email</label>
          <input class="input" id="setup-email" name="email" type="email" required placeholder="admin@company.com">
        </div>

        <div class="form-group">
          <label class="form-label" for="setup-pass">Password (minimum 8 characters)</label>
          <div class="password-wrap">
            <input class="input" id="setup-pass" name="password" type="password" minlength="8" required placeholder="Strong production password" style="padding-right:48px;">
            <button type="button" class="password-toggle" data-toggle-password="setup-pass" aria-label="Toggle password visibility" aria-pressed="false" title="Toggle password visibility">
              <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="setup-confirm">Confirm Password</label>
          <div class="password-wrap">
            <input class="input" id="setup-confirm" name="password_confirm" type="password" minlength="8" required placeholder="Re-enter password" style="padding-right:48px;">
            <button type="button" class="password-toggle" data-toggle-password="setup-confirm" aria-label="Toggle confirm password visibility" aria-pressed="false" title="Toggle password visibility">
              <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button class="btn btn-primary" type="submit" style="width:100%;padding:11px;margin-top:6px;">Create Administrator &amp; Enter</button>
      </form>
    </section>
  </main>

  <script src="/assets/js/app.js"></script>
</body>
</html>
