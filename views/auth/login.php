<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — Workly Virtual Assistant Platform</title>
  <meta name="description" content="Secure authentication for Workly Virtual Assistant Operations.">
  <link rel="stylesheet" href="/assets/css/workly.css">
  <?php if (!empty($secretsStatus['turnstile_configured'])): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <?php endif; ?>
</head>
<body class="auth-body">
  <main class="auth-card">
    <div class="auth-logo-row">
      <div class="brand-mark">W</div>
      <div>
        <div style="font-weight:700;font-size:17px;">Workly</div>
        <div class="muted" style="font-size:12px;">Virtual Assistant Operations</div>
      </div>
    </div>

    <h1 class="auth-title">Sign in to your workspace</h1>
    <p class="muted" style="margin-bottom:22px;">Enter your Workly staff credentials to continue.</p>

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
        <input class="input" id="login-password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password">
      </div>

      <?php if (!empty($secretsStatus['turnstile_configured'])): ?>
        <div class="form-group">
          <div class="cf-turnstile" data-sitekey="<?= SecurityHelper::e((string) $secretsStatus['turnstile_site_key']) ?>"></div>
        </div>
      <?php endif; ?>

      <button class="btn btn-primary" type="submit" style="width:100%;padding:11px;margin-top:6px;">Sign In</button>
    </form>

    <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);font-size:12px;" class="muted">
      Access is provisioned exclusively by your System Administrator in PostgreSQL.
    </div>
  </main>
</body>
</html>
