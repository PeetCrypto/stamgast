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
        try {
            // Get active email configuration
            $config = $this->emailConfigModel->getActiveConfig();
            if (!$config) {
                throw new \RuntimeException('No active email configuration found');
            }

            // Initialize the appropriate provider
            $provider = $this->initializeProvider($config);
            
            // Prepare email data
            $emailData = [
                'to' => $to,
                'subject' => $subject,
                'html_content' => $htmlContent,
                'text_content' => $textContent,
                'template_type' => $templateType,
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ];

            // Send the email
            $result = $provider->send($emailData);
            
            // Log the email
            $this->logEmail($emailData, $result, $config['provider']);
            
            return $result;
        } catch (\Exception $e) {
            error_log("EmailService::sendEmail - " . $e->getMessage());
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
    private function logEmail(array $emailData, bool $success, string $provider): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_log 
                    (tenant_id, user_id, recipient_email, subject, template_type, provider, status, sent_at)
                VALUES 
                    (:tenant_id, :user_id, :recipient_email, :subject, :template_type, :provider, :status, NOW())
            ");
            $stmt->execute([
                ':tenant_id' => $emailData['tenant_id'] ?? null,
                ':user_id' => $emailData['user_id'] ?? null,
                ':recipient_email' => $emailData['to'],
                ':subject' => $emailData['subject'],
                ':template_type' => $emailData['template_type'] ?? null,
                ':provider' => $provider,
                ':status' => $success ? 'sent' : 'failed'
            ]);
        } catch (\Throwable $e) {
            error_log("EmailService::logEmail - " . $e->getMessage());
        }
    }
}

class BrevoProvider implements EmailProviderInterface
{
    private array $config;
    
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
        // Implementation for Brevo API
        // This is a placeholder - actual implementation would use Brevo's API
        try {
            // Simulate API call to Brevo
            // $result = brevo_api_send($emailData);
            // return $result;
            return true; // Placeholder
        } catch (\Exception $e) {
            error_log("BrevoProvider::send - " . $e->getMessage());
            return false;
        }
    }
}

class SenderNetProvider implements EmailProviderInterface
{
    private array $config;
    
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
        // Implementation for Sender.net API
        // This is a placeholder - actual implementation would use Sender.net's API
        try {
            // Simulate API call to Sender.net
            // $result = sendernet_api_send($emailData);
            // return $result;
            return true; // Placeholder
        } catch (\Exception $e) {
            error_log("SenderNetProvider::send - " . $e->getMessage());
            return false;
        }
    }
}

class AwsSesProvider implements EmailProviderInterface
{
    private array $config;
    
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
        // Implementation for AWS SES
        // This is a placeholder - actual implementation would use AWS SES
        try {
            // Simulate AWS SES call
            // $result = aws_ses_send($emailData);
            // return $result;
            return true; // Placeholder
        } catch (\Exception $e) {
            error_log("AwsSesProvider::send - " . $e->getMessage());
            return false;
        }
    }
}