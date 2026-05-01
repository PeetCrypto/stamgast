/**
 * REGULR.vip - Push Notification Handler
 * Web Push API met VAPID keys
 *
 * Functionaliteit:
 * - Push abonnement registreren/verwijderen
 * - Notificaties ontvangen en weergeven
 * - Permission state management
 */
(function() {
    'use strict';

    // ============================================
    // CONFIGURATION
    // ============================================
    const PUSH_CONFIG = {
        // VAPID public key — moet overeenkomen met server-side private key
        // In productie: genereer met web-push library en sla op in config/app.php
        VAPID_PUBLIC_KEY: '',
        // Interval voor het controleren van push permission status
        PERMISSION_CHECK_INTERVAL: 60000,
    };

    // ============================================
    // STATE
    // ============================================
    let isSubscribed = false;
    let swRegistration = null;

    // ============================================
    // UTILITY: URL-safe Base64 decode
    // ============================================
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/\-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    // ============================================
    // PERMISSION MANAGEMENT
    // ============================================

    /**
     * Get current push permission state
     * @returns {'granted'|'denied'|'default'|'unsupported'}
     */
    function getPermissionState() {
        if (!('Notification' in window)) {
            return 'unsupported';
        }
        return Notification.permission;
    }

    /**
     * Check if push is supported and VAPID key is configured
     */
    function isPushSupported() {
        return 'PushManager' in window && 'serviceWorker' in navigator && PUSH_CONFIG.VAPID_PUBLIC_KEY !== '';
    }

    /**
     * Request notification permission from user
     * @returns {Promise<string>} permission state
     */
    async function requestPermission() {
        if (!('Notification' in window)) {
            console.warn('[Push] Notifications not supported');
            return 'unsupported';
        }

        if (Notification.permission === 'granted') {
            return 'granted';
        }

        if (Notification.permission === 'denied') {
            console.warn('[Push] Permission denied by user');
            return 'denied';
        }

        const permission = await Notification.requestPermission();
        return permission;
    }

    // ============================================
    // SUBSCRIPTION MANAGEMENT
    // ============================================

    /**
     * Subscribe user to push notifications
     * Registers with push server and sends subscription to backend
     */
    async function subscribe() {
        if (!isPushSupported()) {
            if (window.REGULR?.showError) {
                window.REGULR.showError('Push notificaties worden niet ondersteund door deze browser');
            }
            return false;
        }

        try {
            const permission = await requestPermission();
            if (permission !== 'granted') {
                if (window.REGULR?.showError) {
                    window.REGULR.showError('Push notificaties zijn uitgeschakeld. Controleer je browser instellingen.');
                }
                return false;
            }

            // Get service worker registration
            swRegistration = swRegistration || await navigator.serviceWorker.ready;

            // Check existing subscription
            let subscription = await swRegistration.pushManager.getSubscription();

            if (!subscription) {
                // Check if VAPID key is available
                if (!PUSH_CONFIG.VAPID_PUBLIC_KEY) {
                    console.warn('[Push] No VAPID public key configured');
                    if (window.REGULR?.showError) {
                        window.REGULR.showError('Push notificaties zijn nog niet geconfigureerd door de beheerder.');
                    }
                    return false;
                }

                const applicationServerKey = urlBase64ToUint8Array(PUSH_CONFIG.VAPID_PUBLIC_KEY);
                subscription = await swRegistration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: applicationServerKey,
                });
            }

            // Send subscription to backend
            const success = await sendSubscriptionToServer(subscription);

            if (success) {
                isSubscribed = true;
                updatePushUI(true);
                if (window.REGULR?.showSuccess) {
                    window.REGULR.showSuccess('Push notificaties ingeschakeld!');
                }
            }

            return success;
        } catch (error) {
            console.error('[Push] Subscribe error:', error);
            if (window.REGULR?.showError) {
                window.REGULR.showError('Kon push notificaties niet inschakelen: ' + error.message);
            }
            return false;
        }
    }

    /**
     * Unsubscribe user from push notifications
     */
    async function unsubscribe() {
        try {
            swRegistration = swRegistration || await navigator.serviceWorker.ready;
            const subscription = await swRegistration.pushManager.getSubscription();

            if (subscription) {
                await subscription.unsubscribe();
                await removeSubscriptionFromServer(subscription);
            }

            isSubscribed = false;
            updatePushUI(false);

            if (window.REGULR?.showSuccess) {
                window.REGULR.showSuccess('Push notificaties uitgeschakeld');
            }
            return true;
        } catch (error) {
            console.error('[Push] Unsubscribe error:', error);
            return false;
        }
    }

    /**
     * Toggle push subscription state
     */
    async function toggleSubscription() {
        if (isSubscribed) {
            return await unsubscribe();
        } else {
            return await subscribe();
        }
    }

    // ============================================
    // SERVER COMMUNICATION
    // ============================================

    /**
     * Send push subscription to backend
     * @param {PushSubscription} subscription
     * @returns {Promise<boolean>}
     */
    async function sendSubscriptionToServer(subscription) {
        try {
            const data = {
                endpoint: subscription.endpoint,
                p256dh: subscription.getKey('p256dh')
                    ? btoa(String.fromCharCode.apply(null, new Uint8Array(subscription.getKey('p256dh'))))
                    : '',
                auth: subscription.getKey('auth')
                    ? btoa(String.fromCharCode.apply(null, new Uint8Array(subscription.getKey('auth'))))
                    : '',
            };

            if (window.REGULR?.api) {
                const response = await window.REGULR.api('/push/subscribe', {
                    method: 'POST',
                    body: data,
                });
                return response.success === true;
            }

            // Fallback: direct fetch
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const response = await fetch((window.__BASE_URL || '') + '/api/push/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify(data),
            });

            const result = await response.json();
            return result.success === true;
        } catch (error) {
            console.error('[Push] Server subscription error:', error);
            return false;
        }
    }

    /**
     * Remove push subscription from backend
     * @param {PushSubscription} subscription
     */
    async function removeSubscriptionFromServer(subscription) {
        try {
            if (window.REGULR?.api) {
                await window.REGULR.api('/push/subscribe', {
                    method: 'DELETE',
                    body: { endpoint: subscription.endpoint },
                });
            }
        } catch (error) {
            console.warn('[Push] Could not remove subscription from server:', error);
        }
    }

    // ============================================
    // UI MANAGEMENT
    // ============================================

    /**
     * Update push-related UI elements
     * @param {boolean} subscribed
     */
    function updatePushUI(subscribed) {
        // Update toggle buttons
        const pushToggle = document.getElementById('push-toggle');
        if (pushToggle) {
            pushToggle.checked = subscribed;
        }

        // Update status text
        const pushStatus = document.getElementById('push-status');
        if (pushStatus) {
            pushStatus.textContent = subscribed ? 'Ingeschakeld' : 'Uitgeschakeld';
            pushStatus.style.color = subscribed ? '#4CAF50' : 'rgba(255,255,255,0.5)';
        }

        // Update subscribe/unsubscribe buttons
        const subscribeBtn = document.getElementById('push-subscribe-btn');
        const unsubscribeBtn = document.getElementById('push-unsubscribe-btn');

        if (subscribeBtn) {
            subscribeBtn.style.display = subscribed ? 'none' : 'inline-flex';
        }
        if (unsubscribeBtn) {
            unsubscribeBtn.style.display = subscribed ? 'inline-flex' : 'none';
        }
    }

    /**
     * Show in-app notification (fallback when push is not available)
     * @param {string} title
     * @param {string} body
     * @param {string} [icon]
     */
    function showLocalNotification(title, body, icon) {
        if (getPermissionState() === 'granted') {
            // Use service worker for better notification display
            if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                navigator.serviceWorker.ready.then(registration => {
                    registration.showNotification(title, {
                        body: body,
                        icon: icon || '/icons/favicon.png',
                        badge: '/icons/favicon.png',
                        vibrate: [100, 50, 100],
                    });
                });
            } else {
                // Fallback to basic notification
                new Notification(title, {
                    body: body,
                    icon: icon || '/icons/favicon.png',
                });
            }
        }
    }

    // ============================================
    // INITIALIZATION
    // ============================================

    /**
     * Initialize push notification system
     * Checks current subscription state and sets up UI
     */
    async function initPush() {
        if (!('serviceWorker' in navigator)) {
            console.warn('[Push] Service Worker not supported');
            return;
        }

        try {
            swRegistration = await navigator.serviceWorker.ready;

            // Check current subscription state
            const subscription = await swRegistration.pushManager.getSubscription();
            isSubscribed = subscription !== null;

            // Update UI to reflect current state
            updatePushUI(isSubscribed);

            // Setup event listeners for push toggle
            setupPushEventListeners();

            console.log('[Push] Initialized, subscribed:', isSubscribed);
        } catch (error) {
            console.error('[Push] Init error:', error);
        }
    }

    /**
     * Setup event listeners for push UI elements
     */
    function setupPushEventListeners() {
        // Push toggle checkbox (settings page)
        const pushToggle = document.getElementById('push-toggle');
        if (pushToggle) {
            pushToggle.addEventListener('change', async (e) => {
                e.preventDefault();
                // Reset checkbox state until operation completes
                pushToggle.checked = isSubscribed;
                await toggleSubscription();
            });
        }

        // Subscribe button
        const subscribeBtn = document.getElementById('push-subscribe-btn');
        if (subscribeBtn) {
            subscribeBtn.addEventListener('click', async () => {
                subscribeBtn.disabled = true;
                await subscribe();
                subscribeBtn.disabled = false;
            });
        }

        // Unsubscribe button
        const unsubscribeBtn = document.getElementById('push-unsubscribe-btn');
        if (unsubscribeBtn) {
            unsubscribeBtn.addEventListener('click', async () => {
                unsubscribeBtn.disabled = true;
                await unsubscribe();
                unsubscribeBtn.disabled = false;
            });
        }
    }

    // ============================================
    // LISTEN FOR MESSAGES FROM SERVICE WORKER
    // ============================================
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'PUSH_NOTIFICATION') {
                const { title, body } = event.data;
                if (window.REGULR?.showSuccess) {
                    window.REGULR.showSuccess(body || title, 8000);
                }
            }
        });
    }

    // ============================================
    // EXPORTS
    // ============================================
    window.REGULR = window.REGULR || {};
    window.REGULR.push = {
        init: initPush,
        subscribe: subscribe,
        unsubscribe: unsubscribe,
        toggle: toggleSubscription,
        isSubscribed: () => isSubscribed,
        isSupported: isPushSupported,
        getPermissionState: getPermissionState,
        showLocal: showLocalNotification,
    };

    // Auto-init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPush);
    } else {
        initPush();
    }

})();
