<?php
/**
 * Workly PostgreSQL Audit Logger Service
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/CacheService.php';

final class AuditLogger
{
    public static function log(
        string $action,
        string $status,
        string $details,
        ?string $userId = null,
        ?string $userRole = null,
        ?PDO $db = null
    ): void {
        try {
            $conn = $db ?? Database::getConnection();
            $id = 'aud-' . bin2hex(random_bytes(8));
            $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $ip = substr(trim(explode(',', $fwd)[0]), 0, 64);
            $cleanAction = preg_replace('/[^A-Z0-9_]/', '_', strtoupper($action)) ?: 'EVENT';
            $cleanStatus = in_array($status, ['success', 'blocked', 'warning'], true) ? $status : 'success';
            $cleanUser = preg_replace('/[^a-zA-Z0-9_\-@.]/', '', $userId ?? 'system') ?: 'system';
            $cleanRole = preg_replace('/[^a-zA-Z0-9_\-]/', '', $userRole ?? 'system') ?: 'system';
            $cleanDetails = str_replace(["\r", "\n"], ' ', strip_tags(trim($details)));

            $stmt = $conn->prepare("
                INSERT INTO audit_logs (id, action, status, user_id, user_role, ip_address, details, created_at)
                VALUES (:id, :action, :status, :user_id, :user_role, :ip, :details, NOW())
            ");
            $stmt->execute([
                ':id'        => $id,
                ':action'    => $cleanAction,
                ':status'    => $cleanStatus,
                ':user_id'   => $cleanUser,
                ':user_role' => $cleanRole,
                ':ip'        => $ip,
                ':details'   => $cleanDetails,
            ]);

            CacheService::invalidatePrefix($conn, 'audit_logs:');
        } catch (Throwable) {
            // Keep operational flows resilient
        }
    }

    public static function listMonths(PDO $db): array
    {
        $stmt = $db->query("
            SELECT DISTINCT TO_CHAR(created_at, 'FMMonth_YYYY') AS month_key,
                   TO_CHAR(created_at, 'YYYY-MM') AS sort_key
            FROM audit_logs
            ORDER BY sort_key DESC
        ");
        return array_values(array_map(
            static fn(array $r) => (string) $r['month_key'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        ));
    }

    public static function queryLogEntries(PDO $db, ?string $monthKey = null, ?string $userIdFilter = null, int $limit = 200): array
    {
        $sql = "
            SELECT id, action, status, user_id, user_role, ip_address, details,
                   TO_CHAR(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS timestamp_iso
            FROM audit_logs
            WHERE 1=1
        ";
        $params = [];

        if ($monthKey !== null && $monthKey !== '' && preg_match('/^[A-Za-z]+_\d{4}$/', $monthKey)) {
            $sql .= " AND TO_CHAR(created_at, 'FMMonth_YYYY') = :month_key";
            $params[':month_key'] = $monthKey;
        }

        if ($userIdFilter !== null && $userIdFilter !== '') {
            $sql .= " AND user_id = :user_id";
            $params[':user_id'] = $userIdFilter;
        }

        $sql .= " ORDER BY created_at DESC LIMIT " . max(1, min(1000, $limit));

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rawLines = [];
        foreach ($rows as $row) {
            $rawLines[] = sprintf(
                "[%s] [%s] [%s] [USER:%s] [ROLE:%s] [IP:%s] %s",
                $row['timestamp_iso'],
                $row['action'],
                strtoupper((string) $row['status']),
                $row['user_id'],
                $row['user_role'],
                $row['ip_address'],
                $row['details']
            );
        }

        return [
            'entries' => $rows,
            'raw'     => implode("\n", $rawLines),
        ];
    }

    public static function exportUserActivityTxt(PDO $db, string $userId): string
    {
        $cleanUser = preg_replace('/[^a-zA-Z0-9_\-@.]/', '', $userId);
        $stmt = $db->prepare("
            SELECT action, status, user_id, user_role, ip_address, details,
                   TO_CHAR(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS timestamp_iso
            FROM audit_logs
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT 1000
        ");
        $stmt->execute([':user_id' => $cleanUser]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $output = "WORKLY SECURITY & ACTIVITY LOG (POSTGRESQL SOURCE)\n";
        $output .= "Account ID: {$cleanUser}\n";
        $output .= "Generated UTC: " . gmdate('Y-m-d\TH:i:s\Z') . "\n";
        $output .= str_repeat('=', 72) . "\n\n";

        if (empty($rows)) {
            $output .= "No activity recorded for {$cleanUser}.\n";
            return $output;
        }

        foreach ($rows as $row) {
            $output .= sprintf(
                "[%s] [%s] [%s] [USER:%s] [ROLE:%s] [IP:%s] %s\n",
                $row['timestamp_iso'],
                $row['action'],
                strtoupper((string) $row['status']),
                $row['user_id'],
                $row['user_role'],
                $row['ip_address'],
                $row['details']
            );
        }

        return $output;
    }
}
