<?php
declare(strict_types=1);
/**
 * Guest Inbox
 * STAMGAST Loyalty Platform — Notification feed
 */

$db = Database::getInstance()->getConnection();
$userId = currentUserId();
$tenantId = currentTenantId();
$firstName = $_SESSION['first_name'] ?? 'Gast';

// Fetch recent transactions as "notifications" (MVP: transactions are the inbox items)
require_once __DIR__ . '/../../models/Transaction.php';
$txModel = new Transaction($db);

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Get transactions for this user (getByUser returns ['transactions', 'total', 'page', 'limit'])
$result = $txModel->getByUser($userId, $tenantId, $page, $limit);
$transactions = $result['transactions'];
$totalTx = (int) $result['total'];
$totalPages = (int) ceil($totalTx / $limit);

// Build notification items from transactions
$notifications = [];
foreach ($transactions as $tx) {
    $type = $tx['type'];
    $cents = (int) $tx['final_total_cents'];
    $date = $tx['created_at'];
    $desc = $tx['description'] ?? '';

    switch ($type) {
        case 'deposit':
            $icon = '💰';
            $title = 'Opwaardering ontvangen';
            $body = '+ € ' . number_format($cents / 100, 2, ',', '.');
            $color = 'var(--color-success)';
            break;
        case 'payment':
            $icon = '🍺';
            $title = 'Betaling verwerkt';
            $body = '- € ' . number_format($cents / 100, 2, ',', '.');
            $discount = (int) ($tx['discount_alc_cents'] ?? 0) + (int) ($tx['discount_food_cents'] ?? 0);
            if ($discount > 0) {
                $body .= ' (korting: € ' . number_format($discount / 100, 2, ',', '.') . ')';
            }
            $color = 'var(--text-secondary)';
            break;
        case 'bonus':
            $icon = '🎁';
            $title = 'Bonus ontvangen';
            $body = '+ € ' . number_format($cents / 100, 2, ',', '.');
            $color = 'var(--color-success)';
            break;
        case 'correction':
            $icon = '🔧';
            $title = 'Correctie';
            $body = '€ ' . number_format($cents / 100, 2, ',', '.');
            $color = 'var(--color-warning)';
            break;
        default:
            $icon = '📋';
            $title = ucfirst($type);
            $body = '€ ' . number_format($cents / 100, 2, ',', '.');
            $color = 'var(--text-secondary)';
    }

    if ($desc !== '') {
        $body .= ' — ' . $desc;
    }

    // Relative time
    $txTime = strtotime($date);
    $diff = time() - $txTime;
    if ($diff < 60) {
        $timeAgo = 'Zojuist';
    } elseif ($diff < 3600) {
        $timeAgo = (int) ($diff / 60) . ' min geleden';
    } elseif ($diff < 86400) {
        $timeAgo = (int) ($diff / 3600) . ' uur geleden';
    } elseif ($diff < 604800) {
        $timeAgo = (int) ($diff / 86400) . ' dagen geleden';
    } else {
        $timeAgo = date('d M Y', $txTime);
    }

    $notifications[] = [
        'icon' => $icon,
        'title' => $title,
        'body' => $body,
        'color' => $color,
        'time' => $timeAgo,
        'points' => (int) ($tx['points_earned'] ?? 0),
    ];
}
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg);">
    <h1 style="margin-bottom: var(--space-lg);">
        <span style="margin-right: 8px;">📬</span> Inbox
    </h1>

    <?php if (empty($notifications)): ?>
        <!-- Empty State -->
        <div class="glass-card" style="padding: var(--space-xl); text-align: center;">
            <p style="font-size: 48px; margin-bottom: var(--space-md);">📭</p>
            <h2 style="color: var(--text-secondary); margin-bottom: var(--space-sm);">Nog geen berichten</h2>
            <p class="text-secondary">Je transacties en notificaties verschijnen hier automatisch.</p>
            <a href="<?= BASE_URL ?>/dashboard" class="btn btn-primary" style="margin-top: var(--space-lg);">Terug naar dashboard</a>
        </div>
    <?php else: ?>
        <!-- Notification Feed -->
        <div class="inbox-feed" style="display: flex; flex-direction: column; gap: var(--space-sm);">

            <?php foreach ($notifications as $notif): ?>
                <div class="glass-card inbox-item" style="
                    padding: var(--space-md) var(--space-lg);
                    display: flex;
                    align-items: center;
                    gap: var(--space-md);
                    transition: transform 0.15s ease, box-shadow 0.15s ease;
                    cursor: default;
                ">
                    <!-- Icon -->
                    <div style="
                        font-size: 28px;
                        min-width: 44px;
                        height: 44px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        border-radius: 12px;
                        background: rgba(255,255,255,0.05);
                    "><?= $notif['icon'] ?></div>

                    <!-- Content -->
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: var(--space-sm);">
                            <p style="font-weight: 600; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= sanitize($notif['title']) ?>
                            </p>
                            <span class="text-secondary" style="font-size: 12px; white-space: nowrap;"><?= sanitize($notif['time']) ?></span>
                        </div>
                        <p class="text-secondary" style="font-size: 14px; margin-top: 2px; color: <?= $notif['color'] ?>;">
                            <?= sanitize($notif['body']) ?>
                        </p>
                        <?php if ($notif['points'] > 0): ?>
                            <p style="font-size: 12px; color: var(--accent-primary); margin-top: 2px;">
                                ⭐ +<?= $notif['points'] ?> punten verdiend
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; gap: var(--space-sm); margin-top: var(--space-lg);">
                <?php if ($page > 1): ?>
                    <a href="/inbox?page=<?= $page - 1 ?>" class="btn btn-secondary" style="font-size: 14px;">← Vorige</a>
                <?php endif; ?>
                <span class="text-secondary" style="display: flex; align-items: center; font-size: 14px;">
                    Pagina <?= $page ?> van <?= $totalPages ?>
                </span>
                <?php if ($page < $totalPages): ?>
                    <a href="/inbox?page=<?= $page + 1 ?>" class="btn btn-secondary" style="font-size: 14px;">Volgende →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Back link -->
    <div style="margin-top: var(--space-xl); text-align: center;">
        <a href="/dashboard" class="text-secondary" style="font-size: 14px; text-decoration: none;">← Terug naar dashboard</a>
    </div>
</div>

<style>
    .inbox-item:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 16px rgba(0,0,0,0.3);
    }
    @media (max-width: 480px) {
        .inbox-item {
            padding: var(--space-sm) var(--space-md) !important;
        }
    }
</style>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
