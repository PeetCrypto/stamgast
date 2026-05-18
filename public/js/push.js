/**
 * REGULR.vip — Push Notification System
 *
 * Combineert Firebase Cloud Messaging (real-time push) met
 * polling fallback ( elke 30s /api/notification/check).
 *
 * Flow:
 * 1. Registreer service worker (/sw.js)
 * 2. Vraag notification permission aan
 * 3. Genereer FCM token via firebase.messaging().getToken()
 * 4. POST token naar /api/push/subscribe
 * 5. Luister naar foreground messages via onMessage()
 * 6. Polling als fallback voor non-push browsers
 */

(function() {
    'use strict';

    var POLL_INTERVAL = 30000; // 30 seconds
    var TOAST_DURATION = 6000; // 6 seconds
    var POLL_URL = (window.__BASE_URL || '') + '/api/notification/check';
    var SUBSCRIBE_URL = (window.__BASE_URL || '') + '/api/push/subscribe';
    var CONFIG_URL = (window.__BASE_URL || '') + '/api/push/config';
    
    // Default fallback VAPID key (will be overwritten by API config)
    var VAPID_KEY = '';

    var lastUnreadCount = 0;
    var lastSeenIds = {};
    var polling = false;
    var pollTimer = null;
    var fcmTokenSent = false;

    console.log('[Push] System loaded');

    // Fetch config and then init
    fetch(CONFIG_URL)
        .then(r => r.json())
        .then(config => {
            if (config.success && config.data.vapid_key) {
                VAPID_KEY = config.data.vapid_key;
                console.log('[Push] VAPID key loaded from server');
            }
            initFCM();
        })
        .catch(err => {
            console.warn('[Push] Could not fetch FCM config, using default VAPID');
            initFCM();
        });

    // ════════════════════════════════════════════════════════════
    // SERVICE WORKER REGISTRATION
    // ════════════════════════════════════════════════════════════

    function registerServiceWorker() {
        var swUrl = (window.__BASE_URL || '') + '/sw.js';


        // Als er al een controller is, return ready promise
        if (navigator.serviceWorker.controller) {
            return navigator.serviceWorker.ready;
        }

        // Registreer de service worker
        return navigator.serviceWorker.register(swUrl, { scope: '/' })
            .then(function(reg) {
                console.log('[Push] SW registered, scope:', reg.scope);
                return navigator.serviceWorker.ready;
            });
    }

    // ════════════════════════════════════════════════════════════
    // FCM — Firebase Cloud Messaging
    // ════════════════════════════════════════════════════════════

    function initFCM() {
        // Check of Firebase beschikbaar is
        if (typeof firebase === 'undefined' || !firebase.messaging) {
            console.warn('[Push] Firebase SDK niet geladen, polling only');
            return;
        }

        // Check browser support
        if (!('serviceWorker' in navigator)) {
            console.warn('[Push] Geen Service Worker support');
            return;
        }

        if (!('PushManager' in window)) {
            console.warn('[Push] Geen Push API support');
            return;
        }

        // Stap 1: Zorg dat service worker geregistreerd is
        registerServiceWorker().then(function(registration) {
            console.log('[Push] Service worker ready');
            
            // Firebase v10 compat: geen useServiceWorker() nodig
            // Geef registration mee aan getToken() via serviceWorkerRegistration optie
            return requestPermissionAndgetToken(registration);
        }).catch(function(err) {
            console.warn('[Push] Service worker setup failed:', err.message);
        });
    }

    function requestPermissionAndgetToken(registration) {
        const messaging = firebase.messaging();

        // Stap 5: Luister naar foreground messages (als app open is)
        // Deze listener moet vroeg gezet worden, ongeacht permission status
        messaging.onMessage(function(payload) {
            console.log('[Push] Foreground message:', payload);

            var title = 'REGULR.vip';
            var body = '';
            var url = (window.__BASE_URL || '') + '/inbox';

            if (payload.notification) {
                title = payload.notification.title || title;
                body = payload.notification.body || '';
            }
            if (payload.data) {
                if (payload.data.title) title = payload.data.title;
                if (payload.data.body) body = payload.data.body;
                if (payload.data.url) url = payload.data.url;
            }

            showBrowserNotification(title, body, '/icons/favicon.png', 'fcm-' + Date.now(), url);
        });

        // Stap 2: Vraag permission aan (indien nog niet gevraagd)
        if (Notification.permission === 'default') {
            console.log('[Push] Requesting notification permission...');
            Notification.requestPermission().then(function(permission) {
                console.log('[Push] Permission:', permission);
                if (permission === 'granted') {
                    getTokenAndSend(messaging, registration);
                }
            }).catch(function(err) {
                console.warn('[Push] Permission request failed:', err);
            });
        } else if (Notification.permission === 'granted') {
            // Al toegestaan — direct token ophalen
            getTokenAndSend(messaging, registration);
        } else {
            console.log('[Push] Notification permission denied');
        }
    }

    function getTokenAndSend(messaging, registration) {
        // Stap 3: Genereer FCM token
        console.log('[Push] Requesting FCM token with VAPID key...');
        
        messaging.getToken({
            vapidKey: VAPID_KEY
        }).then(function(token) {
            if (token) {
                console.log('[Push] ✅ FCM token ontvangen:', token.substring(0, 30) + '...');
                // Stap 4: Stuur token naar server
                sendTokenToServer(token);
            } else {
                console.warn('[Push] Geen FCM token ontvangen (null)');
            }
        }).catch(function(err) {
            // err can be undefined — guard against property access on undefined
            var errMsg = (err && err.message) ? err.message : 'unknown error';
            var errCode = (err && err.code) ? err.code : 'UNKNOWN';
            console.warn('[Push] getToken failed:', errCode, errMsg);
            // Token will be retried on next page load; no point retrying with same params
        });
    }

    function sendTokenToServer(token) {
        if (fcmTokenSent) return;

        fetch(SUBSCRIBE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({ fcm_token: token })
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(result) {
            if (result.success) {
                console.log('[Push] Token opgeslagen op server');
                fcmTokenSent = true;
            } else {
                console.warn('[Push] Token opslaan faalde:', result.error || 'unknown');
            }
        })
        .catch(function(err) {
            console.warn('[Push] Token submit error:', err.message);
        });
    }

    function getCSRFToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // ════════════════════════════════════════════════════════════
    // BROWSER NOTIFICATIONS
    // ════════════════════════════════════════════════════════════

    function showBrowserNotification(title, body, icon, tag, url) {
        if (typeof Notification === 'undefined') return;
        if (Notification.permission !== 'granted') return;

        try {
            var notif = new Notification(title, {
                body: body,
                icon: icon || '/icons/favicon.png',
                badge: '/icons/favicon.png',
                tag: tag || 'regulr-notification',
                renotify: true,
                vibrate: [100, 50, 100],
                data: { url: url || (window.__BASE_URL || '') + '/inbox' }
            });

            notif.onclick = function() {
                window.focus();
                window.location.href = notif.data.url;
                notif.close();
            };
        } catch (e) {
            console.warn('[Push] Browser notification failed:', e.message);
            showInAppToast(title, body);
        }
    }

    // ════════════════════════════════════════════════════════════
    // POLLING FALLBACK
    // ════════════════════════════════════════════════════════════

    function checkNotifications() {
        fetch(POLL_URL, {
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(result) {
            if (!result.success || !result.data) return;

            var data = result.data;
            var unreadCount = data.unread_count || 0;
            var notifications = data.notifications || [];

            updateBadge(unreadCount);

            var newNotifs = [];
            for (var i = 0; i < notifications.length; i++) {
                var id = notifications[i].id;
                if (!lastSeenIds[id]) {
                    newNotifs.push(notifications[i]);
                    lastSeenIds[id] = true;
                }
            }

            if (newNotifs.length > 0) {
                console.log('[Push] ' + newNotifs.length + ' new notification(s) via poll');
                var n = newNotifs[0];
                var title = n.title || 'Nieuwe notificatie';
                var body = n.body || '';
                var extraCount = newNotifs.length - 1;
                if (extraCount > 0) {
                    body += ' (nog ' + extraCount + ' meer)';
                }
                showBrowserNotification(title, body, '/icons/favicon.png', 'poll-' + n.id);
            }

            lastUnreadCount = unreadCount;
        })
        .catch(function() {
            // Silent
        });
    }

    // ════════════════════════════════════════════════════════════
    // UNREAD BADGE
    // ════════════════════════════════════════════════════════════

    function updateBadge(count) {
        var badges = document.querySelectorAll('.notif-badge');
        for (var i = 0; i < badges.length; i++) {
            if (count > 0) {
                badges[i].textContent = count > 99 ? '99+' : count;
                badges[i].style.display = 'flex';
            } else {
                badges[i].style.display = 'none';
            }
        }
    }

    // ════════════════════════════════════════════════════════════
    // IN-APP TOAST
    // ════════════════════════════════════════════════════════════

    function showInAppToast(title, body) {
        var existing = document.getElementById('notif-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.id = 'notif-toast';
        toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;'
            + 'min-width:280px;max-width:380px;padding:16px 20px;'
            + 'background:rgba(30,30,30,0.95);border:1px solid rgba(76,175,80,0.5);'
            + 'border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.4);'
            + 'cursor:pointer;transform:translateX(120%);transition:transform 0.4s cubic-bezier(0.175,0.885,0.32,1.275);'
            + 'backdrop-filter:blur(10px);';

        toast.innerHTML = '<div style="display:flex;align-items:flex-start;gap:12px;">'
            + '<span style="font-size:24px;line-height:1;">📢</span>'
            + '<div style="flex:1;min-width:0;">'
            + '<p style="font-weight:600;font-size:14px;color:#fff;margin:0 0 4px 0;">' + escapeHtml(title) + '</p>'
            + '<p style="font-size:13px;color:rgba(255,255,255,0.65);margin:0;line-height:1.4;">' + escapeHtml(body) + '</p>'
            + '</div>'
            + '</div>';

        document.body.appendChild(toast);

        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                toast.style.transform = 'translateX(0)';
            });
        });

        toast.addEventListener('click', function() {
            toast.style.transform = 'translateX(120%)';
            setTimeout(function() { if (toast.parentNode) toast.remove(); }, 400);
            window.location.href = (window.__BASE_URL || '') + '/inbox';
        });

        setTimeout(function() {
            toast.style.transform = 'translateX(120%)';
            setTimeout(function() { if (toast.parentNode) toast.remove(); }, 400);
        }, TOAST_DURATION);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ════════════════════════════════════════════════════════════
    // POLLING CONTROLS
    // ════════════════════════════════════════════════════════════

    function startPolling() {
        if (polling) return;
        polling = true;
        console.log('[Push] Polling every ' + (POLL_INTERVAL / 1000) + 's');
        checkNotifications();
        pollTimer = setInterval(checkNotifications, POLL_INTERVAL);
    }

    function stopPolling() {
        polling = false;
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // ════════════════════════════════════════════════════════════
    // INIT
    // ════════════════════════════════════════════════════════════

    function init() {
        var path = window.location.pathname;
        var publicPages = ['/login', '/register', '/forgot-password', '/reset-password'];
        var isPublic = publicPages.some(function(p) { return path.indexOf(p) !== -1; });
        var isJoin = path.indexOf('/j/') !== -1;

        if (isPublic || isJoin) return;

        // Start FCM (push) + polling fallback
        initFCM();
        startPolling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Public API
    window.FCMHandler = {
        isPolling: true,
        check: checkNotifications,
        start: startPolling,
        stop: stopPolling,
        initFCM: initFCM
    };
})();
