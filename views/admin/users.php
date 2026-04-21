<?php
/**
 * STAMGAST - Admin Gebruikersbeheer
 * Admin: gebruikers bekijken, rollen wijzigen, blokkeren
 */
require_once __DIR__ . '/../shared/header.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header('Location: /login');
    exit;
}

$user = $_SESSION;
$tenant = $_SESSION['tenant'] ?? null;
?>
<body class="admin-page users-page">
    <main class="main-container">
        <!-- Header -->
        <div class="page-header">
            <h1>Gebruikers</h1>
            <div class="header-actions">
                <button class="btn btn-primary" id="add-user-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                        <circle cx="8.5" cy="7" r="4"/>
                        <line x1="20" y1="8" x2="20" y2="14"/>
                        <line x1="23" y1="11" x2="17" y2="11"/>
                    </svg>
                    Toevoegen
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <div class="search-box">
                <input type="text" id="search-input" placeholder="Zoeken...">
            </div>
            <select id="role-filter" class="filter-select">
                <option value="">Alle rollen</option>
                <option value="guest">Gasten</option>
                <option value="bartender">Bartenders</option>
                <option value="admin">Admins</option>
            </select>
            <select id="tier-filter" class="filter-select">
                <option value="">Alle tiers</option>
                <option value="bronze">Brons</option>
                <option value="silver">Zilver</option>
                <option value="gold">Goud</option>
            </select>
        </div>

        <!-- Users Table -->
        <div class="table-container glass-card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Gebruiker</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Tier</th>
                        <th>Saldo</th>
                        <th>Laatste activiteit</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody id="users-table-body">
                    <tr class="loading-row">
                        <td colspan="7">
                            <div class="spinner"></div>
                            <p>Laden...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination" id="users-pagination">
            <button class="btn btn-sm" id="prev-page" disabled>&larr;</button>
            <span class="page-info" id="page-info">Pagina 1</span>
            <button class="btn btn-sm" id="next-page">&rarr;</button>
        </div>
    </main>

    <!-- User Modal -->
    <div class="modal" id="user-modal">
        <div class="modal-content glass-card">
            <div class="modal-header">
                <h2 id="modal-title">Gebruiker</h2>
                <button class="btn-close" id="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="user-form">
                    <input type="hidden" id="user-id">
                    
                    <div class="form-group">
                        <label for="user-first-name">Voornaam</label>
                        <input type="text" id="user-first-name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="user-last-name">Achternaam</label>
                        <input type="text" id="user-last-name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="user-email">Email</label>
                        <input type="email" id="user-email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="user-role">Rol</label>
                        <select id="user-role">
                            <option value="guest">Gast</option>
                            <option value="bartender">Bartender</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-danger" id="block-user-btn">
                            Blokkeren
                        </button>
                        <button type="submit" class="btn btn-primary">
                            Opslaan
                        </button>
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