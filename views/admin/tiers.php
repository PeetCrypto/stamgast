<?php
/**
 * STAMGAST - Admin Tier Configuratie
 * Admin: loyalty tiers beheren
 */
$firstName = $_SESSION['first_name'] ?? 'Admin';
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<div class="container" style="padding: var(--space-lg); max-width: 100%; width: 100%;">
    <h1 style="margin-bottom: var(--space-lg); text-align: center;">Loyalty Tiers</h1>
    <p style="text-align: center; margin-bottom: var(--space-xl); opacity: 0.7;">Configureer kortingen en beloningen per niveau</p>

    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); overflow-x: auto;">
        <!-- Tiers Table -->
        <div style="min-width: 800px;">
            <table class="data-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th style="text-align: center;">Min. storting</th>
                        <th style="text-align: center;">Alcohol korting</th>
                        <th style="text-align: center;">Food korting</th>
                        <th style="text-align: center;">Punten multiplier</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody id="tiers-table-body">
                    <tr>
                        <td colspan="6" style="text-align: center; padding: var(--space-xl);">
                            <p>Laden...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Tier Button -->
    <div style="text-align: center; margin-bottom: var(--space-xl);">
        <button class="btn btn-primary" id="add-tier-btn">
            <span style="margin-right: var(--space-sm);">+</span>
            Nieuwe Tier
        </button>
    </div>

    <div style="text-align: center;">
        <a href="/admin" class="btn btn-secondary">Terug naar Dashboard</a>
    </div>
</div>

<!-- Tier Modal -->
<div class="modal-overlay" id="tier-modal-overlay">
    <div class="modal" id="tier-modal" style="max-width: 500px;">
        <div class="modal-header">
            <h2 id="tier-modal-title">Tier</h2>
            <button class="btn-close" id="close-tier-modal">&times;</button>
        </div>
        <div class="modal-body">
            <form id="tier-form">
                <input type="hidden" id="tier-id">
                
                <div class="form-group">
                    <label for="tier-name">Naam</label>
                    <input type="text" id="tier-name" class="form-input" placeholder="Bijv. Goud" required>
                </div>
                
                <div class="form-group">
                    <label for="tier-min-deposit">Minimum storting (€)</label>
                    <input type="number" id="tier-min-deposit" class="form-input" min="0" step="1" required>
                    <small class="help-text">Voor deze tier bereikt moet men dit totaal gestort hebben</small>
                </div>
                
                <div class="form-group">
                    <label for="tier-alc-discount">Alcohol korting (%)</label>
                    <input type="number" id="tier-alc-discount" class="form-input" min="0" max="25" value="0">
                    <small class="help-text">Maximaal 25% (wettelijk maximum)</small>
                </div>
                
                <div class="form-group">
                    <label for="tier-food-discount">Etenswaren korting (%)</label>
                    <input type="number" id="tier-food-discount" class="form-input" min="0" max="100" value="0">
                    <small class="help-text">Maximaal 100%</small>
                </div>
                
                <div class="form-group">
                    <label for="tier-multiplier">Punten multiplier</label>
                    <input type="number" id="tier-multiplier" class="form-input" min="1" max="10" step="0.1" value="1">
                    <small class="help-text">Bijv. 1.5 = 50% extra punten</small>
                </div>
                
                <div class="form-actions" style="display: flex; justify-content: space-between; margin-top: var(--space-lg);">
                    <button type="button" class="btn btn-danger" id="delete-tier-btn" style="display: none;">Verwijderen</button>
                    <button type="submit" class="btn btn-primary">Opslaan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Alerts -->
<div class="alerts-container"></div>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
<script src="/public/js/app.js"></script>
<script src="/public/js/admin.js"></script>
</body>
</html>