<?php
declare(strict_types=1);

/**
 * Notification Service
 * Creates inbox notifications AND sends email to the guest.
 *
 * This is the single entry point for all notification creation.
 * Called from WalletService (deposit) and PaymentService (payment).
 */

require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/Email/EmailService.php';

class NotificationService
{
    private PDO $db;
    private Notification $notifModel;
    private User $userModel;
    private Tenant $tenantModel;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->notifModel = new Notification($db);
        $this->userModel = new User($db);
        $this->tenantModel = new Tenant($db);
    }

    /**
     * Create a notification from a transaction and send email to the guest.
     *
     * @param int   $userId        Guest user ID
     * @param int   $tenantId      Tenant ID
     * @param int   $transactionId Transaction ID
     * @param string $type         Transaction type: deposit|payment|bonus|correction
     * @param int   $amountCents   Final total in cents
     * @param array $extra         Additional context: discount_alc_cents, discount_food_cents, points_earned, description
     * @return int Notification ID
     */
    public function notifyTransaction(
        int $userId,
        int $tenantId,
        int $transactionId,
        string $type,
        int $amountCents,
        array $extra = []
    ): int {
        // Build notification fields based on type
        $notifData = $this->buildNotifFields($type, $amountCents, $extra);
        $notifData['tenant_id'] = $tenantId;
        $notifData['user_id'] = $userId;
        $notifData['transaction_id'] = $transactionId;
        $notifData['type'] = $type;

        // Create notification in DB
        $notificationId = $this->notifModel->create($notifData);

        // Send email (non-blocking: failures are logged but don't break the flow)
        $this->sendEmailNotification($userId, $tenantId, $notifData['title'], $notifData['body']);

        return $notificationId;
    }

    /**
     * Map transaction type to notification display fields
     */
    private function buildNotifFields(string $type, int $amountCents, array $extra): array
    {
        $discountAlc = (int) ($extra['discount_alc_cents'] ?? 0);
        $discountFood = (int) ($extra['discount_food_cents'] ?? 0);
        $discountTotal = $discountAlc + $discountFood;
        $pointsEarned = (int) ($extra['points_earned'] ?? 0);
        $description = $extra['description'] ?? '';

        switch ($type) {
            case 'deposit':
                return [
                    'icon'  => '💰',
                    'title' => 'Opwaardering ontvangen',
                    'body'  => '+ € ' . centsToEuro($amountCents) . ($description !== '' ? ' — ' . $description : ' — Opwaardering wallet'),
                    'color' => 'var(--color-success)',
                    'points_earned' => $pointsEarned,
                ];

            case 'payment':
                $body = '- € ' . centsToEuro($amountCents);
                if ($discountTotal > 0) {
                    $body .= ' (korting: € ' . centsToEuro($discountTotal) . ')';
                }
                return [
                    'icon'  => '🍺',
                    'title' => 'Betaling verwerkt',
                    'body'  => $body,
                    'color' => 'var(--text-secondary)',
                    'points_earned' => $pointsEarned,
                ];

            case 'bonus':
                return [
                    'icon'  => '🎁',
                    'title' => 'Bonus ontvangen',
                    'body'  => '+ € ' . centsToEuro($amountCents) . ($description !== '' ? ' — ' . $description : ''),
                    'color' => 'var(--color-success)',
                    'points_earned' => $pointsEarned,
                ];

            case 'correction':
                return [
                    'icon'  => '🔧',
                    'title' => 'Correctie',
                    'body'  => '€ ' . centsToEuro($amountCents) . ($description !== '' ? ' — ' . $description : ''),
                    'color' => 'var(--color-warning)',
                    'points_earned' => $pointsEarned,
                ];

            default:
                return [
                    'icon'  => '📋',
                    'title' => ucfirst($type),
                    'body'  => '€ ' . centsToEuro($amountCents),
                    'color' => 'var(--text-secondary)',
                    'points_earned' => $pointsEarned,
                ];
        }
    }

    /**
     * Send an email notification to the guest
     * Failures are logged but do NOT throw — email is non-critical
     */
    private function sendEmailNotification(int $userId, int $tenantId, string $title, string $body): void
    {
        try {
            // Get user info
            $user = $this->userModel->findById($userId);
            if ($user === null || empty($user['email'])) {
                return;
            }

            // Get tenant name
            $tenant = $this->tenantModel->findById($tenantId);
            $tenantName = $tenant !== null ? $tenant['name'] : 'REGULR.vip';

            $emailService = new EmailService($this->db);

            // Build HTML email content
            $userName = $user['first_name'] . ' ' . $user['last_name'];
            $htmlContent = $this->buildEmailHtml($userName, $tenantName, $title, $body);

            $emailService->sendEmail(
                $user['email'],
                $title . ' — ' . $tenantName,
                $htmlContent,
                strip_tags($body),
                'guest_confirmation',
                $tenantId,
                $userId
            );
        } catch (\Throwable $e) {
            // Non-critical: log and continue
            error_log('NotificationService::sendEmailNotification failed — ' . $e->getMessage());
        }
    }

    /**
     * Build a simple HTML email for transaction notifications
     */
    private function buildEmailHtml(string $userName, string $tenantName, string $title, string $body): string
    {
        $year = date('Y');
        return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#1a1a2e;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#ffffff;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:0 auto;padding:24px;">
        <tr>
            <td style="text-align:center;padding-bottom:24px;">
                <h2 style="margin:0;color:#FFC107;font-size:20px;">{$tenantName}</h2>
            </td>
        </tr>
        <tr>
            <td style="background:rgba(255,255,255,0.05);border-radius:16px;padding:32px 24px;">
                <p style="margin:0 0 8px;font-size:16px;">Hallo {$userName},</p>
                <h3 style="margin:0 0 12px;font-size:18px;color:#FFC107;">{$title}</h3>
                <p style="margin:0 0 16px;font-size:15px;color:#cccccc;">{$body}</p>
                <p style="margin:0;font-size:13px;color:#888888;">Bekijk meer in je REGULR.vip inbox</p>
            </td>
        </tr>
        <tr>
            <td style="text-align:center;padding-top:24px;font-size:12px;color:#666666;">
                &copy; {$year} REGULR.vip — {$tenantName}
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}
