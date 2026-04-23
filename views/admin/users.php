<?php
/**
 * STAMGAST - Admin Gebruikersbeheer
 * Admin: gebruikers bekijken, rollen wijzigen, blokkeren
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

<div class="container" style="padding: var(--space-lg); max-width: 100%; width: 100%;">
    <h1 style="margin-bottom: var(--space-lg); text-align: center;">Gebruikers</h1>

    <div class="glass-card" style="padding: var(--space-lg); margin-bottom: var(--space-lg); overflow-x: auto;">
        <!-- Filters -->
        <div style="display: flex; gap: var(--space-md); flex-wrap: wrap; margin-bottom: var(--space-md); min-width: 800px;">
            <input type="text" id="search-input" placeholder="Zoeken..." class="form-input" style="flex: 1; min-width: 200px;">
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

        <!-- Users Table -->
        <div style="min-width: 800px;">
            <table class="data-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Tier</th>
                        <th style="text-align: center;">Saldo</th>
                        <th style="text-align: center;">Laatste activiteit</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody id="users-table-body">
                    <tr>
                        <td colspan="7" style="text-align: center; padding: var(--space-xl);">
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
        <a href="/admin" class="btn btn-secondary">Terug naar Dashboard</a>
    </div>
</div>

<!-- User Modal -->
<div class="modal-overlay" id="user-modal-overlay">
    <div class="modal" id="user-modal" style="max-width: 500px;">
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
                    <label for="user-role">Rol</label>
                    <select id="user-role" class="form-input">
                        <option value="guest">Gast</option>
                        <option value="bartender">Bartender</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                
                <div class="form-actions" style="display: flex; justify-content: space-between; margin-top: var(--space-lg);">
                    <button type="button" class="btn btn-danger" id="block-user-btn">Blokkeren</button>
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