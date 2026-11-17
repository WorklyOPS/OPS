<?php
/**
 * Workly Secure Upload Controller (Staff/Client Avatars + Client PDF Contracts)
 * Hardened for PHP 8 Shared Hosting: clean JSON errors, GD sanitization, EXIF stripping,
 * and automatic PostgreSQL uploaded_assets fallback if local filesystem is read-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/secrets.php';
require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class UploadApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        if ($method !== 'POST') {
            $this->jsonError(405, 'Method not allowed');
            return;
        }

        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        $parts = explode('/', trim($uri, '/'));
        $sub = $parts[3] ?? '';

        if ($sub === 'avatar') {
            $this->uploadAvatar($user);
            return;
        }
        if ($sub === 'client-avatar') {
            $this->uploadClientAvatar($user);
            return;
        }
        if ($sub === 'contract') {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->uploadContract($user);
            return;
        }

        $this->jsonError(404, 'Upload endpoint not found');
    }

    private function jsonError(int $status, string $message): void
    {
        if (ob_get_level() > 0) {
            @ob_clean();
        }
        http_response_code($status);
        echo json_encode(['error' => $message]);
    }

    private function jsonSuccess(array $payload): void
    {
        if (ob_get_level() > 0) {
            @ob_clean();
        }
        echo json_encode($payload);
    }

    private function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds server maximum upload size limit.',
            UPLOAD_ERR_PARTIAL    => 'File upload was interrupted before completing.',
            UPLOAD_ERR_NO_FILE    => 'Please select an image file before uploading.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write temporary upload file.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
            default               => 'Valid file upload is required.',
        };
    }

    private function detectImageMime(string $tmpPath): string
    {
        if ($tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath)) {
            return '';
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = @finfo_file($finfo, $tmpPath);
                if (is_string($detected)) {
                    $mime = $detected;
                }
                @finfo_close($finfo);
            }
        }

        if ($mime === '' && function_exists('getimagesize')) {
            $info = @getimagesize($tmpPath);
            if (is_array($info) && !empty($info['mime'])) {
                $mime = (string) $info['mime'];
            }
        }

        if ($mime === '') {
            $head = @file_get_contents($tmpPath, false, null, 0, 12);
            if (is_string($head)) {
                if (str_starts_with($head, "\xFF\xD8\xFF")) {
                    $mime = 'image/jpeg';
                } elseif (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
                    $mime = 'image/png';
                } elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
                    $mime = 'image/webp';
                }
            }
        }

        return $mime;
    }

    /**
     * Re-encode via PHP GD when available to strip EXIF metadata & resize oversized avatars cleanly.
     */
    private function sanitizeAndEncodeImage(string $tmpPath, string $mime): array
    {
        $rawFallback = @file_get_contents($tmpPath);
        if (!is_string($rawFallback) || $rawFallback === '') {
            throw new RuntimeException('Unable to read uploaded image bytes.');
        }

        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        $ext = $extMap[$mime] ?? 'jpg';

        if (!function_exists('getimagesize') || !function_exists('imagecreatetruecolor')) {
            return ['bytes' => $rawFallback, 'mime' => $mime, 'ext' => $ext];
        }

        $src = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmpPath) : false,
            'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmpPath) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
            default      => false,
        };

        if (!$src) {
            return ['bytes' => $rawFallback, 'mime' => $mime, 'ext' => $ext];
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $maxDim = 512;

        if ($srcW <= 0 || $srcH <= 0) {
            @imagedestroy($src);
            return ['bytes' => $rawFallback, 'mime' => $mime, 'ext' => $ext];
        }

        if ($srcW > $maxDim || $srcH > $maxDim) {
            $scale = min($maxDim / $srcW, $maxDim / $srcH);
            $dstW = max(1, (int) round($srcW * $scale));
            $dstH = max(1, (int) round($srcH * $scale));
        } else {
            $dstW = $srcW;
            $dstH = $srcH;
        }

        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            @imagealphablending($dst, false);
            @imagesavealpha($dst, true);
            $transparent = @imagecolorallocatealpha($dst, 255, 255, 255, 127);
            if ($transparent !== false) {
                @imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
            }
        }

        @imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        ob_start();
        $ok = match ($mime) {
            'image/jpeg' => @imagejpeg($dst, null, 88),
            'image/png'  => @imagepng($dst, null, 6),
            'image/webp' => function_exists('imagewebp') ? @imagewebp($dst, null, 86) : @imagepng($dst, null, 6),
            default      => false,
        };
        $encoded = ob_get_clean();

        @imagedestroy($src);
        @imagedestroy($dst);

        if ($ok && is_string($encoded) && $encoded !== '') {
            if ($mime === 'image/webp' && !function_exists('imagewebp')) {
                return ['bytes' => $encoded, 'mime' => 'image/png', 'ext' => 'png'];
            }
            return ['bytes' => $encoded, 'mime' => $mime, 'ext' => $ext];
        }

        return ['bytes' => $rawFallback, 'mime' => $mime, 'ext' => $ext];
    }

    private function storePublicImage(string $prefix, string $bytes, string $mime, string $ext): string
    {
        $dir = __DIR__ . '/../../uploads/avatars';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $filename = $prefix . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $filename;

        if (is_dir($dir) && is_writable($dir) && @file_put_contents($dest, $bytes) !== false) {
            return '/uploads/avatars/' . $filename;
        }

        // Seamless PostgreSQL fallback if host filesystem is read-only / non-writable
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS uploaded_assets (
              id VARCHAR(64) PRIMARY KEY,
              mime_type VARCHAR(64) NOT NULL,
              data_base64 TEXT NOT NULL,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $assetId = 'img-' . bin2hex(random_bytes(8));
        $ins = $this->db->prepare('INSERT INTO uploaded_assets (id, mime_type, data_base64) VALUES (:id, :mime, :b64)');
        $ins->execute([
            ':id'   => $assetId,
            ':mime' => $mime,
            ':b64'  => base64_encode($bytes),
        ]);

        return '/media/' . $assetId;
    }

    private function uploadAvatar(array $actor): void
    {
        $targetUserId = SecurityHelper::sanitizePlainText($_POST['user_id'] ?? $actor['id'], 64);
        if ($targetUserId === '') {
            $targetUserId = (string) $actor['id'];
        }

        if ($actor['role'] !== 'admin' && $targetUserId !== $actor['id']) {
            $this->jsonError(403, 'Forbidden: Only administrators can upload photos for other staff');
            return;
        }

        if (empty($_FILES['avatar'])) {
            $this->jsonError(400, 'No avatar file received. Check PHP post_max_size / upload_max_filesize limits.');
            return;
        }

        $errCode = (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            $this->jsonError(400, $this->describeUploadError($errCode));
            return;
        }

        $file = $_FILES['avatar'];
        $maxBytes = max(1048576, (int) Secrets::get('UPLOAD_MAX_AVATAR_BYTES', '5242880'));
        if ((int) $file['size'] > $maxBytes) {
            $this->jsonError(413, 'Avatar file exceeds 5MB limit');
            return;
        }

        $mime = $this->detectImageMime((string) $file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->jsonError(422, 'Unsupported image type. Allowed: JPG, PNG, WEBP');
            return;
        }

        try {
            $processed = $this->sanitizeAndEncodeImage((string) $file['tmp_name'], $mime);
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $targetUserId) ?: 'staff';
            $publicUrl = $this->storePublicImage('avatar-' . $safeId, $processed['bytes'], $processed['mime'], $processed['ext']);

            $stmt = $this->db->prepare('UPDATE users SET avatar_url = :url, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':url' => $publicUrl, ':id' => $targetUserId]);

            CacheService::invalidatePrefix($this->db, 'users:');
            CacheService::invalidatePrefix($this->db, 'analytics:');
            AuditLogger::log('AVATAR_UPLOADED', 'success', "Updated avatar for {$targetUserId}", (string) $actor['id'], (string) $actor['role'], $this->db);

            $this->jsonSuccess(['success' => true, 'avatar_url' => $publicUrl]);
        } catch (Throwable $e) {
            $this->jsonError(500, 'Could not process uploaded image: ' . $e->getMessage());
        }
    }

    private function uploadClientAvatar(array $actor): void
    {
        $clientId = SecurityHelper::sanitizePlainText($_POST['client_id'] ?? '', 64);
        if ($clientId === '') {
            $this->jsonError(422, 'Client ID is required');
            return;
        }

        // Verify role access to client
        $cStmt = $this->db->prepare('SELECT id, focus_va_id, team_leader_id FROM clients WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $clientId]);
        $client = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            $this->jsonError(404, 'Client account not found');
            return;
        }
        if ($actor['role'] === 'va' && $client['focus_va_id'] !== $actor['id']) {
            $this->jsonError(403, 'Forbidden: Not assigned to this client');
            return;
        }

        if (empty($_FILES['avatar'])) {
            $this->jsonError(400, 'No client logo/avatar image received.');
            return;
        }
        $errCode = (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            $this->jsonError(400, $this->describeUploadError($errCode));
            return;
        }

        $file = $_FILES['avatar'];
        $maxBytes = max(1048576, (int) Secrets::get('UPLOAD_MAX_AVATAR_BYTES', '5242880'));
        if ((int) $file['size'] > $maxBytes) {
            $this->jsonError(413, 'Image file exceeds 5MB limit');
            return;
        }

        $mime = $this->detectImageMime((string) $file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->jsonError(422, 'Unsupported image type. Allowed: JPG, PNG, WEBP');
            return;
        }

        try {
            $this->db->exec('ALTER TABLE clients ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(512) DEFAULT NULL');
            $processed = $this->sanitizeAndEncodeImage((string) $file['tmp_name'], $mime);
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $clientId) ?: 'client';
            $publicUrl = $this->storePublicImage('client-' . $safeId, $processed['bytes'], $processed['mime'], $processed['ext']);

            $stmt = $this->db->prepare('UPDATE clients SET avatar_url = :url, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':url' => $publicUrl, ':id' => $clientId]);

            CacheService::invalidatePrefix($this->db, 'clients:');
            CacheService::invalidatePrefix($this->db, 'analytics:');
            AuditLogger::log('CLIENT_AVATAR_UPLOADED', 'success', "Updated avatar/logo for client {$clientId}", (string) $actor['id'], (string) $actor['role'], $this->db);

            $this->jsonSuccess(['success' => true, 'avatar_url' => $publicUrl]);
        } catch (Throwable $e) {
            $this->jsonError(500, 'Could not process client image: ' . $e->getMessage());
        }
    }

    private function uploadContract(array $actor): void
    {
        $clientId = SecurityHelper::sanitizePlainText($_POST['client_id'] ?? '', 64);
        if ($clientId === '') {
            $this->jsonError(422, 'Client ID is required');
            return;
        }

        if (empty($_FILES['contract_pdf'])) {
            $this->jsonError(400, 'Valid PDF contract file is required');
            return;
        }
        $errCode = (int) ($_FILES['contract_pdf']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            $this->jsonError(400, $this->describeUploadError($errCode));
            return;
        }

        $file = $_FILES['contract_pdf'];
        $maxBytes = max(1048576, (int) Secrets::get('UPLOAD_MAX_CONTRACT_BYTES', '15728640'));
        if ((int) $file['size'] > $maxBytes) {
            $this->jsonError(413, 'Contract PDF exceeds 15MB limit');
            return;
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string) @finfo_file($finfo, (string) $file['tmp_name']);
                @finfo_close($finfo);
            }
        }

        $header = (string) @file_get_contents((string) $file['tmp_name'], false, null, 0, 5);
        if (($mime !== '' && $mime !== 'application/pdf') || $header !== '%PDF-') {
            $this->jsonError(422, 'Strict validation failed: File must be an authentic PDF document');
            return;
        }

        $dir = __DIR__ . '/../../uploads/contracts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $filename = 'contract-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $clientId) . '-' . bin2hex(random_bytes(6)) . '.pdf';
        $dest = $dir . '/' . $filename;

        if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
            $rawPdf = @file_get_contents((string) $file['tmp_name']);
            if (!is_string($rawPdf) || @file_put_contents($dest, $rawPdf) === false) {
                $this->jsonError(500, 'Failed to store contract PDF. Verify uploads/contracts directory permissions.');
                return;
            }
        }

        $publicUrl = '/uploads/contracts/' . $filename;
        $stmt = $this->db->prepare("
            UPDATE clients
            SET contract_pdf_url = :url,
                contract_signed_date = CURRENT_DATE,
                updated_at = NOW()
            WHERE id = :cid
        ");
        $stmt->execute([':url' => $publicUrl, ':cid' => $clientId]);

        $act = $this->db->prepare("
            INSERT INTO client_activities (id, client_id, author_id, author_name, description, type)
            VALUES (:id, :cid, :aid, :aname, :desc, 'contract')
        ");
        $act->execute([
            ':id'    => 'act-' . bin2hex(random_bytes(5)),
            ':cid'   => $clientId,
            ':aid'   => $actor['id'],
            ':aname' => $actor['name'],
            ':desc'  => "Signed PDF service agreement uploaded by {$actor['name']}.",
        ]);

        CacheService::invalidatePrefix($this->db, 'clients:');
        CacheService::invalidatePrefix($this->db, 'analytics:');
        AuditLogger::log('CONTRACT_PDF_UPLOADED', 'success', "Uploaded signed contract PDF for client {$clientId}", (string) $actor['id'], (string) $actor['role'], $this->db);

        $this->jsonSuccess(['success' => true, 'contract_pdf_url' => $publicUrl]);
    }
}
