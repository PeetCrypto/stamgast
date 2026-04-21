<?php
/**
 * STAMGAST - Admin Tier Configuratie
 * Admin: loyalty tiers beheren
 */
require_once __DIR__ . '/../shared/header.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header('Location: /login');
    exit;
}

$user = $_SESSION;
$tenant = $_SESSION['tenant'] ?? null;
?>
<body class="admin-page tiers-page">
    <main class="main-container">
        <!-- Header -->
        <div class="page-header">
            <h1>Loyalty Tiers</h1>
            <p class="text-muted">Configureer kortingen en beloningen per niveau</p>
        </div>

        <!-- Tiers Grid -->
        <div class="tiers-grid" id="tiers-grid">
            <!-- Tier Cards (loaded via JS) -->
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Tiers laden...</p>
            </div>
        </div>

        <!-- Add Tier Button -->
        <button class="btn btn-primary btn-add" id="add-tier-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Nieuwe Tier
        </button>
    </main>

    <!-- Tier Modal -->
    <div class="modal" id="tier-modal">
        <div class="modal-content glass-card">
            <div class="modal-header">
                <h2 id="modal-title">Tier</h2>
                <button class="btn-close" id="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="tier-form">
                    <input type="hidden" id="tier-id">
                    
                    <div class="form-group">
                        <label for="tier-name">Naam</label>
                        <input type="text" id="tier-name" placeholder="Bijv. Goud" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="tier-min-deposit">Minimum storting (€)</label>
                        <input type="number" id="tier-min-deposit" min="0" step="1" required>
                        <small class="help-text">Voor deze tier bereikt moet men dit totaal gestort hebben</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="tier-alc-discount">Alcohol korting (%)</label>
                        <input type="number" id="tier-alc-discount" min="0" max="25" value="0">
                        <small class="help-text">Maximaal 25% (wettelijk maximum)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="tier-food-discount">Etenswaren korting (%)</label>
                        <input type="number" id="tier-food-discount" min="0" max="100" value="0">
                        <small class="help-text">Maximaal 100%</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="tier-multiplier">Punten multiplier</label>
                        <input type="number" id="tier-multiplier" min="1" max="10" step="0.1" value="1">
                        <small class="help-text">Bijv. 1.5 = 50% extra punten</small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-danger" id="delete-tier-btn">Verwijderen</button>
                        <button type="submit" class="btn btn-primary">Opslaan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <div class="alerts-container"></div>

    <?php require_once __DIR__ . '/../shared/footer.php'; ?>
    <script src="/public/js/app.js"></script>
    <script src="/public/js/admin.js"></script>
</body>
</html>