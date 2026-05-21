<?php
declare(strict_types=1);
/**
 * Security Setup Page
 * Gast kiest beveiligingsmethode: PIN, FaceID/Vingerafdruk, of beide
 */

// Only accessible for logged-in guests
if (!isLoggedIn() || currentUserRole() !== 'guest') {
    redirect(getGuestLoginUrl());
}

$firstName = $_SESSION['first_name'] ?? 'Gast';
$tenantName = $_SESSION['tenant_name'] ?? APP_NAME;
$tenantSlug = $_SESSION['tenant']['slug'] ?? '';
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0f0f">
    <title>Beveilig je app — <?= sanitize($tenantName) ?></title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- App CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/midnight-lounge.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/views.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app-lock.css">

    <!-- CSRF -->
    <meta name="csrf-token" content="<?= generateCSRFToken() ?>">

    <style>
        :root {
            --accent-primary: <?= sanitize($_SESSION['brand_color'] ?? '#FFC107') ?>;
            --accent-secondary: <?= sanitize($_SESSION['secondary_color'] ?? '#FF9800') ?>;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: var(--space-lg);
        }

        .setup-container {
            text-align: center;
            max-width: 360px;
            width: 100%;
        }

        .setup-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: var(--space-xs);
        }

        .setup-subtitle {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: var(--space-xl);
            line-height: 1.5;
        }

        /* ===== Choice cards ===== */
        .choice-cards {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: var(--space-lg);
        }

        .choice-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: var(--space-md) var(--space-lg);
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.04);
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: left;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }

        .choice-card:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.2);
        }

        .choice-card:active {
            transform: scale(0.98);
        }

        .choice-card.selected {
            border-color: var(--accent-primary);
            background: rgba(255,193,7,0.08);
        }

        .choice-card__icon {
            font-size: 32px;
            flex-shrink: 0;
            width: 48px;
            text-align: center;
        }

        .choice-card__text {
            flex: 1;
        }

        .choice-card__title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
        }

        .choice-card__desc {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        /* ===== PIN flow ===== */
        .pin-flow {
            display: none;
        }

        .pin-step-label {
            font-size: 13px;
            color: var(--accent-primary);
            margin-bottom: var(--space-sm);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .pin-dots {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-bottom: var(--space-xl);
        }

        .pin-dot {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid var(--text-secondary);
            background: transparent;
            transition: all 0.2s ease;
        }

        .pin-dot.filled {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            transform: scale(1.15);
        }

        .pin-dot.error {
            border-color: #ef4444;
            background: #ef4444;
            animation: pin-shake 0.4s ease;
        }

        .pin-dot.success {
            border-color: #4CAF50;
            background: #4CAF50;
        }

        @keyframes pin-shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-8px); }
            40% { transform: translateX(8px); }
            60% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
        }

        .pin-keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            max-width: 280px;
            margin: 0 auto var(--space-lg);
        }

        .pin-key {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.05);
            color: var(--text-primary);
            font-size: 28px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            justify-self: center;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }

        .pin-key:active {
            background: rgba(255,255,255,0.15);
            transform: scale(0.95);
        }

        .pin-key.backspace {
            font-size: 20px;
            color: var(--text-secondary);
        }

        .pin-error {
            color: #ef4444;
            font-size: 14px;
            margin-top: var(--space-sm);
            min-height: 20px;
        }

        /* ===== WebAuthn standalone flow ===== */
        .webauthn-flow {
            display: none;
        }

        .webauthn-flow-btn {
            width: 100%;
            margin-top: var(--space-lg);
            padding: 20px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.15);
            background: rgba(255,255,255,0.06);
            color: var(--text-primary);
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .webauthn-flow-btn:active {
            background: rgba(255,255,255,0.12);
            transform: scale(0.98);
        }

        .webauthn-flow-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .webauthn-flow-btn .icon {
            font-size: 48px;
            display: block;
            margin-bottom: 8px;
        }

        .webauthn-error {
            color: #ef4444;
            font-size: 14px;
            margin-top: var(--space-md);
            min-height: 20px;
        }

        /* ===== Success state ===== */
        .success-flow {
            display: none;
        }

        .success-icon {
            font-size: 64px;
            margin-bottom: var(--space-md);
        }

        .success-title {
            font-size: 20px;
            font-weight: 700;
            color: #4CAF50;
            margin-bottom: var(--space-sm);
        }

        .success-desc {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: var(--space-xl);
        }

        .success-btn {
            display: inline-block;
            padding: 12px 32px;
            border-radius: 12px;
            background: var(--accent-primary);
            color: #000;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .success-btn:active {
            transform: scale(0.98);
        }

        /* ===== Continue / Back buttons ===== */
        .btn-continue {
            display: inline-block;
            margin-top: var(--space-md);
            padding: 12px 32px;
            border-radius: 12px;
            background: var(--accent-primary);
            color: #000;
            font-weight: 600;
            font-size: 15px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-continue:active { transform: scale(0.98); }
        .btn-continue:disabled { opacity: 0.4; cursor: not-allowed; }

        .btn-back {
            display: inline-block;
            margin-top: var(--space-sm);
            padding: 8px 16px;
            color: var(--text-secondary);
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            background: none;
            border: none;
        }

        .btn-back:hover { color: var(--text-primary); }

        /* ===== Skip link ===== */
        .skip-link {
            display: inline-block;
            margin-top: var(--space-lg);
            color: var(--text-secondary);
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        .skip-link:hover { color: var(--text-primary); }

        /* WebAuthn button after PIN in "both" flow */
        .webauthn-btn {
            display: none;
            width: 100%;
            margin-top: var(--space-md);
            padding: var(--space-md);
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.05);
            color: var(--text-primary);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .webauthn-btn:active { background: rgba(255,255,255,0.1); }
        .webauthn-btn .icon { font-size: 24px; display: block; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="setup-container">

        <!-- ============================================ -->
        <!-- PHASE 1: Choice screen                       -->
        <!-- ============================================ -->
        <div id="phase-choice">
            <div class="setup-title">🔒 Beveilig je app</div>
            <div class="setup-subtitle">
                Kies hoe je je app wilt beveiligen. Je app wordt altijd vergrendeld als je hem op de achtergrond zet.
            </div>

            <!-- FaceID warning: alleen zichtbaar als NIET in PWA/standalone mode -->
            <div id="faceid-pwa-warning" style="display:none;padding:var(--space-md);margin-bottom:var(--space-md);border-radius:12px;border:1px solid rgba(255,193,7,0.3);background:rgba(255,193,7,0.06);text-align:left;font-size:13px;color:var(--text-secondary);line-height:1.5;">
                <strong style="color:#FFC107;">💡 Tip:</strong> FaceID werkt het beste als je deze app op je thuisscherm zet. Zonder PWA kan een wachtwoordmanager verschijnen i.p.v. FaceID.
            </div>

            <div class="choice-cards">
                <!-- Optie A: PIN only -->
                <button type="button" class="choice-card" data-choice="pin">
                    <div class="choice-card__icon">🔢</div>
                    <div class="choice-card__text">
                        <div class="choice-card__title">PIN-code</div>
                        <div class="choice-card__desc">Ontgrendel met een 4-cijferige code</div>
                    </div>
                </button>

                <!-- Optie B: FaceID only -->
                <button type="button" class="choice-card" data-choice="faceid" id="choice-faceid">
                    <div class="choice-card__icon">👤</div>
                    <div class="choice-card__text">
                        <div class="choice-card__title">FaceID / Vingerafdruk</div>
                        <div class="choice-card__desc">Ontgrendel met biometrie van je toestel</div>
                    </div>
                </button>

                <!-- Optie C: Beide -->
                <button type="button" class="choice-card" data-choice="both">
                    <div class="choice-card__icon">🔒</div>
                    <div class="choice-card__text">
                        <div class="choice-card__title">PIN + FaceID</div>
                        <div class="choice-card__desc">Gebruik beide methodes, handig als backup</div>
                    </div>
                </button>
            </div>

            <a href="<?= BASE_URL ?>/dashboard" class="skip-link">Overslaan →</a>
        </div>

        <!-- ============================================ -->
        <!-- PHASE 2A: PIN setup flow                     -->
        <!-- ============================================ -->
        <div id="phase-pin" class="pin-flow">
            <div class="setup-title">🔢 PIN-code instellen</div>
            <div class="setup-subtitle">Kies een 4-cijferige PIN-code</div>

            <div class="pin-step-label" id="step-label">Stap 1 — Kies je PIN</div>

            <div class="pin-dots" id="pin-dots">
                <div class="pin-dot" data-index="0"></div>
                <div class="pin-dot" data-index="1"></div>
                <div class="pin-dot" data-index="2"></div>
                <div class="pin-dot" data-index="3"></div>
            </div>

            <div class="pin-error" id="pin-error"></div>

            <div class="pin-keypad" id="pin-keypad">
                <button class="pin-key" data-digit="1">1</button>
                <button class="pin-key" data-digit="2">2</button>
                <button class="pin-key" data-digit="3">3</button>
                <button class="pin-key" data-digit="4">4</button>
                <button class="pin-key" data-digit="5">5</button>
                <button class="pin-key" data-digit="6">6</button>
                <button class="pin-key" data-digit="7">7</button>
                <button class="pin-key" data-digit="8">8</button>
                <button class="pin-key" data-digit="9">9</button>
                <button class="pin-key" data-digit=""></button>
                <button class="pin-key" data-digit="0">0</button>
                <button class="pin-key backspace" data-digit="backspace">⌫</button>
            </div>

            <!-- Shown after PIN set in "both" flow -->
            <button type="button" class="webauthn-btn" id="webauthn-btn">
                <span class="icon">👤</span>
                FaceID / Vingerafdruk inschakelen
            </button>

            <!-- Shown after PIN set in "pin-only" flow -->
            <div id="pin-done-actions" style="display:none;">
                <a href="<?= BASE_URL ?>/dashboard" class="btn-continue">Ga naar dashboard</a>
            </div>

            <button type="button" class="btn-back" id="pin-back">← Terug</button>
        </div>

        <!-- ============================================ -->
        <!-- PHASE 2B: FaceID-only setup flow             -->
        <!-- ============================================ -->
        <div id="phase-faceid" class="webauthn-flow">
            <div class="setup-title">👤 FaceID / Vingerafdruk</div>
            <div class="setup-subtitle">
                Tik hieronder om je FaceID of vingerafdruk te registreren. Je toestel vraagt om je biometrie.
            </div>

            <button type="button" class="webauthn-flow-btn" id="faceid-register-btn">
                <span class="icon">👤</span>
                Registreer FaceID / Vingerafdruk
            </button>

            <div class="webauthn-error" id="faceid-error"></div>

            <button type="button" class="btn-back" id="faceid-back">← Terug</button>
        </div>

        <!-- ============================================ -->
        <!-- PHASE 3: Success                             -->
        <!-- ============================================ -->
        <div id="phase-success" class="success-flow">
            <div class="success-icon">✅</div>
            <div class="success-title">Beveiliging ingesteld!</div>
            <div class="success-desc" id="success-desc">Je app is nu beveiligd.</div>
            <a href="<?= BASE_URL ?>/dashboard" class="success-btn">Ga naar dashboard</a>
        </div>

    </div>

    <script>
        window.__BASE_URL = '<?= defined("BASE_URL") ? BASE_URL : "" ?>';
    </script>
    <script src="<?= BASE_URL ?>/js/pin-setup.js"></script>
</body>
</html>
