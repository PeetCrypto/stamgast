<?php
/**
 * REGULR.vip - Admin Marketing Studio
 * Admin: segmentatie, email composer, queue status
 */
declare(strict_types=1);

$firstName      = $_SESSION['first_name'] ?? 'Admin';
$tenantName     = $_SESSION['tenant_name'] ?? APP_NAME;
$tenantId       = (int) ($_SESSION['tenant_id'] ?? 0);

// Haal tenant op voor feature check
$db             = Database::getInstance()->getConnection();
$tenantModel    = new Tenant($db);
$tenant         = $tenantModel->findById($tenantId);

$featureMarketing = (bool) ($tenant['feature_marketing'] ?? true);

// Laad tiers voor dropdown
$tierModel      = new LoyaltyTier($db);
$tiersList      = $tierModel->getByTenant($tenantId);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<style>
    /* Segment result list */
    .segment-result-item {
        display: flex;
        align-items: center;
        gap: var(--space-md);
        padding: var(--space-sm) var(--space-md);
        border-bottom: 1px solid var(--glass-border);
        font-size: 14px;
    }
    .segment-result-item:last-child { border-bottom: none; }
    .segment-result-item .seg-email {
        flex: 1;
        color: var(--text-secondary);
    }
    .segment-result-item .seg-tier {
        font-size: 12px;
        padding: 2px 8px;
        border-radius: 12px;
        background: rgba(255,193,7,0.15);
        color: var(--accent-primary);
    }

    /* Queue stat cards */
    .queue-stat {
        text-align: center;
        padding: var(--space-lg);
    }
    .queue-stat .queue-number {
        font-size: 32px;
        font-weight: 700;
        line-height: 1;
        margin-bottom: var(--space-xs);
    }
    .queue-stat .queue-label {
        font-size: 13px;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Placeholder hint in textarea */
    .placeholder-hint {
        font-size: 12px;
        color: var(--text-secondary);
        margin-top: var(--space-xs);
        line-height: 1.6;
    }
    .placeholder-hint code {
        background: rgba(255,255,255,0.1);
        padding: 1px 6px;
        border-radius: 4px;
        font-size: 11px;
    }

    /* Selected counter badge */
    .selected-badge {
        display: inline-flex;
        align-items: center;
        gap: var(--space-xs);
        background: var(--accent-gradient);
        color: #000;
        padding: 4px 12px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 13px;
    }
</style>

<div class="container" style="padding: var(--space-lg); max-width: 1000px; margin: 0 auto;">

    <?php if (!$featureMarketing): ?>
    <!-- Module disabled notice -->
    <div class="glass-card" style="padding: var(--space-xl); text-align: center;">
        <h2 style="margin-bottom: var(--space-md);">Marketing Studio Uitgeschakeld</h2>
        <p style="color: var(--text-secondary); margin-bottom: var(--space-lg);">
            Schakel de Marketing module in via Instellingen om deze functionaliteit te gebruiken.
        </p>
        <a href="<?= BASE_URL ?>/admin/settings" class="btn btn-primary">Naar Instellingen</a>
    </div>
    <?php else: ?>

    <h1 style="margin-bottom: var(--space-xs); text-align: center;">Marketing Studio</h1>
    <p style="text-align: center; margin-bottom: var(--space-xl); opacity: 0.7;">
        Segmenteer gasten, stel e-mails op en beheer de verzendwachtrij
    </p>

    <!-- ============================================ -->
    <!-- SECTIE 1: SEGMENTATIE -->
    <!-- ============================================ -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-xl);">
        <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">1. Gasten Selecteren</h2>

        <form id="segment-form" style="margin-bottom: var(--space-lg);">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-md); margin-bottom: var(--space-md);">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="seg-last-activity">Laatst actief (dagen)</label>
                    <select id="seg-last-activity" class="form-input">
                        <option value="">Geen filter</option>
                        <option value="7">Afgelopen 7 dagen</option>
                        <option value="14">Afgelopen 14 dagen</option>
                        <option value="30" selected>Afgelopen 30 dagen</option>
                        <option value="60">Afgelopen 60 dagen</option>
                        <option value="90">Afgelopen 90 dagen</option>
                        <option value="inactive">Meer dan 30 dagen inactief</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="seg-min-balance">Min. saldo (€)</label>
                    <input type="number" id="seg-min-balance" class="form-input" min="0" step="5" placeholder="Geen minimum">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="seg-tier">Tier</label>
                    <select id="seg-tier" class="form-input">
                        <option value="">Alle tiers</option>
                        <?php foreach ($tiersList as $tier): ?>
                        <option value="<?= htmlspecialchars($tier['name']) ?>"><?= htmlspecialchars(ucfirst($tier['name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center;">
                <button type="submit" class="btn btn-primary">
                    Zoek Gasten
                </button>
                <span id="segment-count" class="selected-badge" style="display: none;">
                    0 gasten geselecteerd
                </span>
            </div>
        </form>

        <!-- Segment resultaten -->
        <div id="segment-results" style="max-height: 300px; overflow-y: auto; display: none;">
            <div style="padding: var(--space-sm) var(--space-md); border-bottom: 1px solid var(--glass-border);">
                <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer; font-size: 13px; color: var(--text-secondary);">
                    <input type="checkbox" id="select-all-seg" checked style="width: 16px; height: 16px;">
                    Alles selecteren / deselecteren
                </label>
            </div>
            <div id="segment-list"></div>
        </div>

        <div id="segment-empty" style="text-align: center; padding: var(--space-xl); display: none;">
            <p style="color: var(--text-secondary);">Geen gasten gevonden met deze filters</p>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- SECTIE 2: EMAIL COMPOSER -->
    <!-- ============================================ -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-xl);">
        <h2 style="margin-bottom: var(--space-md); color: var(--accent-primary);">2. E-mail Opstellen</h2>

        <form id="compose-form">
            <div class="form-group">
                <label for="compose-subject">Onderwerp</label>
                <input type="text" id="compose-subject" class="form-input" placeholder="Bijv. Speciale aanbieding deze week!" required>
            </div>

            <div class="form-group">
                <label for="compose-body">Bericht</label>
                <textarea id="compose-body" class="form-input" rows="8" placeholder="Beste {{first_name}},&#10;&#10;Wij hebben een speciale aanbieding voor je!&#10;&#10;Met vriendelijke groet,&#10;{{tenant_name}}" style="width: 100%; resize: vertical;" required></textarea>
                <p class="placeholder-hint">
                    Gebruik placeholders: <code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{tenant_name}}</code> <code>{{balance}}</code> <code>{{tier}}</code>
                </p>
            </div>

            <div style="text-align: center;">
                <button type="submit" class="btn btn-primary btn-lg" id="compose-send-btn" disabled>
                    Verstuur naar geselecteerde gasten
                </button>
                <p style="margin-top: var(--space-sm); font-size: 13px; color: var(--text-secondary);" id="compose-target-info">
                    Selecteer eerst gasten in stap 1
                </p>
            </div>
        </form>
    </div>

    <!-- ============================================ -->
    <!-- SECTIE 3: QUEUE STATUS -->
    <!-- ============================================ -->
    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-xl);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md);">
            <h2 style="color: var(--accent-primary);">3. Verzendwachtrij</h2>
            <button type="button" class="btn btn-secondary btn-sm" id="refresh-queue-btn">Verversen</button>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-md);">
            <div class="queue-stat glass-card">
                <div class="queue-number" id="queue-pending" style="color: var(--accent-primary);">--</div>
                <div class="queue-label">Wachtend</div>
            </div>
            <div class="queue-stat glass-card">
                <div class="queue-number" id="queue-sent" style="color: var(--success);">--</div>
                <div class="queue-label">Verstuurd</div>
            </div>
            <div class="queue-stat glass-card">
                <div class="queue-number" id="queue-failed" style="color: var(--error);">--</div>
                <div class="queue-label">Mislukt</div>
            </div>
        </div>

        <!-- Recente items -->
        <div style="margin-top: var(--space-lg);">
            <h3 style="font-size: 14px; font-weight: 600; margin-bottom: var(--space-sm);">Recente berichten</h3>
            <div id="queue-items" style="max-height: 200px; overflow-y: auto;">
                <p style="color: var(--text-secondary); font-size: 14px; text-align: center; padding: var(--space-md);">
                    Laden...
                </p>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <div style="text-align: center; margin-top: var(--space-xl);">
        <a href="<?= BASE_URL ?>/admin" class="btn btn-secondary">Terug naar Dashboard</a>
    </div>
</div>

<!-- Alerts -->
<div class="alerts-container"></div>

<script src="<?= BASE_URL ?>/public/js/app.js"></script>
<script src="<?= BASE_URL ?>/public/js/admin.js"></script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
