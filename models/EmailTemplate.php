<?php
declare(strict_types=1);

/**
 * EmailTemplate Model
 * Data access layer for the email_templates table
 */
class EmailTemplate
{
    private PDO $db;

    // Template type constants
    public const TYPE_TENANT_WELCOME       = 'tenant_welcome';
    public const TYPE_ADMIN_INVITE         = 'admin_invite';
    public const TYPE_BARTENDER_INVITE     = 'bartender_invite';
    public const TYPE_GUEST_CONFIRMATION   = 'guest_confirmation';
    public const TYPE_GUEST_PASSWORD_RESET = 'guest_password_reset';
    public const TYPE_MARKETING            = 'marketing';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get template by type and tenant
     * Falls back to default (tenant_id = NULL) if tenant-specific not found.
     *
     * @param string      $type         Template type
     * @param int|null    $tenantId     Tenant ID (null for global defaults)
     * @param string      $languageCode Language code
     */
    public function getTemplate(string $type, ?int $tenantId = null, string $languageCode = 'nl'): ?array
    {
        try {
            // First try tenant-specific template
            if ($tenantId !== null) {
                $stmt = $this->db->prepare("
                    SELECT * FROM email_templates
                    WHERE type = :type
                      AND tenant_id = :tenant_id
                      AND language_code = :lang
                    LIMIT 1
                ");
                $stmt->execute([
                    ':type'      => $type,
                    ':tenant_id' => $tenantId,
                    ':lang'      => $languageCode,
                ]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            }

            // Fall back to global default template (tenant_id IS NULL)
            $stmt = $this->db->prepare("
                SELECT * FROM email_templates
                WHERE type = :type
                  AND tenant_id IS NULL
                  AND language_code = :lang
                LIMIT 1
            ");
            $stmt->execute([
                ':type' => $type,
                ':lang' => $languageCode,
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log("EmailTemplate::getTemplate - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all templates for a tenant (or all global defaults if tenantId is null)
     *
     * @param int|null    $tenantId     Tenant ID (null = global defaults)
     * @param string|null $languageCode Language code (null = all languages)
     * @return array
     */
    public function getTemplatesByTenant(?int $tenantId = null, ?string $languageCode = null): array
    {
        try {
            if ($tenantId !== null) {
                // Admin: fetch tenant-specific templates
                if ($languageCode !== null) {
                    $stmt = $this->db->prepare("
                        SELECT * FROM email_templates
                        WHERE tenant_id = :tenant_id
                          AND language_code = :lang
                        ORDER BY type, updated_at DESC
                    ");
                    $stmt->execute([':tenant_id' => $tenantId, ':lang' => $languageCode]);
                } else {
                    $stmt = $this->db->prepare("
                        SELECT * FROM email_templates
                        WHERE tenant_id = :tenant_id
                        ORDER BY type, language_code, updated_at DESC
                    ");
                    $stmt->execute([':tenant_id' => $tenantId]);
                }
            } else {
                // Superadmin: fetch global default templates (tenant_id IS NULL)
                if ($languageCode !== null) {
                    $stmt = $this->db->prepare("
                        SELECT * FROM email_templates
                        WHERE tenant_id IS NULL
                          AND language_code = :lang
                        ORDER BY type, updated_at DESC
                    ");
                    $stmt->execute([':lang' => $languageCode]);
                } else {
                    $stmt = $this->db->prepare("
                        SELECT * FROM email_templates
                        WHERE tenant_id IS NULL
                        ORDER BY type, language_code, updated_at DESC
                    ");
                    $stmt->execute();
                }
            }

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("EmailTemplate::getTemplatesByTenant - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create or update a template
     */
    public function saveTemplate(array $data): bool
    {
        try {
            $this->db->beginTransaction();

            if (empty($data['type']) || empty($data['subject']) || empty($data['content'])) {
                throw new \RuntimeException('Missing required fields: type, subject, content');
            }

            $validTypes = [
                self::TYPE_TENANT_WELCOME, self::TYPE_ADMIN_INVITE,
                self::TYPE_BARTENDER_INVITE,
                self::TYPE_GUEST_CONFIRMATION, self::TYPE_GUEST_PASSWORD_RESET,
                self::TYPE_MARKETING,
            ];
            if (!in_array($data['type'], $validTypes, true)) {
                throw new \RuntimeException('Invalid template type: ' . $data['type']);
            }

            $tenantId     = $data['tenant_id'] ?? null;
            $languageCode = $data['language_code'] ?? 'nl';

            // Global templates (tenant_id = NULL) should always have is_default = 1
            $isDefault = isset($data['is_default']) ? (int)$data['is_default'] : 1;
            if ($tenantId === null) {
                $isDefault = 1;
            }

            // If ID is provided, update that specific template
            if (!empty($data['id'])) {
                $stmt = $this->db->prepare("
                    UPDATE email_templates SET
                        subject = :subject,
                        content = :content,
                        text_content = :text_content,
                        is_default = :is_default
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':subject'     => $data['subject'],
                    ':content'     => $data['content'],
                    ':text_content' => $data['text_content'] ?? null,
                    ':is_default'  => $isDefault,
                    ':id'          => (int)$data['id'],
                ]);
                $this->db->commit();
                return true;
            }

            // Check if template exists for this type + tenant + language
            $stmt = $this->db->prepare("
                SELECT id FROM email_templates
                WHERE type = :type AND tenant_id <=> :tenant_id AND language_code = :lang
            ");
            $stmt->execute([':type' => $data['type'], ':tenant_id' => $tenantId, ':lang' => $languageCode]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $this->db->prepare("
                    UPDATE email_templates SET
                        subject = :subject,
                        content = :content,
                        text_content = :text_content,
                        is_default = :is_default
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':subject'     => $data['subject'],
                    ':content'     => $data['content'],
                    ':text_content' => $data['text_content'] ?? null,
                    ':is_default'  => $isDefault,
                    ':id'          => $existing['id'],
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO email_templates
                        (type, subject, content, text_content, tenant_id, language_code, is_default)
                    VALUES
                        (:type, :subject, :content, :text_content, :tenant_id, :lang, :is_default)
                ");
                $stmt->execute([
                    ':type'        => $data['type'],
                    ':subject'     => $data['subject'],
                    ':content'     => $data['content'],
                    ':text_content' => $data['text_content'] ?? null,
                    ':tenant_id'   => $tenantId,
                    ':lang'        => $languageCode,
                    ':is_default'  => $isDefault,
                ]);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("EmailTemplate::saveTemplate - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a template by ID
     */
    public function deleteTemplate(int $templateId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM email_templates WHERE id = :id");
            $stmt->execute([':id' => $templateId]);
            return true;
        } catch (\Throwable $e) {
            error_log("EmailTemplate::deleteTemplate - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Render template content by replacing {{placeholder}} values
     */
    public function renderTemplate(string $content, array $variables): string
    {
        $search  = [];
        $replace = [];
        foreach ($variables as $key => $value) {
            $search[]  = '{{' . $key . '}}';
            $replace[] = (string) $value;
        }
        return str_replace($search, $replace, $content);
    }

    /**
     * RBAC check: can a given role manage a given template type?
     */
    public function canManageTemplate(string $templateType, string $userRole): bool
    {
        if ($userRole === 'superadmin') {
            return in_array($templateType, [self::TYPE_TENANT_WELCOME], true);
        }

        if ($userRole === 'admin') {
            return in_array($templateType, [
                self::TYPE_ADMIN_INVITE,
                self::TYPE_BARTENDER_INVITE,
                self::TYPE_GUEST_CONFIRMATION,
                self::TYPE_GUEST_PASSWORD_RESET,
                self::TYPE_MARKETING,
            ], true);
        }

        return false;
    }
}
