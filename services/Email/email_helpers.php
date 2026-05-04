<?php
/**
 * Email Helper
 * 
 * Helper functions for sending emails throughout the application
 * 
 * @package REGULR.vip
 * @subpackage Services
 */

require_once __DIR__ . '/../../models/EmailConfig.php';
require_once __DIR__ . '/../../models/EmailTemplate.php';
require_once __DIR__ . '/EmailService.php';

/**
 * Send email using the email service
 * 
 * @param PDO $db Database connection
 * @param string $to Recipient email address
 * @param string $templateType Type of template to use
 * @param array $variables Variables to replace in template
 * @param string|null $tenantId Tenant ID (null for global templates)
 * @return array Result with success status and message
 */
function sendEmailTemplate($db, $to, $templateType, $variables, $tenantId = null) {
    try {
        // Initialize email service
        $emailService = new EmailService($db);
        
        // Send the email
        $result = $emailService->sendTemplatedEmail($to, $templateType, $variables, $tenantId);
        
        return $result;
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send tenant welcome email
 * 
 * @param PDO $db Database connection
 * @param array $tenant Tenant data
 * @param string $passwordResetToken Password reset token
 * @return array Result with success status and message
 */
function sendTenantWelcomeEmail($db, $tenant, $passwordResetToken) {
    try {
        // Get superadmin email template
        $emailTemplate = new EmailTemplate($db);
        $template = $emailTemplate->getTemplate('tenant_welcome', null);
        
        if (!$template) {
            return ['success' => false, 'message' => 'Tenant welcome template not found'];
        }
        
        // Prepare template variables
        $variables = [
            'tenant_name' => $tenant['name'],
            'password_reset_link' => FULL_BASE_URL . '/set-password?token=' . $passwordResetToken
        ];
        
        // Send the email
        return sendEmailTemplate($db, $tenant['contact_email'], 'tenant_welcome', $variables);
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send admin invite email
 * 
 * @param PDO $db Database connection
 * @param array $tenant Tenant data
 * @param array $user User data
 * @param string $inviteToken Invite token
 * @param int $tenantId Tenant ID
 * @return array Result with success status and message
 */
function sendAdminInviteEmail($db, $tenant, $user, $inviteToken, $tenantId) {
    try {
        // Prepare template variables
        $variables = [
            'user_name' => $user['first_name'] . ' ' . $user['last_name'],
            'tenant_name' => $tenant['name'],
            'invitation_link' => FULL_BASE_URL . '/accept-invite?token=' . $inviteToken
        ];
        
        // Send the email
        return sendEmailTemplate($db, $user['email'], 'admin_invite', $variables, $tenantId);
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send bartender invite email
 * 
 * @param PDO $db Database connection
 * @param array $tenant Tenant data
 * @param array $user User data with email and password
 * @param string $inviteToken Invite token
 * @param int $tenantId Tenant ID
 * @return array Result with success status and message
 */
function sendBartenderInviteEmail($db, $tenant, $user, $inviteToken, $tenantId) {
    try {
        // Prepare template variables
        $variables = [
            'user_name' => $user['first_name'] . ' ' . $user['last_name'],
            'tenant_name' => $tenant['name'],
            'invitation_link' => FULL_BASE_URL . '/accept-invite?token=' . $inviteToken,
            'user_email' => $user['email'],
            'user_password' => $user['password'] ?? '',
        ];
        
        // Send the email
        return sendEmailTemplate($db, $user['email'], 'bartender_invite', $variables, $tenantId);
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send guest confirmation email
 * 
 * @param PDO $db Database connection
 * @param string $email Recipient email
 * @param string $tenantName Tenant name
 * @param string $verificationCode Verification code
 * @param int $tenantId Tenant ID
 * @return array Result with success status and message
 */
function sendGuestConfirmationEmail($db, $email, $tenantName, $verificationCode, $tenantId) {
    try {
        // Prepare template variables
        $variables = [
            'guest_name' => '',
            'tenant_name' => $tenantName,
            'verification_code' => $verificationCode,
            'verification_link' => FULL_BASE_URL . '/verify?code=' . $verificationCode
        ];
        
        // Send the email
        return sendEmailTemplate($db, $email, 'guest_confirmation', $variables, $tenantId);
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send guest password reset email
 * 
 * @param PDO $db Database connection
 * @param string $email Recipient email
 * @param string $tenantName Tenant name
 * @param string $passwordResetLink Password reset link
 * @param int $tenantId Tenant ID
 * @return array Result with success status and message
 */
function sendGuestPasswordResetEmail($db, $email, $tenantName, $passwordResetLink, $tenantId) {
    try {
        // Prepare template variables
        $variables = [
            'guest_name' => '',
            'tenant_name' => $tenantName,
            'password_reset_link' => $passwordResetLink
        ];
        
        // Send the email
        return sendEmailTemplate($db, $email, 'guest_password_reset', $variables, $tenantId);
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send marketing email
 * 
 * @param PDO $db Database connection
 * @param string $email Recipient email
 * @param string $tenantName Tenant name
 * @param string $campaignName Campaign name
 * @param string $campaignMessage Campaign message
 * @param string $actionUrl Action URL
 * @param string $actionText Action text
 * @param int $tenantId Tenant ID
 * @return array Result with success status and message
 */
function sendMarketingEmail($db, $email, $tenantName, $campaignName, $campaignMessage, $actionUrl, $actionText, $tenantId) {
    try {
        // Prepare template variables
        $variables = [
            'tenant_name' => $tenantName,
            'campaign_name' => $campaignName,
            'campaign_message' => $campaignMessage,
            'action_url' => $actionUrl,
            'action_text' => $actionText
        ];
        
        // Send the email
        return sendEmailTemplate($db, $email, 'marketing', $variables, $tenantId);
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}