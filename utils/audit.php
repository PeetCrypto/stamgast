<?php
declare(strict_types=1);

/**
 * Audit Trail Logger
 * Logs every significant action with who/what/where/when metadata
 */

class Audit
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Log an audit entry
     * @param int|null $tenantId NULL for platform-level superadmin actions
     */
    public function log(
        ?int $tenantId,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO `audit_log` (`tenant_id`, `user_id`, `action`, `entity_type`, `entity_id`, `ip_address`, `user_agent`, `metadata`)
             VALUES (:tenant_id, :user_id, :action, :entity_type, :entity_id, :ip_address, :user_agent, :metadata)'
        );

        $stmt->execute([
            ':tenant_id'    => $tenantId,
            ':user_id'      => $userId,
            ':action'       => $action,
            ':entity_type'  => $entityType,
            ':entity_id'    => $entityId,
            ':ip_address'   => getClientIP(),
            ':user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ':metadata'     => $metadata !== null ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    /**
     * Get audit logs for a tenant
     */
    public function getLogs(int $tenantId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `audit_log` WHERE `tenant_id` = :tenant_id ORDER BY `created_at` DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get audit logs for a specific entity
     */
    public function getEntityLogs(int $tenantId, string $entityType, int $entityId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `audit_log` WHERE `tenant_id` = :tenant_id AND `entity_type` = :entity_type AND `entity_id` = :entity_id ORDER BY `created_at` DESC'
        );
        $stmt->execute([
            ':tenant_id'   => $tenantId,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
        ]);
        return $stmt->fetchAll();
    }
}
