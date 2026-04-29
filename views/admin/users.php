<?php
/**
 * STAMGAST - Admin Gebruikersbeheer
 * Admin: gebruikers bekijken, aanmaken, rollen wijzigen, blokkeren, wachtwoord resetten
 */
$firstName = $_SESSION['first_name'] ?? 'Admin';
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;

// Load tiers dynamically for filter dropdown
$db = Database::getInstance()->getConnection();
$tierModel = new LoyaltyTier($db);
$tenantId = currentTenantId();
$tiersList = $tierModel->getByTenant($tenantId);
?>

<?php require VIEWS_PATH . 'shared/header.php'; ?>

<style>
    .badge-blocked {
        background: rgba(244, 67, 54, 0.15);
        color: var(--error, #f44336);
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 600;
        margin-left: 6px;
    }
    .user-blocked-row {
        opacity: 0.55;
    }
    .user-blocked-row td:first-child::after {
        content: '';
    }
    .password-reset-section {
        background: rgba(255, 193, 7, 0.06);
        border: 1px solid rgba(255, 193, 7, 0.2);
        border-radius: 8px;
        padding: var(--space-md);
        margin-top: var(--space-md);
    }
    .password-reset-section h4 {
        margin: 0 0 var(--space-sm) 0;
        font-size: 14px;
        color: var(--text-secondary);
    }
    .password-toggle-wrap {
        position: relative;
        display: flex;
        gap: var(--space-sm);
    }
    .password-toggle-wrap .form-input {
        flex: 1;
    }
    .password-toggle-wrap .btn {
        min-width: auto;
        padding: 0 12px;
        font-size: 13px;
    }
    .btn-add-user {
        background: var(--brand-color, #FFC107);
        color: #000;
        font-weight: 600;
        border: none;
        padding: 8px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
    }
    .btn-add-user:hover {
        opacity: 0.9;
    }
    .table-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: var(--space-md);
    }
</style>

<div class="container" style="padding: var(--space-lg); max-width: 100%; width: 100%;">
    <h1 style="margin-bottom: var(--space-lg); text-align: center;">Gebruikers</h1>

    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); overflow-x: auto;">
        <!-- Filters + Add Button -->
        <div class="table-header" style="min-width: 800px;">
            <div style="display: flex; gap: var(--space-md); flex-wrap: wrap; flex: 1;">
                <input type="text" id="search-input" placeholder="Zoeken op naam of email..." class="form-input" style="flex: 1; min-width: 200px;">
                <select id="role-filter" class="form-input" style="min-width: 150px;">
                    <option value="">Alle rollen</option>
                    <option value="guest">Gasten</option>
                    <option value="bartender">Bartenders</option>
                    <option value="admin">Admins</option>
                </select>
                <select id="tier-filter" class="form-input" style="min-width: 150px;">
                    <option value="">Alle tiers</option>
                    <?php foreach ($tiersList as $tier): ?>
                    <option value="<?= htmlspecialchars($tier['name']) ?>"><?= htmlspecialchars(ucfirst($tier['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-add-user" id="add-user-btn" style="margin-left: var(--space-md);">
                + Gebruiker toevoegen
            </button>
        </div>

        <!-- Users Table -->
        <div style="min-width: 800px;">
            <table class="data-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Tier</th>
                        <th style="text-align: center;">Status</th>
                        <th style="text-align: center;">Saldo</th>
                        <th style="text-align: center;">Laatste activiteit</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody id="users-table-body">
                    <tr>
                        <td colspan="8" style="text-align: center; padding: var(--space-xl);">
                            <p>Laden...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div style="display: flex; justify-content: center; gap: var(--space-md); align-items: center; min-width: 800px; padding-bottom: var(--space-md);">
        <button class="btn btn-sm" id="prev-page" disabled>&larr;</button>
        <span id="page-info">Pagina 1</span>
        <button class="btn btn-sm" id="next-page">&rarr;</button>
    </div>

    <div style="text-align: center; margin-top: var(--space-lg);">
        <a href="<?= BASE_URL ?>/admin" class="btn btn-secondary">Terug naar Dashboard</a>
    </div>
</div>

<!-- User Modal -->
<div class="modal-overlay" id="user-modal-overlay">
    <div class="modal" id="user-modal" style="max-width: 520px;">
        <div class="modal-header">
            <h2 id="modal-title">Gebruiker</h2>
            <button class="btn-close" id="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <form id="user-form">
                <input type="hidden" id="user-id">

                <div class="form-group">
                    <label for="user-first-name">Voornaam</label>
                    <input type="text" id="user-first-name" class="form-input" required>
                </div>

                <div class="form-group">
                    <label for="user-last-name">Achternaam</label>
                    <input type="text" id="user-last-name" class="form-input" required>
                </div>

                <div class="form-group">
                    <label for="user-email">Email</label>
                    <input type="email" id="user-email" class="form-input" required>
                </div>

                <div class="form-group">
                    <label for="user-birthdate">Geboortedatum</label>
                    <input type="date" id="user-birthdate" class="form-input">
                </div>

                <!-- Password field - only shown when creating new user -->
                <div class="form-group" id="password-create-group" style="display: none;">
                    <label for="user-password">Wachtwoord <small>(min. 8 tekens)</small></label>
                    <input type="password" id="user-password" class="form-input" autocomplete="new-password" placeholder="Minimaal 8 tekens">
                </div>

                <div class="form-group">
                    <label for="user-role">Rol</label>
                    <select id="user-role" class="form-input">
                        <option value="guest">Gast</option>
                        <option value="bartender">Bartender</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <!-- Password Reset section - only shown when editing existing user -->
                <div class="password-reset-section" id="password-reset-section" style="display: none;">
                    <h4>🔑 Wachtwoord resetten</h4>
                    <div class="password-toggle-wrap">
                        <input type="password" id="reset-password-input" class="form-input" placeholder="Nieuw wachtwoord (min. 8 tekens)" autocomplete="new-password">
                        <button type="button" class="btn btn-sm" id="toggle-password-visibility" title="Toon/verberg wachtwoord">👁</button>
                    </div>
                    <button type="button" class="btn btn-sm" id="reset-password-btn" style="margin-top: var(--space-sm); width: 100%;">
                        Wachtwoord wijzigen
                    </button>
                </div>

                <div class="form-actions" style="display: flex; justify-content: space-between; margin-top: var(--space-lg);">
                    <div id="block-actions">
                        <button type="button" class="btn btn-danger" id="block-user-btn">Blokkeren</button>
                        <button type="button" class="btn" id="unblock-user-btn" style="display: none; background: rgba(76,175,80,0.15); color: var(--success, #4CAF50);">Deblokkeren</button>
                    </div>
                    <button type="submit" class="btn btn-primary" id="save-user-btn">Opslaan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Alerts -->
<div class="alerts-container"></div>

<script src="<?= BASE_URL ?>/public/js/app.js"></script>
<script src="<?= BASE_URL ?>/public/js/admin.js"></script>

<?php require VIEWS_PATH . 'shared/footer.php'; ?>
