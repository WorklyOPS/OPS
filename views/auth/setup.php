<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Initial Administrator Setup — Workly</title>
  <link rel="stylesheet" href="/assets/css/workly.css">
</head>
<body class="auth-body">
  <main class="auth-card">
    <div class="auth-logo-row">
      <div class="brand-mark">W</div>
      <div>
        <div style="font-weight:700;font-size:17px;">Workly</div>
        <div class="muted" style="font-size:12px;">First-Time Production Setup</div>
      </div>
    </div>

    <h1 class="auth-title">Create Primary Administrator</h1>
    <p class="muted" style="margin-bottom:20px;">
      PostgreSQL currently contains zero user accounts. Create the first System Administrator to unlock your workspace. This installer locks automatically once completed.
    </p>

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
        <input class="input" id="setup-pass" name="password" type="password" minlength="8" required placeholder="Strong production password">
      </div>

      <div class="form-group">
        <label class="form-label" for="setup-confirm">Confirm Password</label>
        <input class="input" id="setup-confirm" name="password_confirm" type="password" minlength="8" required placeholder="Re-enter password">
      </div>

      <button class="btn btn-primary" type="submit" style="width:100%;padding:11px;margin-top:6px;">Create Administrator & Enter</button>
    </form>
  </main>
</body>
</html>
