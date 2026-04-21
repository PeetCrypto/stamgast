/**
 * STAMGAST - Bartender POS Interface
 * Scanner & Betaling Verwerking
 *
 * Used by the standalone /scan and /payment views (now redirect to /bartender).
 * Also serves as a library for the bartender dashboard if needed.
 */
(function() {
    'use strict';

    // Current scanned user
    let currentUser = null;
    let paymentProcessing = false;

    // Amount inputs
    let alcoholAmount = 0;
    let foodAmount = 0;

    // ============================================
    // QR VALIDATION (calls server API)
    // ============================================
    async function validateQR(qrPayload) {
        if (!qrPayload) return null;

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfToken = csrfMeta ? csrfMeta.content : '';

        const response = await fetch('/api/pos/scan', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: JSON.stringify({ qr_payload: qrPayload })
        });

        const data = await response.json();

        if (data.valid) {
            currentUser = data;
            return data;
        } else {
            throw new Error(data.error || 'Ongeldige QR code');
        }
    }

    // ============================================
    // PAYMENT PROCESSING
    // ============================================
    async function processPayment(userId, alcCents, foodCents) {
        if (paymentProcessing) return null;

        if (!userId || (alcCents <= 0 && foodCents <= 0)) {
            throw new Error('Geen geldige betalingsgegevens');
        }

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfToken = csrfMeta ? csrfMeta.content : '';

        paymentProcessing = true;

        try {
            const response = await fetch('/api/pos/process_payment', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    user_id: userId,
                    amount_alc_cents: alcCents,
                    amount_food_cents: foodCents
                })
            });

            const data = await response.json();

            if (data.success) {
                return data.data || data;
            } else {
                throw new Error(data.error || 'Betaling mislukt');
            }
        } finally {
            paymentProcessing = false;
        }
    }

    // ============================================
    // DISCOUNT CALCULATION (client preview only)
    // Server always recalculates authoritatively
    // ============================================
    function calculateDiscounts(alcCents, foodCents, tier) {
        const alcPerc = Math.min(tier?.alcohol_discount_perc || 0, 25);
        const foodPerc = tier?.food_discount_perc || 0;

        const discountAlc = Math.floor(alcCents * alcPerc / 100);
        const discountFood = Math.floor(foodCents * foodPerc / 100);

        return {
            discountAlc,
            discountFood,
            alcTotal: alcCents - discountAlc,
            foodTotal: foodCents - discountFood,
            finalTotal: (alcCents - discountAlc) + (foodCents - discountFood)
        };
    }

    // ============================================
    // EXPORTS
    // ============================================
    window.STAMGAST = window.STAMGAST || {};
    window.STAMGAST.pos = {
        validateQR: validateQR,
        processPayment: processPayment,
        calculateDiscounts: calculateDiscounts,
        getCurrentUser: function() { return currentUser; },
        reset: function() {
            currentUser = null;
            alcoholAmount = 0;
            foodAmount = 0;
            paymentProcessing = false;
        }
    };

})();
