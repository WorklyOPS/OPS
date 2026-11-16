<?php
/**
 * Workly Two-Tier Cache Service (APCu + PostgreSQL cache_store)
 */

declare(strict_types=1);

final class CacheService
{
    private const NAMESPACE_PREFIX = 'workly_pg:';

    public static function get(PDO $db, string $key): mixed
    {
        $namespacedKey = self::NAMESPACE_PREFIX . $key;

        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $hit = false;
            $val = apcu_fetch($namespacedKey, $hit);
            if ($hit) {
                return $val;
            }
        }

        try {
            $stmt = $db->prepare("
                SELECT cache_value
                FROM cache_store
                WHERE cache_key = :k AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([':k' => $namespacedKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && isset($row['cache_value'])) {
                $decoded = json_decode((string) $row['cache_value'], true);
                if (function_exists('apcu_enabled') && apcu_enabled()) {
                    apcu_store($namespacedKey, $decoded, 60);
                }
                return $decoded;
            }
        } catch (PDOException) {
            return null;
        }

        return null;
    }

    public static function set(PDO $db, string $key, mixed $value, int $ttlSeconds = 60): void
    {
        $namespacedKey = self::NAMESPACE_PREFIX . $key;
        $ttl = max(5, $ttlSeconds);

        if (function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_store($namespacedKey, $value, $ttl);
        }

        try {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return;
            }

            $stmt = $db->prepare("
                INSERT INTO cache_store (cache_key, cache_value, expires_at, created_at)
                VALUES (:k, :v, NOW() + make_interval(secs => :ttl), NOW())
                ON CONFLICT (cache_key) DO UPDATE
                SET cache_value = EXCLUDED.cache_value,
                    expires_at = EXCLUDED.expires_at
            ");
            $stmt->bindValue(':k', $namespacedKey, PDO::PARAM_STR);
            $stmt->bindValue(':v', $encoded, PDO::PARAM_STR);
            $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException) {
            // Non-fatal
        }
    }

    public static function remember(PDO $db, string $key, int $ttlSeconds, callable $resolver, ?bool &$cacheHit = null): mixed
    {
        $cached = self::get($db, $key);
        if ($cached !== null) {
            $cacheHit = true;
            return $cached;
        }

        $cacheHit = false;
        $fresh = $resolver();
        self::set($db, $key, $fresh, $ttlSeconds);
        return $fresh;
    }

    public static function invalidatePrefix(PDO $db, string $prefix): void
    {
        $namespacedPrefix = self::NAMESPACE_PREFIX . $prefix;

        if (function_exists('apcu_enabled') && apcu_enabled() && class_exists('APCUIterator')) {
            $pattern = '/^' . preg_quote($namespacedPrefix, '/') . '/';
            foreach (new APCUIterator($pattern) as $entry) {
                apcu_delete($entry['key']);
            }
        }

        try {
            $stmt = $db->prepare("DELETE FROM cache_store WHERE cache_key LIKE :prefix");
            $stmt->execute([':prefix' => $namespacedPrefix . '%']);
        } catch (PDOException) {
            // Non-fatal
        }
    }

    public static function purgeExpired(PDO $db): int
    {
        try {
            $stmt = $db->prepare("DELETE FROM cache_store WHERE expires_at <= NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException) {
            return 0;
        }
    }

    public static function sendJsonWithEtag(array $payload, bool $cacheHit, int $maxAgeSeconds = 30): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . hash('sha256', (string) $json) . '"';

        header('X-Workly-Cache: ' . ($cacheHit ? 'HIT' : 'MISS'));
        header('Cache-Control: private, max-age=' . $maxAgeSeconds . ', must-revalidate');
        header('ETag: ' . $etag);

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
            http_response_code(304);
            exit;
        }

        echo $json;
    }
}
