/**
 * STAMGAST - App Initializer & Router
 * Midnight Lounge PWA Framework
 */
(function() {
    'use strict';

    // ============================================
    // CONFIGURATION
    // ============================================
    const APP_CONFIG = {
        API_BASE: (window.__BASE_URL || '') + '/api',
        SESSION_CHECK_INTERVAL: 30000, // 30 seconds
        QR_REFRESH_INTERVAL: 55000,   // 55 seconds (refresh before 60s expiry)
        DEBUG: false
    };

    // ============================================
    // STATE MANAGEMENT
    // ============================================
    const AppState = {
        user: null,
        tenant: null,
        wallet: null,
        accountStatus: null,
        sessionChecked: false,
        currentView: null
    };

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================
    function log(...args) {
        if (APP_CONFIG.DEBUG) {
            console.log('[STAMGAST]', ...args);
        }
    }

    function showError(message, duration = 5000) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-error';
        alertDiv.textContent = message;
        
        const container = document.querySelector('.alerts-container') || document.body;
        container.appendChild(alertDiv);
        
        setTimeout(() => alertDiv.classList.add('show'), 10);
        
        if (duration > 0) {
            setTimeout(() => {
                alertDiv.classList.remove('show');
                setTimeout(() => alertDiv.remove(), 300);
            }, duration);
        }
        
        return alertDiv;
    }

    function showSuccess(message, duration = 3000) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success';
        alertDiv.textContent = message;
        
        const container = document.querySelector('.alerts-container') || document.body;
        container.appendChild(alertDiv);
        
        setTimeout(() => alertDiv.classList.add('show'), 10);
        
        setTimeout(() => {
            alertDiv.classList.remove('show');
            setTimeout(() => alertDiv.remove(), 300);
        }, duration);
    }

    function formatCurrency(cents) {
        return new Intl.NumberFormat('nl-NL', {
            style: 'currency',
            currency: 'EUR'
        }).format(cents / 100);
    }

    function formatPoints(cents) {
        return new Intl.NumberFormat('nl-NL').format(cents / 100);
    }

    // ============================================
    // API CLIENT
    // ============================================
    async function apiCall(endpoint, options = {}) {
        const url = `${APP_CONFIG.API_BASE}${endpoint}`;
        const defaults = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            credentials: 'same-origin'
        };
        
        const config = { ...defaults, ...options };
        
        if (config.body && typeof config.body === 'object') {
            config.body = JSON.stringify(config.body);
        }
        
        try {
            const response = await fetch(url, config);
            
            // Try to parse JSON, handle non-JSON responses gracefully
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                throw new Error('Server returned non-JSON response');
            }
            
            if (!response.ok) {
                // On 401 Unauthorized, redirect to login for server-rendered pages
                if (response.status === 401) {
                    const isServerPage = SERVER_RENDERED_PAGES.includes(
                        window.location.pathname.replace(/\/$/, '').split('?')[0]
                    );
                    if (isServerPage) {
                        // Server-rendered page with expired session — redirect to login
                        window.location.href = (window.__BASE_URL || '') + '/login';
                        throw new Error('Sessie verlopen — doorverwijzen naar login...');
                    }
                }
                throw new Error(data.error || data.message || 'API Error');
            }
            
            return data;
        } catch (error) {
            log('API Error:', error);
            throw error;
        }
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    // ============================================
    // SESSION MANAGEMENT
    // ============================================

    /**
     * Pages rendered server-side by PHP (index.php router). These already have
     * proper session/auth checks in their view templates, so a failed JS
     * session-check must NOT redirect away — the server already validated access.
     * Only SPA-like navigations should trigger a redirect on session failure.
     */
    const SERVER_RENDERED_PAGES = [
        '/dashboard', '/wallet', '/qr', '/inbox',
        '/scan', '/payment',
        '/admin', '/admin/users', '/admin/tiers', '/admin/settings', '/admin/marketing',
        '/superadmin', '/superadmin/tenants'
    ];

    function isServerRenderedPage() {
        const path = window.location.pathname.replace(/\/$/, '').split('?')[0];
        return SERVER_RENDERED_PAGES.includes(path);
    }

    async function checkSession() {
        try {
            const response = await apiCall('/auth/session');
            const data = response.data || response;
            if (data.authenticated) {
                AppState.user = data.user;
                AppState.accountStatus = data.user.account_status || 'unverified';
                AppState.sessionChecked = true;
                log('Session valid:', AppState.user);
                return true;
            } else {
                AppState.user = null;
                // Only redirect on non-login pages that are NOT server-rendered.
                // Server-rendered pages already have PHP session protection;
                // a failed JS session check here is likely a transient API issue.
                if (window.location.pathname !== '/login' &&
                    window.location.pathname !== '/' &&
                    !isServerRenderedPage()) {
                    redirectToLogin();
                }
                return false;
            }
        } catch (error) {
            log('Session check failed:', error);
            // On API failure: do NOT redirect server-rendered pages.
            // The PHP session is still valid — this is likely a transient network/API error.
            return false;
        }
    }

    function redirectToLogin() {
        window.location.href = (window.__BASE_URL || '') + '/login';
    }

    function getUserRole() {
        return AppState.user?.role || null;
    }

    function isAuthenticated() {
        return AppState.user !== null;
    }

    // ============================================
    // ROUTING
    // ============================================
    const ROUTES = {
        '/': 'dashboard',
        '/dashboard': 'dashboard',
        '/wallet': 'wallet',
        '/qr': 'qr',
        '/inbox': 'inbox',
        '/scan': 'scanner',
        '/payment': 'payment',
        '/admin': 'admin',
        '/admin/users': 'adminUsers',
        '/admin/tiers': 'adminTiers',
        '/admin/settings': 'adminSettings',
        '/admin/marketing': 'adminMarketing',
        '/superadmin': 'superadmin',
        '/superadmin/tenants': 'superadminTenants'
    };

    function getRouteFromPath(pathname) {
        // Remove trailing slash and query params
        const cleanPath = pathname.replace(/\/$/, '').split('?')[0];
        return ROUTES[cleanPath] || ROUTES[cleanPath.replace('/stamgast', '')] || 'unknown';
    }

    async function handleRoute() {
        const route = getRouteFromPath(window.location.pathname);
        log('Handling route:', route);
        
        // Check authentication for protected routes
        if (!isAuthenticated() && route !== 'unknown') {
            await checkSession();
        }
        
        // Route-specific initialization (use STAMGAST namespace, not global functions)
        switch (route) {
            case 'wallet':
                if (window.STAMGAST?.wallet?.init) window.STAMGAST.wallet.init();
                else if (typeof initWallet === 'function') initWallet();
                break;
            case 'qr':
                if (window.STAMGAST?.qr?.init) window.STAMGAST.qr.init();
                else if (typeof initQR === 'function') initQR();
                break;
            case 'scanner':
            case 'payment':
                if (window.STAMGAST?.pos?.init) window.STAMGAST.pos.init();
                else if (typeof initPOS === 'function') initPOS();
                break;
            case 'admin':
            case 'adminUsers':
            case 'adminTiers':
            case 'adminSettings':
            case 'adminMarketing':
                if (window.STAMGAST?.admin?.init) window.STAMGAST.admin.init();
                else if (typeof initAdmin === 'function') initAdmin();
                break;
            case 'superadmin':
            case 'superadminTenants':
                if (window.STAMGAST?.superadmin?.init) window.STAMGAST.superadmin.init();
                else if (typeof initSuperAdmin === 'function') initSuperAdmin();
                break;
        }
        
        AppState.currentView = route;
    }

    // ============================================
    // NAVIGATION
    // ============================================
    function navigateTo(path) {
        window.history.pushState({}, '', path);
        handleRoute();
    }

    function setupNavigation() {
        // Handle browser back/forward
        window.addEventListener('popstate', handleRoute);
        
        // Handle link clicks
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[data-link]');
            if (link) {
                e.preventDefault();
                navigateTo(link.getAttribute('href'));
            }
        });
    }

    // ============================================
    // UI HELPERS
    // ============================================
    function updateWalletDisplay() {
        const balanceEl = document.getElementById('wallet-balance');
        const pointsEl = document.getElementById('wallet-points');
        
        if (balanceEl && AppState.wallet) {
            balanceEl.textContent = formatCurrency(AppState.wallet.balance_cents);
        }
        
        if (pointsEl && AppState.wallet) {
            pointsEl.textContent = formatPoints(AppState.wallet.points_cents);
        }
    }

    function showLoading(element) {
        if (element) {
            element.classList.add('loading');
            element.dataset.originalContent = element.innerHTML;
            element.innerHTML = '<span class="spinner"></span>';
        }
    }

    function hideLoading(element) {
        if (element && element.classList.contains('loading')) {
            element.classList.remove('loading');
            element.innerHTML = element.dataset.originalContent || element.innerHTML;
        }
    }

    // ============================================
    // SERVICE WORKER REGISTRATION
    // ============================================
    async function registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            try {
                const registration = await navigator.serviceWorker.register('/public/js/sw.js');
                log('ServiceWorker registered:', registration);
            } catch (error) {
                log('ServiceWorker registration failed:', error);
            }
        }
    }

    // ============================================
    // THEME & BRANDING
    // ============================================
    function applyTenantBranding(tenant) {
        if (!tenant) return;
        
        const root = document.documentElement;
        
        if (tenant.brand_color) {
            root.style.setProperty('--brand-color', tenant.brand_color);
        }
        
        if (tenant.secondary_color) {
            root.style.setProperty('--secondary-color', tenant.secondary_color);
        }
        
        // Update page title with tenant name
        if (tenant.name) {
            document.title = `${tenant.name} - Stamgast`;
        }
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    async function init() {
        log('Initializing STAMGAST App...');
        
        // Setup navigation
        setupNavigation();
        
        // Check session
        await checkSession();
        
        // Register service worker
        registerServiceWorker();
        
        // Apply tenant branding if available
        if (AppState.user?.tenant_id) {
            try {
                const tenantResponse = await apiCall('/auth/session');
                if (tenantResponse.tenant) {
                    applyTenantBranding(tenantResponse.tenant);
                }
            } catch (e) {
                log('Could not load tenant branding');
            }
        }
        
        // Handle initial route
        await handleRoute();
        
        // Periodic session check
        setInterval(checkSession, APP_CONFIG.SESSION_CHECK_INTERVAL);
        
        log('STAMGAST App initialized');
    }

    // ============================================
    // EXPORTS
    // ============================================
    window.STAMGAST = {
        // State
        state: AppState,
        
        // API
        api: apiCall,
        
        // Navigation
        navigate: navigateTo,
        
        // UI
        showError,
        showSuccess,
        showLoading,
        hideLoading,
        formatCurrency,
        formatPoints,
        
        // Auth
        checkSession,
        isAuthenticated,
        isAccountActive: () => AppState.accountStatus === 'active',
        getUserRole,
        redirectToLogin,
        
        // Routing
        handleRoute,
        
        // Config
        config: APP_CONFIG
    };

    // Auto-init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
