<?php
/**
 * Fix Bonus Tiers — Run on any server (local or production)
 * Updates ALL loyalty tiers to bonus model based on their topup_amount_cents.
 * Works regardless of tier names — matches by deposit amount.
 *
 * Access: /fix-bonus-tiers (superadmin only)
 * Or CLI: php sql/fix_bonus_tiers.php
 */
require_once __DIR__ . '/config/load_env.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
        http_response_code(403);
        die('Alleen superadmin');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$db = Database::getInstance()->getConnection();

// Bonus mapping by topup amount (cents)
// key = topup_amount_cents, value = bonus_cents
$bonusMap = [
    5000  => 500,    // €50  → €5 bonus
    10000 => 1000,   // €100 → €10 bonus
    15000 => 2000,   // €150 → €20 bonus
    20000 => 3000,   // €200 → €30 bonus
    25000 => 3750,   // €250 → €37.50 bonus
    50000 => 7500,   // €500 → €75 bonus
];

echo "=== BONUS TIERS FIX ===\n\n";

// Get all tiers grouped by tenant
$stmt = $db->query('SELECT t.id as tenant_id, t.name as tenant_name FROM tenants t ORDER BY t.id');
$tenants = $stmt->fetchAll();

foreach ($tenants as $tenant) {
    $tid = (int) $tenant['tenant_id'];
    echo "--- Tenant {$tid}: {$tenant['tenant_name']} ---\n";

    $stmt2 = $db->prepare('SELECT id, name, topup_amount_cents, model_type, bonus_cents FROM loyalty_tiers WHERE tenant_id = :tid ORDER BY sort_order');
    $stmt2->execute([':tid' => $tid]);
    $tiers = $stmt2->fetchAll();

    if (empty($tiers)) {
        echo "  Geen tiers gevonden — overgeslagen\n\n";
        continue;
    }

    foreach ($tiers as $tier) {
        $topup = (int) $tier['topup_amount_cents'];
        $bonus = $bonusMap[$topup] ?? 0;
        $currentModel = $tier['model_type'];
        $currentBonus = (int) $tier['bonus_cents'];

        if ($bonus > 0 && ($currentModel !== 'bonus' || $currentBonus !== $bonus)) {
            $stmt3 = $db->prepare('UPDATE loyalty_tiers SET model_type = :model, bonus_cents = :bonus, bonus_percentage = 0.00 WHERE id = :id');
            $stmt3->execute([
                ':model' => 'bonus',
                ':bonus' => $bonus,
                ':id'    => $tier['id'],
            ]);
            echo "  ✓ {$tier['name']} (€" . number_format($topup/100, 0) . "): model_type='{$currentModel}'→'bonus', bonus_cents={$currentBonus}→{$bonus}\n";
        } elseif ($bonus > 0) {
            echo "  ✓ {$tier['name']} (€" . number_format($topup/100, 0) . "): al correct (bonus €" . number_format($bonus/100, 2) . ")\n";
        } else {
            echo "  - {$tier['name']} (€" . number_format($topup/100, 0) . "): geen bonus gedefinieerd voor dit bedrag — overgeslagen\n";
        }
    }
    echo "\n";
}

echo "=== VERIFICATIE ===\n\n";
$stmt = $db->query('
    SELECT t.name as tenant_name, lt.name as tier_name, lt.topup_amount_cents, lt.model_type, lt.bonus_cents
    FROM loyalty_tiers lt
    JOIN tenants t ON t.id = lt.tenant_id
    ORDER BY lt.tenant_id, lt.sort_order
');
foreach ($stmt->fetchAll() as $r) {
    $status = ($r['model_type'] === 'bonus' && (int)$r['bonus_cents'] > 0) ? '✓' : '✗';
    echo "  {$status} {$r['tenant_name']} | {$r['tier_name']} | €" . number_format($r['topup_amount_cents']/100, 0) . " | {$r['model_type']} | bonus: €" . number_format($r['bonus_cents']/100, 2) . "\n";
}

echo "\nKlaar!\n";
