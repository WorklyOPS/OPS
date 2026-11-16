<?php
/**
 * Workly Knowledge Base & SOP REST API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class KnowledgeApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        $parts = explode('/', trim($uri, '/'));
        // /api/v1/knowledge/{subOrId}
        $segment = $parts[3] ?? null;

        if ($method === 'GET' && !$segment) {
            $this->listKnowledge($user);
            return;
        }
        if ($method === 'POST' && $segment === 'categories') {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->createCategory($user);
            return;
        }
        if ($method === 'POST' && !$segment) {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->createDoc($user);
            return;
        }
        if (($method === 'PUT' || $method === 'PATCH') && $segment) {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->updateDoc($segment, $user);
            return;
        }
        if ($method === 'DELETE' && $segment) {
            AuthMiddleware::requireRoles(['admin', 'team_leader'], $user);
            $this->deleteDoc($segment, $user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Knowledge API route not found']);
    }

    private function invalidate(): void
    {
        CacheService::invalidatePrefix($this->db, 'knowledge:');
    }

    private function listKnowledge(array $user): void
    {
        $role = $user['role'];
        $uid = $user['id'];
        $hit = false;

        $payload = CacheService::remember($this->db, "knowledge:{$role}:{$uid}", 60, function () use ($role, $uid): array {
            $cats = $this->db->query('SELECT id, name FROM knowledge_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

            if ($role === 'va') {
                $stmt = $this->db->prepare("
                    SELECT k.*, c.agency_name AS client_name
                    FROM knowledge_docs k
                    LEFT JOIN clients c ON k.client_id = c.id
                    WHERE k.client_id IS NULL
                       OR k.client_id IN (SELECT id FROM clients WHERE focus_va_id = :va_id)
                    ORDER BY k.last_updated DESC, k.created_at DESC
                ");
                $stmt->execute([':va_id' => $uid]);
            } else {
                $stmt = $this->db->query("
                    SELECT k.*, c.agency_name AS client_name
                    FROM knowledge_docs k
                    LEFT JOIN clients c ON k.client_id = c.id
                    ORDER BY k.last_updated DESC, k.created_at DESC
                ");
            }

            return [
                'categories' => $cats,
                'docs'       => $stmt->fetchAll(PDO::FETCH_ASSOC),
            ];
        }, $hit);

        CacheService::sendJsonWithEtag($payload, $hit, 30);
    }

    private function createCategory(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $name = SecurityHelper::sanitizePlainText($input['name'] ?? '', 120);
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Category name is required']);
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO knowledge_categories (name) VALUES (:name) ON CONFLICT (name) DO NOTHING');
        $stmt->execute([':name' => $name]);

        $this->invalidate();
        AuditLogger::log('KNOWLEDGE_CATEGORY_CREATED', 'success', "Created SOP category {$name}", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'name' => $name]);
    }

    private function createDoc(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 255);
        $category = SecurityHelper::sanitizePlainText($input['category'] ?? 'General SOP', 191);
        $clientId = SecurityHelper::sanitizePlainText($input['client_id'] ?? '', 64) ?: null;
        $content = SecurityHelper::sanitizePlainText($input['content'] ?? '', 15000);

        if ($title === '' || $content === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Title and SOP content are required']);
            return;
        }

        $catIns = $this->db->prepare('INSERT INTO knowledge_categories (name) VALUES (:name) ON CONFLICT (name) DO NOTHING');
        $catIns->execute([':name' => $category]);

        $id = 'kdoc-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO knowledge_docs (id, title, category, client_id, content, author, last_updated)
            VALUES (:id, :title, :cat, :cid, :content, :author, CURRENT_DATE)
        ");
        $stmt->execute([
            ':id'      => $id,
            ':title'   => $title,
            ':cat'     => $category,
            ':cid'     => $clientId,
            ':content' => $content,
            ':author'  => $user['name'],
        ]);

        $this->invalidate();
        AuditLogger::log('KNOWLEDGE_DOC_CREATED', 'success', "Published SOP {$title}", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function updateDoc(string $docId, array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 255);
        $category = SecurityHelper::sanitizePlainText($input['category'] ?? '', 191);
        $content = SecurityHelper::sanitizePlainText($input['content'] ?? '', 15000);
        $clientId = array_key_exists('client_id', $input)
            ? (SecurityHelper::sanitizePlainText((string) $input['client_id'], 64) ?: null)
            : null;

        if ($title === '' || $content === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Title and content cannot be empty']);
            return;
        }

        if ($category !== '') {
            $this->db->prepare('INSERT INTO knowledge_categories (name) VALUES (:name) ON CONFLICT (name) DO NOTHING')
                ->execute([':name' => $category]);
        }

        $stmt = $this->db->prepare("
            UPDATE knowledge_docs
            SET title = :title,
                category = COALESCE(NULLIF(:cat, ''), category),
                client_id = :cid,
                content = :content,
                last_updated = CURRENT_DATE
            WHERE id = :id
        ");
        $stmt->execute([
            ':title'   => $title,
            ':cat'     => $category,
            ':cid'     => $clientId,
            ':content' => $content,
            ':id'      => $docId,
        ]);

        $this->invalidate();
        AuditLogger::log('KNOWLEDGE_DOC_UPDATED', 'success', "Updated SOP {$docId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $docId]);
    }

    private function deleteDoc(string $docId, array $user): void
    {
        $stmt = $this->db->prepare('DELETE FROM knowledge_docs WHERE id = :id');
        $stmt->execute([':id' => $docId]);
        $this->invalidate();
        AuditLogger::log('KNOWLEDGE_DOC_DELETED', 'warning', "Removed SOP {$docId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $docId]);
    }
}
