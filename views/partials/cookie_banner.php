<?php
$cookieConsentState = SecurityHelper::getCookieConsent();
if ($cookieConsentState === 'unset'):
?>
<aside id="workly-cookie-banner" class="cookie-banner no-print" role="dialog" aria-live="polite" aria-label="Session Cookie Preferences">
  <div class="cookie-banner-icon" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
  </div>
  <div class="cookie-banner-body">
    <strong>Essential Session Cookies</strong>
    <p class="muted">We use strictly necessary, hijack-protected cookies to keep your session signed in securely across visits.</p>
  </div>
  <div class="cookie-banner-actions">
    <button type="button" id="btn-cookie-decline" class="btn btn-secondary btn-sm">Decline</button>
    <button type="button" id="btn-cookie-accept" class="btn btn-primary btn-sm">Accept Necessary</button>
  </div>
</aside>
<?php endif; ?>
