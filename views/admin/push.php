<?php
/**
 * REGULR.vip - Admin Push Notificaties
 * Admin: broadcast en individuele push notificaties versturen
 */
declare(strict_types=1);

$firstName      = $_SESSION['first_name'] ?? 'Admin';
$tenantName     = $_SESSION['tenant_name'] ?? APP_NAME;
$tenantId       = (int) ($_SESSION['tenant_id'] ?? 0);

// Haal tenant op voor feature check
$db             = Database::getInstance()->getConnection();
$tenantModel    = new Tenant($db);
$tenant         = $tenantModel->findById($tenantId);

$featurePush    = (bool) ($tenant['feature_push'] ?? true);
$featureMarketing = (bool) ($tenant['feature_marketing'] ?? true);

// Haal abonnees count op
require_once __DIR__ . '/../../services/PushService.php';
$pushService    = new PushService($db);
$subscriberCount = $pushService->getSubscriptionCount($tenantId);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<style>
    .push-stat-card {
        text-align: center;
        padding: var(--space-lg);
    }
    .push-stat-card .push-number {
        font-size: 40px;
        font-weight: 700;
        line-height: 1;
        margin-bottom: var(--space-xs);
    }
    .push-stat-card .push-label {
        font-size: 13px;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Target mode tabs */
    .target-tabs {
        display: flex;
        gap: var(--space-xs);
        margin-bottom: var(--space-lg);
    }
    .target-tab {
        padding: 8px 20px;
        border-radius: var(--radius-md);
        border: 1px solid var(--glass-border);
        background: transparent;
        color: var(--text-secondary);
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }
    .target-tab:hover {
        border-color: var(--accent-primary);
        color: var(--text-primary);
    }
    .target-tab.active {
        background: var(--accent-gradient);
        color: #000;
        border-color: transparent;
        font-weight: 600;
    }

    /* User search results */
    .user-search-item {
        display: flex;
        align-items: center;
        gap: var(--space-md);
        padding: var(--space-sm) var(--space-md);
        border-bottom: 1px solid var(--glass-border);
        cursor: pointer;
        transition: background 0.15s;
    }
    .user-search-item:hover {
        background: rgba(255, 193, 7, 0.05);
    }
    .user-search-item:last-child { border-bottom: none; }
    .user-search-item .user-info {
        flex: 1;
    }
    .user-search-item .user-name {
        font-weight: 600;
        font-size: 14px;
    }
    .user-search-item .user-email {
        font-size: 12px;
        color: var(--text-secondary);
    }
    .user-search-item .push-badge {
        font-size: 12px;
        padding: 2px 8px;
        border-radius: 12px;
    }
    .push-badge.subscribed {
        background: rgba(76, 175, 80, 0.15);
        color: #4CAF50;
    }
    .push-badge.no-push {
        background: rgba(255, 255, 255, 0.05);
        color: var(--text-secondary);
    }

    /* History item */
    .history-item {
        display: flex;
        align-items: flex-start;
        gap: var(--space-md);
        padding: var(--space-md);
        border-bottom: 1px solid var(--glass-border);
        font-size: 14px;
    }
    .history-item:last-child { border-bottom: none; }
    .history-icon {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
    }
    .history-icon.broadcast {
        background: rgba(76, 175, 80, 0.15);
    }
    .history-icon.individual {
        background: rgba(255, 193, 7, 0.15);
    }
    .history-details {
        flex: 1;
    }
    .history-title {
        font-weight: 600;
        margin-bottom: 2px;
    }
    .history-body {
        color: var(--text-secondary);
        font-size: 13px;
    }
    .history-meta {
        font-size: 12px;
        color: var(--text-secondary);
        margin-top: 4px;
    }

    /* Character counter */
    .char-counter {
        font-size: 12px;
        color: var(--text-secondary);
        text-align: right;
        margin-top: 4px;
    }
    .char-counter.warn { color: var(--accent-primary); }
    .char-counter.over { color: var(--error); }

    /* Preview card */
    .push-preview {
        background: #1a1a1a;
        border-radius: 12px;
        padding: var(--space-md);
        max-width: 360px;
        margin: 0 auto;
    }
    .push-preview-header {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        margin-bottom: var(--space-sm);
    }
    .push-preview-icon {
        width: 24px;
        height: 24px;
        border-radius: 4px;
        background: var(--accent-gradient);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
    }
    .push-preview-app {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-secondary);
    }
    .push-preview-title {
        font-weight: 600;
        font-size: 14px;
        margin-bottom: 2px;
    }
    .push-preview-body {
        font-size: 13px;
        color: var(--text-secondary);
    }
</style>

<div class="container" style="padding: var(--space-lg); max-width: 1000px; margin: 0 auto;">

    <?php if (!$featurePush): ?>
    <!-- Module disabled notice -->
    <div class="glass-card" style="padding: var(--space-xl); text-align: center;">
        <div style="font-size: 48px; margin-bottom: var(--space-md);">🔕</div>
        <h2 style="margin-bottom: var(--space-md);">Push Notificaties Uitgeschakeld</h2>
        <p style="color: var(--text-secondary); margin-bottom: var(--space-lg);">
            De Push module is door de platform beheerder uitgeschakeld.
            Neem contact op met de beheerder om deze functionaliteit te activeren.
        </p>
        <a href="<?= BASE_URL ?>/admin" class="btn btn-primary">Terug naar Dashboard</a>
    </div>

    <?php else: ?>

    <h1 style="margin-bottom: var(--space-xs); text-align: center;">Push Notificaties</h1>
    <p style="text-align: center; margin-bottom: var(--space-xl); opacity: 0.7;">
        Stuur push notificaties naar gasten via de PWA
    </p>

    <!-- ============================================ -->
    <!-- STATISTIEKEN -->
    <!-- ============================================ -->
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-md); margin-bottom: var(--space-xl);">
        <div class="glass-card push-stat-card">
            <div class="push-number" style="color: var(--accent-primary);"><?= $subscriberCount ?></div>
            <div class="push-label">Geabonneerd</div>
        </div>
        <div class="glass-card push-stat-card">
            <div class="push-number" style="color: var(--success);" id="stat-sent">0</div>
            <div class="push-label">Verzonden (7d)</div>
        </div>
        <div class="glass-card push-stat-card">
            <div class="push-number" style="color: var(--error);" id="stat-failed">0</div>
            <div class="push-label">Mislukt (7d)</div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- SECTIE 1: NOTIFICATIE OPSTELLEN -->
    <!-- ============================================ -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-xl);">
        <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">Nieuwe Notificatie</h2>

        <!-- Target mode tabs -->
        <div class="target-tabs">
            <button type="button" class="target-tab active" data-mode="broadcast" id="tab-broadcast">
                📢 Iedereen
            </button>
            <button type="button" class="target-tab" data-mode="individual" id="tab-individual">
                👤 Individueel
            </button>
        </div>

        <!-- Broadcast info -->
        <div id="broadcast-info" style="background: rgba(76,175,80,0.1); border: 1px solid rgba(76,175,80,0.2); border-radius: var(--radius-md); padding: var(--space-md); margin-bottom: var(--space-lg);">
            <p style="font-size: 14px; margin: 0;">
                <strong>Broadcast:</strong> Je notificatie wordt verstuurd naar alle <strong><?= $subscriberCount ?> geabonneerde</strong> gasten met push notificaties ingeschakeld.
            </p>
        </div>

        <!-- Individual user search (hidden by default) -->
        <div id="individual-section" style="display: none; margin-bottom: var(--space-lg);">
            <div class="form-group">
                <label for="user-search">Zoek gast</label>
                <input type="text" id="user-search" class="form-input" placeholder="Typ naam of e-mailadres...">
            </div>
            <div id="user-search-results" style="max-height: 250px; overflow-y: auto; display: none;">
                <!-- Results worden via JS ingevuld -->
            </div>
            <div id="selected-user-info" style="display: none; background: rgba(255,193,7,0.1); border: 1px solid rgba(255,193,7,0.2); border-radius: var(--radius-md); padding: var(--space-md); margin-top: var(--space-sm);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong id="selected-user-name"></strong>
                        <div id="selected-user-email" style="font-size: 13px; color: var(--text-secondary);"></div>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" id="clear-user-btn">✕ Wijzig</button>
                </div>
            </div>
        </div>

        <!-- Compose form -->
        <form id="push-compose-form">
            <div class="form-group">
                <label for="push-title">Titel</label>
                <input type="text" id="push-title" class="form-input" placeholder="Bijv. Speciale aanbieding vanavond!" maxlength="100" required>
                <div class="char-counter"><span id="title-count">0</span>/100</div>
            </div>

            <div class="form-group">
                <label for="push-body">Bericht</label>
                <textarea id="push-body" class="form-input" rows="3" placeholder="Bijv. Kom vanavond naar Cafe De Dulle Griet en krijg 20% korting op alle bieren!" maxlength="500" style="width: 100%; resize: vertical;" required></textarea>
                <div class="char-counter"><span id="body-count">0</span>/500</div>
            </div>

            <!-- Preview -->
            <div style="margin-bottom: var(--space-lg);">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-sm);">Voorbeeldweergave</h3>
                <div class="push-preview">
                    <div class="push-preview-header">
                        <div class="push-preview-icon">🍺</div>
                        <span class="push-preview-app"><?= sanitize($tenantName) ?></span>
                    </div>
                    <div class="push-preview-title" id="preview-title">Je titel hier...</div>
                    <div class="push-preview-body" id="preview-body">Je bericht verschijnt hier als voorbeeld...</div>
                </div>
            </div>

            <div style="text-align: center;">
                <button type="submit" class="btn btn-primary btn-lg" id="push-send-btn">
                    📢 Verstuur naar iedereen
                </button>
            </div>
        </form>
    </div>

    <!-- ============================================ -->
    <!-- SECTIE 2: VERZENDGESCHIEDENIS -->
    <!-- ============================================ -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-xl);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md);">
            <h2 style="color: var(--accent-primary);">Verzendgeschiedenis</h2>
            <button type="button" class="btn btn-secondary btn-sm" id="refresh-history-btn">Verversen</button>
        </div>

        <div id="push-history">
            <p style="color: var(--text-secondary); font-size: 14px; text-align: center; padding: var(--space-md);">
                Geschiedenis ophalen...
            </p>
        </div>
    </div>

    <?php endif; ?>

    <div style="text-align: center; margin-top: var(--space-xl);">
        <a href="<?= BASE_URL ?>/admin" class="btn btn-secondary">Terug naar Dashboard</a>
    </div>
</div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
<script src="<?= BASE_URL ?>/public/js/app.js"></script>
<script src="<?= BASE_URL ?>/public/js/admin.js"></script>
