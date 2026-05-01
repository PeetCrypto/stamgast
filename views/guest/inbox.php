<?php
declare(strict_types=1);
/**
 * Guest Inbox
 * REGULR.vip Loyalty Platform — Notification feed with delete & mark-read
 */

$db = Database::getInstance()->getConnection();
$userId = currentUserId();
$tenantId = currentTenantId();
$firstName = $_SESSION['first_name'] ?? 'Gast';

// Fetch notifications from the dedicated notifications table
require_once __DIR__ . '/../../models/Notification.php';
$notifModel = new Notification($db);

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;

// Get notifications (excludes soft-deleted)
$result = $notifModel->getByUser($userId, $tenantId, $page, $limit);
$notifications = $result['notifications'];
$totalNotif = (int) $result['total'];
$unreadCount = (int) $result['unread_count'];
$totalPages = (int) ceil($totalNotif / $limit);

// Build display items from notifications
$items = [];
foreach ($notifications as $notif) {
    $createdAt = $notif['created_at'];
    $txTime = strtotime($createdAt);
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

    $items[] = [
        'id'     => (int) $notif['id'],
        'icon'   => $notif['icon'],
        'title'  => $notif['title'],
        'body'   => $notif['body'],
        'color'  => $notif['color'],
        'time'   => $timeAgo,
        'points' => (int) $notif['points_earned'],
        'is_read' => (int) $notif['is_read'],
    ];
}
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg);">
    <!-- Header with unread badge and actions -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-lg); flex-wrap: wrap; gap: var(--space-sm);">
        <h1 style="margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>📬</span> Inbox
            <?php if ($unreadCount > 0): ?>
                <span id="unread-badge" style="
                    background: var(--color-success, #4CAF50);
                    color: #000;
                    font-size: 12px;
                    font-weight: 700;
                    padding: 2px 8px;
                    border-radius: 12px;
                    min-width: 20px;
                    text-align: center;
                "><?= $unreadCount ?></span>
            <?php endif; ?>
        </h1>
        <?php if ($unreadCount > 0): ?>
            <button id="btn-mark-all-read" class="btn btn-secondary" style="font-size: 13px; padding: 6px 14px;">
                ✓ Alles gelezen
            </button>
        <?php endif; ?>
    </div>

    <?php if (empty($items)): ?>
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

            <?php foreach ($items as $item): ?>
                <div class="glass-card inbox-item" data-id="<?= $item['id'] ?>" style="
                    padding: var(--space-md) var(--space-lg);
                    display: flex;
                    align-items: center;
                    gap: var(--space-md);
                    transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.3s ease, max-height 0.3s ease;
                    cursor: default;
                    position: relative;
                    <?= $item['is_read'] ? '' : 'border-left: 3px solid var(--color-success, #4CAF50);' ?>
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
                    "><?= $item['icon'] ?></div>

                    <!-- Content -->
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: var(--space-sm);">
                            <p style="font-weight: 600; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= sanitize($item['title']) ?>
                            </p>
                            <span class="text-secondary" style="font-size: 12px; white-space: nowrap;"><?= sanitize($item['time']) ?></span>
                        </div>
                        <p class="text-secondary" style="font-size: 14px; margin-top: 2px; color: <?= $item['color'] ?>;">
                            <?= sanitize($item['body']) ?>
                        </p>
                        <?php if ($item['points'] > 0): ?>
                            <p style="font-size: 12px; color: var(--accent-primary); margin-top: 2px;">
                                ⭐ +<?= $item['points'] ?> punten verdiend
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Delete button -->
                    <button class="inbox-delete-btn" data-id="<?= $item['id'] ?>" title="Verwijder melding" style="
                        background: none;
                        border: none;
                        color: var(--text-secondary, #888);
                        font-size: 18px;
                        cursor: pointer;
                        padding: 6px 8px;
                        border-radius: 8px;
                        transition: background 0.15s ease, color 0.15s ease;
                        flex-shrink: 0;
                        opacity: 0.4;
                    ">✕</button>
                </div>
            <?php endforeach; ?>

        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; gap: var(--space-sm); margin-top: var(--space-lg);">
                <?php if ($page > 1): ?>
                    <a href="<?= BASE_URL ?>/inbox?page=<?= $page - 1 ?>" class="btn btn-secondary" style="font-size: 14px;">← Vorige</a>
                <?php endif; ?>
                <span class="text-secondary" style="display: flex; align-items: center; font-size: 14px;">
                    Pagina <?= $page ?> van <?= $totalPages ?>
                </span>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= BASE_URL ?>/inbox?page=<?= $page + 1 ?>" class="btn btn-secondary" style="font-size: 14px;">Volgende →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Back link -->
    <div style="margin-top: var(--space-xl); text-align: center;">
        <a href="<?= BASE_URL ?>/dashboard" class="text-secondary" style="font-size: 14px; text-decoration: none;">← Terug naar dashboard</a>
    </div>
</div>

<style>
    .inbox-item:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 16px rgba(0,0,0,0.3);
    }
    .inbox-item:hover .inbox-delete-btn {
        opacity: 1;
    }
    .inbox-delete-btn:hover {
        background: rgba(255, 75, 75, 0.15) !important;
        color: #ff4b4b !important;
    }
    .inbox-item.removing {
        opacity: 0;
        transform: translateX(40px);
        max-height: 0;
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        margin-bottom: 0 !important;
        overflow: hidden;
    }

    /* Toast notification overlay */
    .inbox-toast-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(4px);
        z-index: 9998;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.2s ease;
    }
    .inbox-toast-card {
        background: #1e1e32;
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 20px;
        padding: 28px 24px 20px;
        max-width: 340px;
        width: 90%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        animation: slideUp 0.25s ease;
    }
    .inbox-toast-card .toast-icon {
        font-size: 40px;
        margin-bottom: 12px;
    }
    .inbox-toast-card .toast-title {
        font-size: 17px;
        font-weight: 600;
        color: #fff;
        margin-bottom: 6px;
    }
    .inbox-toast-card .toast-body {
        font-size: 14px;
        color: #aaa;
        margin-bottom: 20px;
        line-height: 1.4;
    }
    .inbox-toast-card .toast-actions {
        display: flex;
        gap: 10px;
    }
    .inbox-toast-card .toast-actions button {
        flex: 1;
        padding: 10px 0;
        border-radius: 12px;
        border: none;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.1s ease, opacity 0.15s ease;
    }
    .inbox-toast-card .toast-actions button:active {
        transform: scale(0.96);
    }
    .toast-btn-cancel {
        background: rgba(255,255,255,0.08);
        color: #ccc;
    }
    .toast-btn-cancel:hover {
        background: rgba(255,255,255,0.14);
    }
    .toast-btn-delete {
        background: linear-gradient(135deg, #ff4b4b, #e03030);
        color: #fff;
    }
    .toast-btn-delete:hover {
        opacity: 0.9;
    }

    /* Success toast */
    .inbox-success-toast {
        position: fixed;
        bottom: 24px;
        left: 50%;
        transform: translateX(-50%);
        background: #1e1e32;
        border: 1px solid rgba(76,175,80,0.3);
        border-radius: 14px;
        padding: 12px 20px;
        color: #4CAF50;
        font-size: 14px;
        font-weight: 500;
        z-index: 9999;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        animation: slideUpFade 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; } to { opacity: 1; }
    }
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px) scale(0.96); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    @keyframes slideUpFade {
        from { opacity: 0; transform: translateX(-50%) translateY(16px); }
        to { opacity: 1; transform: translateX(-50%) translateY(0); }
    }
    @media (max-width: 480px) {
        .inbox-item {
            padding: var(--space-sm) var(--space-md) !important;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- CSRF token from meta tag or session ---
    function getCSRFToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        return '';
    }

    // --- Show styled confirmation toast ---
    function showDeleteConfirm(title, body, onConfirm) {
        // Remove any existing toast
        var existing = document.querySelector('.inbox-toast-overlay');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.className = 'inbox-toast-overlay';
        overlay.innerHTML =
            '<div class="inbox-toast-card">' +
                '<div class="toast-icon">🗑️</div>' +
                '<div class="toast-title">' + title + '</div>' +
                '<div class="toast-body">' + body + '</div>' +
                '<div class="toast-actions">' +
                    '<button class="toast-btn-cancel" id="toast-cancel">Annuleren</button>' +
                    '<button class="toast-btn-delete" id="toast-confirm">Verwijderen</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        // Cancel
        document.getElementById('toast-cancel').addEventListener('click', function () {
            overlay.remove();
        });
        // Click outside to cancel
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.remove();
        });
        // Confirm delete
        document.getElementById('toast-confirm').addEventListener('click', function () {
            overlay.remove();
            onConfirm();
        });
    }

    // --- Show success toast ---
    function showSuccessToast(message) {
        var toast = document.createElement('div');
        toast.className = 'inbox-success-toast';
        toast.innerHTML = '✅ ' + message;
        document.body.appendChild(toast);
        setTimeout(function () {
            toast.style.transition = 'opacity 0.3s ease';
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 300);
        }, 2500);
    }

    // --- Delete notification ---
    document.querySelectorAll('.inbox-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var id = parseInt(this.getAttribute('data-id'));
            var item = this.closest('.inbox-item');

            // Get the notification title for a nice message
            var titleEl = item.querySelector('p[style*="font-weight: 600"]');
            var notifTitle = titleEl ? titleEl.textContent.trim() : 'deze melding';

            showDeleteConfirm(
                'Melding verwijderen?',
                'Wil je "' + notifTitle + '" definitief uit je inbox verwijderen?',
                function () {
                    fetch('<?= BASE_URL ?>/api/notification/delete', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': getCSRFToken()
                        },
                        body: JSON.stringify({ notification_id: id })
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) {
                            item.classList.add('removing');
                            setTimeout(function () { item.remove(); checkEmpty(); }, 300);
                            showSuccessToast('Melding verwijderd');
                        }
                    })
                    .catch(function (err) { console.error('Delete failed:', err); });
                }
            );
        });
    });

    // --- Mark all as read ---
    var markAllBtn = document.getElementById('btn-mark-all-read');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            fetch('<?= BASE_URL ?>/api/notification/mark_read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCSRFToken()
                },
                body: JSON.stringify({ mark_all: true })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    document.querySelectorAll('.inbox-item').forEach(function (el) {
                        el.style.borderLeft = 'none';
                    });
                    var badge = document.getElementById('unread-badge');
                    if (badge) badge.remove();
                    markAllBtn.remove();
                    showSuccessToast('Alle meldingen gelezen');
                }
            })
            .catch(function (err) { console.error('Mark read failed:', err); });
        });
    }

    // --- Show empty state when all items removed ---
    function checkEmpty() {
        var remaining = document.querySelectorAll('.inbox-item:not(.removing)');
        if (remaining.length === 0) {
            var feed = document.querySelector('.inbox-feed');
            if (feed) {
                feed.innerHTML = '<div class="glass-card" style="padding: var(--space-xl); text-align: center;">' +
                    '<p style="font-size: 48px; margin-bottom: var(--space-md);">📭</p>' +
                    '<h2 style="color: var(--text-secondary); margin-bottom: var(--space-sm);">Nog geen berichten</h2>' +
                    '<p class="text-secondary">Je transacties en notificaties verschijnen hier automatisch.</p></div>';
            }
        }
    }
});
</script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
