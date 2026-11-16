<?php
/**
 * Workly Secure Upload Controller (VA Avatars + Client PDF Contracts)
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
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
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
        if ($sub === 'contract') {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->uploadContract($user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Upload endpoint not found']);
    }

    private function uploadAvatar(array $actor): void
    {
        $targetUserId = SecurityHelper::sanitizePlainText($_POST['user_id'] ?? $actor['id'], 64);
        if ($actor['role'] !== 'admin' && $targetUserId !== $actor['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: Only administrators can upload photos for other staff']);
            return;
        }

        if (empty($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid image upload is required']);
            return;
        }

        $file = $_FILES['avatar'];
        $maxBytes = max(1048576, (int) Secrets::get('UPLOAD_MAX_AVATAR_BYTES', '5242880'));
        if ((int) $file['size'] > $maxBytes) {
            http_response_code(413);
            echo json_encode(['error' => 'Avatar file exceeds 5MB limit']);
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extMap[$mime])) {
            http_response_code(422);
            echo json_encode(['error' => 'Unsupported image type. Allowed: JPG, PNG, WEBP']);
            return;
        }

        $dir = __DIR__ . '/../../uploads/avatars';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = 'avatar-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $targetUserId) . '-' . bin2hex(random_bytes(6)) . '.' . $extMap[$mime];
        $dest = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save uploaded image']);
            return;
        }

        $publicUrl = '/uploads/avatars/' . $filename;
        $stmt = $this->db->prepare('UPDATE users SET avatar_url = :url, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':url' => $publicUrl, ':id' => $targetUserId]);

        CacheService::invalidatePrefix($this->db, 'users:');
        AuditLogger::log('AVATAR_UPLOADED', 'success', "Updated avatar for {$targetUserId}", $actor['id'], $actor['role'], $this->db);

        echo json_encode(['success' => true, 'avatar_url' => $publicUrl]);
    }

    private function uploadContract(array $actor): void
    {
        $clientId = SecurityHelper::sanitizePlainText($_POST['client_id'] ?? '', 64);
        if ($clientId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Client ID is required']);
            return;
        }

        if (empty($_FILES['contract_pdf']) || ($_FILES['contract_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid PDF contract file is required']);
            return;
        }

        $file = $_FILES['contract_pdf'];
        $maxBytes = max(1048576, (int) Secrets::get('UPLOAD_MAX_CONTRACT_BYTES', '15728640'));
        if ((int) $file['size'] > $maxBytes) {
            http_response_code(413);
            echo json_encode(['error' => 'Contract PDF exceeds 15MB limit']);
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $header = (string) file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($mime !== 'application/pdf' || $header !== '%PDF-') {
            http_response_code(422);
            echo json_encode(['error' => 'Strict validation failed: File must be an authentic PDF document']);
            return;
        }

        $dir = __DIR__ . '/../../uploads/contracts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = 'contract-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $clientId) . '-' . bin2hex(random_bytes(6)) . '.pdf';
        $dest = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to store contract PDF']);
            return;
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
        AuditLogger::log('CONTRACT_PDF_UPLOADED', 'success', "Uploaded signed contract PDF for client {$clientId}", $actor['id'], $actor['role'], $this->db);

        echo json_encode(['success' => true, 'contract_pdf_url' => $publicUrl]);
    }
}
