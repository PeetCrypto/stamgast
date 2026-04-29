<?php
declare(strict_types=1);

/**
 * EmailConfig Model
 * Data access layer for the email_config table
 */
class EmailConfig
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get active email configuration
     */
    public function getActiveConfig(): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM email_config 
                WHERE is_active = 1 
                ORDER BY updated_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            error_log("EmailConfig::getActiveConfig - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all email configurations
     */
    public function getAllConfigs(): array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM email_config ORDER BY updated_at DESC");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("EmailConfig::getAllConfigs - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Save or update email configuration
     */
    public function saveConfig(array $data): bool
    {
        try {
            $this->db->beginTransaction();

            $encryption = $data['smtp_encryption'] ?? 'tls';

            if (!empty($data['id'])) {
                // Update existing configuration
                if (isset($data['smtp_pass']) && $data['smtp_pass'] !== '') {
                    // Password provided — update everything including password
                    $stmt = $this->db->prepare("
                        UPDATE email_config SET
                            provider = :provider,
                            smtp_host = :smtp_host,
                            smtp_port = :smtp_port,
                            smtp_encryption = :smtp_encryption,
                            smtp_user = :smtp_user,
                            smtp_pass = :smtp_pass,
                            from_email = :from_email,
                            from_name = :from_name,
                            is_active = :is_active
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':provider' => $data['provider'],
                        ':smtp_host' => $data['smtp_host'],
                        ':smtp_port' => $data['smtp_port'],
                        ':smtp_encryption' => $encryption,
                        ':smtp_user' => $data['smtp_user'],
                        ':smtp_pass' => $data['smtp_pass'],
                        ':from_email' => $data['from_email'],
                        ':from_name' => $data['from_name'],
                        ':is_active' => $data['is_active'] ?? 0,
                        ':id' => $data['id']
                    ]);
                } else {
                    // No password — keep existing password, update the rest
                    $stmt = $this->db->prepare("
                        UPDATE email_config SET
                            provider = :provider,
                            smtp_host = :smtp_host,
                            smtp_port = :smtp_port,
                            smtp_encryption = :smtp_encryption,
                            smtp_user = :smtp_user,
                            from_email = :from_email,
                            from_name = :from_name,
                            is_active = :is_active
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':provider' => $data['provider'],
                        ':smtp_host' => $data['smtp_host'],
                        ':smtp_port' => $data['smtp_port'],
                        ':smtp_encryption' => $encryption,
                        ':smtp_user' => $data['smtp_user'],
                        ':from_email' => $data['from_email'],
                        ':from_name' => $data['from_name'],
                        ':is_active' => $data['is_active'] ?? 0,
                        ':id' => $data['id']
                    ]);
                }
            } else {
                // Create new configuration — generate UUID for id column (char(36))
                $data['id'] = sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );
                if (empty($data['smtp_pass'])) {
                    $data['smtp_pass'] = '';
                }
                $stmt = $this->db->prepare("
                    INSERT INTO email_config 
                        (id, provider, smtp_host, smtp_port, smtp_encryption, smtp_user, smtp_pass, from_email, from_name, is_active)
                    VALUES 
                        (:id, :provider, :smtp_host, :smtp_port, :smtp_encryption, :smtp_user, :smtp_pass, :from_email, :from_name, :is_active)
                ");
                $stmt->execute([
                    ':id' => $data['id'],
                    ':provider' => $data['provider'],
                    ':smtp_host' => $data['smtp_host'],
                    ':smtp_port' => $data['smtp_port'],
                    ':smtp_encryption' => $encryption,
                    ':smtp_user' => $data['smtp_user'],
                    ':smtp_pass' => $data['smtp_pass'],
                    ':from_email' => $data['from_email'],
                    ':from_name' => $data['from_name'],
                    ':is_active' => $data['is_active'] ?? 0
                ]);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("EmailConfig::saveConfig - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a test email using the active configuration
     */
    public function testConfig(string $to): array
    {
        try {
            $config = $this->getActiveConfig();
            if (!$config) {
                return ['success' => false, 'message' => 'Geen actieve email configuratie gevonden.'];
            }

            require_once __DIR__ . '/../services/Email/EmailService.php';
            $emailService = new EmailService($this->db);

            $subject = 'REGULR.vip Test Email';
            $htmlContent = '<h2>Test Email</h2><p>Dit is een test email vanuit REGULR.vip.</p>'
                . '<p>Provider: ' . htmlspecialchars($config['provider']) . '</p>'
                . '<p>Verzonden om: ' . date('Y-m-d H:i:s') . '</p>';

            $sent = $emailService->sendEmail($to, $subject, $htmlContent, null, null, null, null);

            if ($sent) {
                return ['success' => true, 'message' => 'Test email succesvol verzonden naar ' . $to];
            }
            return ['success' => false, 'message' => 'Test email kon niet worden verzonden. Controleer de SMTP instellingen.'];
        } catch (\Throwable $e) {
            error_log("EmailConfig::testConfig - " . $e->getMessage());
            return ['success' => false, 'message' => 'Fout: ' . $e->getMessage()];
        }
    }

    /**
     * Delete email configuration
     */
    public function deleteConfig(string $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM email_config WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return true;
        } catch (\Throwable $e) {
            error_log("EmailConfig::deleteConfig - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set active configuration
     */
    public function setActiveConfig(string $id): bool
    {
        try {
            $this->db->beginTransaction();
            
            // Set all configs to inactive
            $stmt = $this->db->prepare("UPDATE email_config SET is_active = 0");
            $stmt->execute();
            
            // Set selected config to active
            $stmt = $this->db->prepare("UPDATE email_config SET is_active = 1 WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("EmailConfig::setActiveConfig - " . $e->getMessage());
            return false;
        }
    }
}