<?php
declare(strict_types=1);

/**
 * Email Service
 * Handles email sending through various providers
 */

interface EmailProviderInterface
{
    public function send(array $emailData): bool;
    public function getName(): string;
    public function getLastError(): string;
}

class EmailService
{
    private PDO $db;
    private ?EmailConfig $emailConfigModel;
    private ?EmailTemplate $emailTemplateModel;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->emailConfigModel = new EmailConfig($db);
        $this->emailTemplateModel = new EmailTemplate($db);
    }

    /**
     * Send an email using the active provider
     */
    public function sendEmail(
        string $to,
        string $subject,
        string $htmlContent,
        ?string $textContent = null,
        ?string $templateType = null,
        ?int $tenantId = null,
        ?int $userId = null
    ): bool {
        $emailData = [
            'to' => $to,
            'subject' => $subject,
            'html_content' => $htmlContent,
            'text_content' => $textContent,
            'template_type' => $templateType,
            'tenant_id' => $tenantId,
            'user_id' => $userId
        ];

        try {
            // Get active email configuration
            $config = $this->emailConfigModel->getActiveConfig();
            if (!$config) {
                throw new \RuntimeException('No active email configuration found');
            }

            // Initialize the appropriate provider
            $provider = $this->initializeProvider($config);
            
            // Send the email
            $result = $provider->send($emailData);
            
            // Get error message if send failed
            $errorMessage = '';
            if (!$result && method_exists($provider, 'getLastError')) {
                $errorMessage = $provider->getLastError();
            }

            // Log the email (success or failure from provider)
            $this->logEmail($emailData, $result, $config['provider'], $errorMessage ?: null);

            return $result;
        } catch (\Exception $e) {
            error_log("EmailService::sendEmail - " . $e->getMessage());
            // Always log the failure
            $this->logEmail($emailData, false, 'unknown', $e->getMessage());
            return false;
        }
    }

    /**
     * Send a templated email
     */
    public function sendTemplatedEmail(
        string $to,
        string $templateType,
        array $variables = [],
        ?int $tenantId = null,
        ?string $languageCode = 'nl'
    ): bool {
        try {
            // Get the template
            $template = $this->emailTemplateModel->getTemplate($templateType, $tenantId, $languageCode);
            if (!$template) {
                throw new \RuntimeException("Template not found: $templateType");
            }

            // Render the template with variables
            $htmlContent = $this->emailTemplateModel->renderTemplate($template['content'], $variables);
            $textContent = $template['text_content'] ? $this->emailTemplateModel->renderTemplate($template['text_content'], $variables) : null;

            // Send the email
            return $this->sendEmail(
                $to,
                $template['subject'],
                $htmlContent,
                $textContent,
                $templateType,
                $tenantId
            );
        } catch (\Exception $e) {
            error_log("EmailService::sendTemplatedEmail - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Initialize the appropriate email provider
     */
    private function initializeProvider(array $config): EmailProviderInterface
    {
        switch ($config['provider']) {
            case 'brevo':
                return new BrevoProvider($config);
            case 'sender_net':
                return new SenderNetProvider($config);
            case 'aws_ses':
                return new AwsSesProvider($config);
            default:
                throw new \RuntimeException('Unsupported email provider: ' . $config['provider']);
        }
    }

    /**
     * Log email in the email_log table
     */
    private function logEmail(array $emailData, bool $success, string $provider, ?string $errorMessage = null): void
    {
        try {
            // Generate UUID for char(36) id column
            $id = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            $stmt = $this->db->prepare("
                INSERT INTO email_log 
                    (id, tenant_id, user_id, recipient_email, subject, template_type, provider, status, error_message, sent_at)
                VALUES 
                    (:id, :tenant_id, :user_id, :recipient_email, :subject, :template_type, :provider, :status, :error_message, NOW())
            ");
            $stmt->execute([
                ':id' => $id,
                ':tenant_id' => $emailData['tenant_id'] ?? null,
                ':user_id' => $emailData['user_id'] ?? null,
                ':recipient_email' => $emailData['to'],
                ':subject' => $emailData['subject'],
                ':template_type' => $emailData['template_type'] ?? null,
                ':provider' => $provider,
                ':status' => $success ? 'sent' : 'failed',
                ':error_message' => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            error_log("EmailService::logEmail - " . $e->getMessage());
        }
    }
}

class BrevoProvider implements EmailProviderInterface
{
    private array $config;
    private ?SmtpTransport $transport = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function getName(): string
    {
        return 'Brevo';
    }
    
    public function send(array $emailData): bool
    {
        require_once __DIR__ . '/SmtpTransport.php';
        $this->transport = new SmtpTransport($this->config);
        return $this->transport->send($emailData);
    }

    public function getLastError(): string
    {
        return $this->transport ? $this->transport->getLastError() : '';
    }
}

class SenderNetProvider implements EmailProviderInterface
{
    private array $config;
    private ?SmtpTransport $transport = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function getName(): string
    {
        return 'Sender.net';
    }
    
    public function send(array $emailData): bool
    {
        require_once __DIR__ . '/SmtpTransport.php';
        $this->transport = new SmtpTransport($this->config);
        return $this->transport->send($emailData);
    }

    public function getLastError(): string
    {
        return $this->transport ? $this->transport->getLastError() : '';
    }
}

class AwsSesProvider implements EmailProviderInterface
{
    private array $config;
    private ?SmtpTransport $transport = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function getName(): string
    {
        return 'AWS SES';
    }
    
    public function send(array $emailData): bool
    {
        require_once __DIR__ . '/SmtpTransport.php';
        $this->transport = new SmtpTransport($this->config);
        return $this->transport->send($emailData);
    }

    public function getLastError(): string
    {
        return $this->transport ? $this->transport->getLastError() : '';
    }
}