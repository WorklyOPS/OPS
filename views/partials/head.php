<?php
$headTitle = isset($headTitle) && $headTitle !== ''
    ? (string) $headTitle
    : 'Workly — Operations & Productivity Suite';
$headDescription = isset($headDescription) && $headDescription !== ''
    ? (string) $headDescription
    : 'Operations & Productivity Suite';
$headIncludeFonts = $headIncludeFonts ?? true;
$headExtra = $headExtra ?? '';

$rootDir = dirname(__DIR__, 2);
$faviconPath = is_file($rootDir . '/assets/img/brand/favicon.ico')
    ? '/assets/img/brand/favicon.ico'
    : '/favicon.ico';
$faviconVersion = (string) (@filemtime($rootDir . $faviconPath) ?: '1');
$cssVersion = (string) (@filemtime($rootDir . '/assets/css/workly.css') ?: '1');
?>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if (!empty($csrfToken)): ?>
    <meta name="workly-csrf" content="<?= SecurityHelper::e((string) $csrfToken) ?>">
  <?php endif; ?>
  <title><?= SecurityHelper::e($headTitle) ?></title>
  <meta name="description" content="<?= SecurityHelper::e($headDescription) ?>">
  <link rel="icon" type="image/x-icon" href="<?= SecurityHelper::e($faviconPath) ?>?v=<?= SecurityHelper::e($faviconVersion) ?>">
  <link rel="shortcut icon" type="image/x-icon" href="<?= SecurityHelper::e($faviconPath) ?>?v=<?= SecurityHelper::e($faviconVersion) ?>">
  <?php if ($headIncludeFonts): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <?php endif; ?>
  <link rel="stylesheet" href="/assets/css/workly.css?v=<?= SecurityHelper::e($cssVersion) ?>">
  <script src="/assets/js/localtheme.js"></script>
  <?= $headExtra ?>
</head>
