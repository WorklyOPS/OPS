<?php
/**
 * Workly Admin Security & Audit Logs REST API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

final class AuditLogApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        if ($method !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $actor = AuthMiddleware::authenticateApi($this->db);
        AuthMiddleware::requireRoles(['admin'], $actor);

        $month = SecurityHelper::sanitizePlainText($_GET['month'] ?? '', 32);
        $userId = SecurityHelper::sanitizePlainText($_GET['user_id'] ?? '', 64);
        $download = !empty($_GET['download']);

        if ($download) {
            if ($userId !== '') {
                $txt = AuditLogger::exportUserActivityTxt($this->db, $userId);
                $fname = 'workly_activity_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) . '_' . date('Ymd') . '.txt';
            } else {
                $res = AuditLogger::queryLogEntries($this->db, $month ?: null, null, 1000);
                $txt = "WORKLY SYSTEM AUDIT LOG (POSTGRESQL SOURCE)\nMonth Filter: " . ($month ?: 'ALL') . "\nGenerated UTC: " . gmdate('Y-m-d\TH:i:s\Z') . "\n" . str_repeat('=', 72) . "\n\n" . $res['raw'] . "\n";
                $fname = 'workly_audit_logs_' . ($month ?: date('Ymd')) . '.txt';
            }

            header('Content-Type: text/plain; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Cache-Control: no-store');
            echo $txt;
            return;
        }

        $cacheKey = 'audit_logs:' . md5($month . '|' . $userId);
        $hit = false;

        $payload = CacheService::remember($this->db, $cacheKey, 20, function () use ($month, $userId): array {
            $months = AuditLogger::listMonths($this->db);
            $res = AuditLogger::queryLogEntries($this->db, $month ?: null, $userId ?: null, 250);
            return [
                'months'  => $months,
                'entries' => $res['entries'],
                'raw'     => $res['raw'],
            ];
        }, $hit);

        CacheService::sendJsonWithEtag($payload, $hit, 15);
    }
}
