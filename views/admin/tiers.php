<?php
/**
 * REGULR.vip - Admin Pakketten Configuratie
 * Admin: loyalty pakketten beheren (Brons, Silver, Goud, Platina, custom)
 */
$firstName = $_SESSION['first_name'] ?? 'Admin';
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<style>
    .packages-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: var(--space-lg);
        margin-bottom: var(--space-xl);
    }

    .package-card {
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 16px;
        padding: var(--space-lg);
        position: relative;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .package-card:hover {
        border-color: var(--brand-color, #FFC107);
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    }
    .package-card.is-inactive {
        opacity: 0.45;
        filter: grayscale(0.5);
    }
    .package-card.is-inactive:hover {
        opacity: 0.7;
    }

    .package-card__badge {
        position: absolute;
        top: 12px;
        right: 12px;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        border: 2px solid rgba(255,255,255,0.1);
    }
    .package-card__badge--active { background: #4CAF50; box-shadow: 0 0 8px rgba(76,175,80,0.5); }
    .package-card__badge--inactive { background: #666; }

    .package-card__name {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: var(--space-xs);
        color: var(--text-primary);
    }

    .package-card__amount {
        font-size: 2rem;
        font-weight: 800;
        color: var(--brand-color, #FFC107);
        margin-bottom: var(--space-md);
        line-height: 1;
    }

    .package-card__discounts {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: var(--space-md);
    }

    .package-card__discount-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.85rem;
    }

    .package-card__discount-label {
        color: var(--text-secondary);
    }

    .package-card__discount-value {
        font-weight: 600;
        color: var(--success, #4CAF50);
    }

    .package-card__actions {
        display: flex;
        gap: var(--space-sm);
        margin-top: var(--space-md);
        padding-top: var(--space-sm);
        border-top: 1px solid rgba(255,255,255,0.06);
    }

    .package-card__actions .btn {
        flex: 1;
        font-size: 0.8rem;
        padding: 6px 10px;
    }

    /* Model Selector Cards */
    .model-selector {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--space-lg);
        margin-bottom: var(--space-xl);
    }

    .model-selector-card {
        background: rgba(255,255,255,0.04);
        border: 2px solid rgba(255,255,255,0.1);
        border-radius: 16px;
        padding: var(--space-lg) var(--space-xl);
        cursor: pointer;
        transition: all 0.25s ease;
        text-align: center;
    }
    .model-selector-card:hover {
        border-color: var(--brand-color, #FFC107);
        background: rgba(255,193,7,0.05);
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    }
    .model-selector-card--selected {
        border-color: var(--brand-color, #FFC107);
        background: rgba(255,193,7,0.08);
        box-shadow: 0 0 20px rgba(255,193,7,0.15);
    }
    .model-selector-card__icon {
        font-size: 2rem;
        margin-bottom: var(--space-sm);
    }
    .model-selector-card__title {
        font-size: 1.2rem;
        font-weight: 700;
        margin-bottom: var(--space-xs);
        color: var(--text-primary);
    }
    .model-selector-card__desc {
        font-size: 0.85rem;
        color: var(--text-secondary);
        line-height: 1.5;
    }

    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
    }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0; left: 0; right: 0; bottom: 0;
        background-color: #555;
        border-radius: 24px;
        transition: .3s;
    }
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        border-radius: 50%;
        transition: .3s;
    }
    .toggle-switch input:checked + .toggle-slider { background-color: var(--brand-color, #FFC107); }
    .toggle-switch input:checked + .toggle-slider:before { transform: translateX(20px); }

    .empty-packages {
        text-align: center;
        padding: var(--space-xxl);
        opacity: 0.5;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--space-md);
    }

    @media (max-width: 600px) {
        .form-row { grid-template-columns: 1fr; }
        .packages-grid { grid-template-columns: 1fr; }
        .model-selector { grid-template-columns: 1fr; }
    }
</style>

<div class="container" style="padding: var(--space-lg); max-width: 1400px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg); flex-wrap: wrap; gap: var(--space-md);">
        <div>
            <h1 style="margin: 0;">Pakketten</h1>
            <p style="margin: 0; opacity: 0.6; font-size: 0.9rem;">Configureer opwaardeer-pakketten en kortingen</p>
        </div>
    </div>

    <?php
    $_tdb = Database::getInstance()->getConnection();
    $_tModel = new Tenant($_tdb);
    $_t = $_tModel->findById((int)($_SESSION['tenant_id'] ?? 0));
    $_lockedModel = $_t['tier_model_type'] ?? null;
    ?>

    <!-- MODEL SELECTOR — aparte sectie boven het grid -->
    <div id="model-selector-section">
        <?php if ($_lockedModel): ?>
        <!-- Model is al vastgezet — toon direct stap 2 button -->
        <div class="glass-card" style="padding: var(--space-md); margin-bottom: var(--space-lg); border: 1px solid rgba(255,193,7,0.3);">
            <p style="margin:0;">
                <strong>🔒 Actief model:</strong>
                <?= $_lockedModel === 'bonus' ? 'Opwaardeerbonus' : 'Kortingsmodel' ?>
                <small style="opacity:0.6;margin-left:8px;">— Vastgezet bij het eerste pakket. Reset via superadmin "Verwijder pakketten".</small>
            </p>
        </div>
        <div style="margin-bottom: var(--space-lg);">
            <button class="btn btn-primary" id="add-tier-btn">
                <span style="margin-right: var(--space-sm);">+</span>
                Nieuw Pakket
            </button>
        </div>
        <?php else: ?>
        <!-- Model nog niet gekozen — toon keuzekaarten, button wordt via JS toegevoegd na selectie -->
        <div style="margin-bottom: var(--space-md);">
            <h2 style="font-size: 1.1rem; margin: 0 0 var(--space-xs);">Stap 1: Kies jouw model</h2>
            <p style="margin: 0; opacity: 0.6; font-size: 0.85rem;">Dit kun je achteraf <u>niet</u> meer wijzigen! Kies tussen kortingsmodel of opwaardeerbonus.</p>
        </div>
        <div class="model-selector">
            <div class="model-selector-card" id="select-discount-model" data-model="discount">
                <div class="model-selector-card__icon">🏷️</div>
                <div class="model-selector-card__title">Kortingsmodel</div>
                <div class="model-selector-card__desc">
                    Gast stort een bedrag en krijgt korting op dranken & eten bij bestellingen.
                    <br><strong>Bijv. 10% korting op alle dranken</strong>
                </div>
            </div>
            <div class="model-selector-card" id="select-bonus-model" data-model="bonus">
                <div class="model-selector-card__icon">🎁</div>
                <div class="model-selector-card__title">Opwaardeerbonus</div>
                <div class="model-selector-card__desc">
                    Gast stort een bedrag en krijgt direct extra tegoed bovenop het gestorte bedrag.
                    <br><strong>Bijv. stort €100 → krijg €110 tegoed</strong>
                </div>
            </div>
        </div>
        <!-- Placeholder voor Stap 2 button (gevuld door JS na model selectie) -->
        <div id="step2-container"></div>
        <?php endif; ?>
    </div>

    <!-- Packages Grid -->
    <div class="packages-grid" id="packages-grid">
        <div class="empty-packages" id="packages-empty">
            <p>Pakketten laden...</p>
        </div>
    </div>

    <div style="text-align: center;">
        <a href="<?= BASE_URL ?>/admin" class="btn btn-secondary">Terug naar Dashboard</a>
    </div>
</div>

<!-- Tier Modal (geen model type keuze meer — die is boven het grid) -->
<div class="modal-overlay" id="tier-modal-overlay">
    <div class="modal" id="tier-modal" style="max-width: 520px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <h2 id="tier-modal-title">Pakket</h2>
            <button class="btn-close" id="close-tier-modal">&times;</button>
        </div>
        <div class="modal-body" style="overflow-y: auto; flex: 1;">
            <form id="tier-form">
                <input type="hidden" id="tier-id">

                <div class="form-group">
                    <label for="tier-name">Pakketnaam *</label>
                    <input type="text" id="tier-name" class="form-input" placeholder="Bijv. Brons, Silver, Goud, Platina" required>
                </div>

                <!-- BONUS MODEL FIELDS (hidden by default) -->
                <div id="bonus-fields" style="display:none;">
                    <div class="form-group">
                        <label for="tier-bonus-cents">Bonus bedrag (€) *</label>
                        <input type="number" id="tier-bonus-cents" class="form-input" min="0" max="500" value="10" step="1">
                        <small class="help-text">Vast bonusbedrag bovenop de storting. Bijv. 10 = stort €100 → krijg €110 tegoed</small>
                    </div>
                    <div class="form-group">
                        <label for="tier-food-discount">Eten korting (%) <small style="opacity:0.6;">optioneel</small></label>
                        <input type="number" id="tier-food-discount" class="form-input" min="0" max="100" value="0" step="0.5">
                        <small class="help-text">Extra korting op non-alcohol, max 100%</small>
                    </div>
                </div>

                <!-- DISCOUNT MODEL FIELDS (visible by default) -->
                <div id="discount-fields">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="tier-alc-discount">Alcohol korting (%)</label>
                            <input type="number" id="tier-alc-discount" class="form-input" min="0" max="25" value="0" step="0.5">
                            <small class="help-text">Max 25% (wettelijk)</small>
                        </div>
                        <div class="form-group">
                            <label for="tier-food-discount-d">Eten korting (%)</label>
                            <input type="number" id="tier-food-discount-d" class="form-input" min="0" max="100" value="0" step="0.5">
                            <small class="help-text">Max 100%</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="tier-topup-amount">Stortingsbedrag (€) *</label>
                    <input type="number" id="tier-topup-amount" class="form-input" min="100" step="50" value="100" required>
                    <small class="help-text">Het bedrag dat de gast stort. Minimaal €100. Maak meerdere pakketten voor verschillende bedragen.</small>
                </div>
                <input type="hidden" id="tier-min-deposit" value="0">

                <div class="form-row">
                    <div class="form-group">
                        <label for="tier-multiplier">Punten multiplier</label>
                        <input type="number" id="tier-multiplier" class="form-input" min="1" max="10" step="0.1" value="1">
                        <small class="help-text">Bijv. 1.5 = 50% extra punten</small>
                    </div>
                    <div class="form-group">
                        <label for="tier-sort-order">Volgorde</label>
                        <input type="number" id="tier-sort-order" class="form-input" min="0" step="1" value="0">
                        <small class="help-text">Lager = eerst getoond</small>
                    </div>
                </div>

                <div class="form-group" style="display:flex; align-items:center; gap: var(--space-md);">
                    <label class="toggle-switch">
                        <input type="checkbox" id="tier-is-active" checked>
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Actief</span>
                </div>

                <div class="form-actions" style="display: flex; justify-content: space-between; margin-top: var(--space-lg);">
                    <button type="button" class="btn btn-danger" id="delete-tier-btn" style="display: none;">Verwijderen</button>
                    <button type="submit" class="btn btn-primary" style="margin-left: auto;">Opslaan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Alerts -->
<div class="alerts-container"></div>

<script src="<?= BASE_URL ?>/public/js/app.js?v=<?= filemtime(PUBLIC_PATH . 'js/app.js') ?>"></script>
<script src="<?= BASE_URL ?>/public/js/admin.js?v=<?= filemtime(PUBLIC_PATH . 'js/admin.js') ?>"></script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
