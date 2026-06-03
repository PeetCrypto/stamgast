<?php
declare(strict_types=1);

/**
 * Repair Script: Fix unprocessed Mollie deposits and platform fees
 * 
 * Run on production: visit /superadmin/repair-deposits (as superadmin)
 * 
 * This script:
 * 1. Finds all deposit transactions that have a Mollie payment ID
 * 2. Checks if the wallet was credited (via platform_fees.deposit_processed_at)
 * 3. If not credited, fetches payment status from Mollie and credits the wallet
 * 4. Updates platform fees with the correct amounts
 */

require_once __DIR__ . '/../../models/PlatformSetting.php';
require_once __DIR__ . '/../../models/Wallet.php';
require_once __DIR__ . '/../../models/PlatformFee.php';
require_once __DIR__ . '/../../models/LoyaltyTier.php';
require_once __DIR__ . '/../../services/MollieService.php';
require_once __DIR__ . '/../../services/WalletService.php';

$db = Database::getInstance()->getConnection();

// Get all deposit transactions with Mollie payment IDs that are NOT mock
$stmt = $db->query("
    SELECT t.*, pf.id AS fee_id, pf.deposit_processed_at, pf.fee_amount_cents, pf.gross_amount_cents
    FROM transactions t
    LEFT JOIN platform_fees pf ON pf.transaction_id = t.id
    WHERE t.type = 'deposit'
      AND t.mollie_payment_id IS NOT NULL
      AND t.mollie_payment_id NOT LIKE 'mock_%'
    ORDER BY t.created_at ASC
");
$deposits = $stmt->fetchAll();
?>

<div class="container" style="max-width:900px;margin:2rem auto;">
    <h2 style="margin-bottom:1rem;">🔧 Repair Mollie Deposits</h2>
    <p style="color:var(--text-secondary);margin-bottom:1.5rem;">
        Dit script herstelt openstaande Mollie stortingen die niet zijn verwerkt door de webhook.
    </p>

    <?php if (empty($deposits)): ?>
        <div class="card" style="padding:2rem;text-align:center;">
            <p>Geen echte Mollie stortingen gevonden.</p>
        </div>
    <?php else: ?>
        <p><strong><?= count($deposits) ?></strong> Mollie storting(en) gevonden</p>

        <?php
        $tenantModel = new Tenant($db);
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($deposits as $dep):
            $tid = $dep['id'];
            $paymentId = $dep['mollie_payment_id'];
            $amountCents = (int) $dep['final_total_cents'];
            $tenantId = (int) $dep['tenant_id'];
            $userId = (int) $dep['user_id'];
            $feeProcessed = $dep['deposit_processed_at'];
            $feeId = $dep['fee_id'];

            $tenant = $tenantModel->findById($tenantId);
            $tenantName = $tenant ? $tenant['name'] : 'Onbekend';
        ?>
            <div class="card" style="padding:1rem;margin-bottom:0.5rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <strong>#<?= $tid ?></strong> — <?= htmlspecialchars($tenantName) ?>
                        <br><small style="color:var(--text-secondary);">Payment: <?= $paymentId ?> | €<?= number_format($amountCents / 100, 2) ?></small>
                    </div>
                    <?php if ($feeProcessed !== null): ?>
                        <span style="color:#3fb950;">✅ Verwerkt</span>
                    <?php else: ?>
                        <?php
                        // Try to process
                        $result = '⏳';
                        $detail = '';
                        try {
                            $accessToken = $tenant['mollie_connect_access_token'] ?? '';
                            $mollieMode = $tenant['mollie_status'] ?? 'mock';

                            if (empty($accessToken)) {
                                throw new RuntimeException('Geen access token');
                            }

                            $mollie = new MollieService($accessToken, $mollieMode);
                            $status = $mollie->getPaymentStatus($paymentId);

                            if ($status['status'] !== 'paid') {
                                $result = '⏳';
                                $detail = 'Status: ' . $status['status'] . ' (niet betaald)';
                                $skipped++;
                            } else {
                                // Payment is paid — use processDeposit() for full bonus + fee handling
                                $walletService = new WalletService($db);

                                try {
                                    $processedOk = $walletService->processDeposit($paymentId, $amountCents);

                                    // Update platform fee amounts from Mollie
                                    if ($feeId) {
                                        $feeModel2 = new PlatformFee($db);
                                        $appFeeCents = (int) ($status['application_fee_cents'] ?? 0);
                                        $mollieFeeCents = (int) ($status['mollie_fee_cents'] ?? 0);
                                        $feeModel2->updateFeeFromMollie($feeId, $appFeeCents, $mollieFeeCents);
                                    }

                                    $result = '✅';
                                    $bonusLabel = $processedOk ? '' : ' (al verwerkt)';
                                    $detail = 'Wallet gecrediteerd' . $bonusLabel . ' | Fee: €' . number_format((int) ($status['application_fee_cents'] ?? 0) / 100, 2);
                                    $processed++;
                                } catch (Throwable $inner) {
                                    $result = '❌';
                                    $detail = $inner->getMessage();
                                    $failed++;
                                }
                            }
                        } catch (Throwable $e) {
                            $result = '❌';
                            $detail = $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . ']';
                            $failed++;
                        }
                        ?>
                        <span style="color:<?= $result === '✅' ? '#3fb950' : ($result === '❌' ? '#f44336' : '#d29922') ?>;">
                            <?= $result ?> <?= htmlspecialchars($detail) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card" style="padding:1.5rem;margin-top:1rem;background:var(--card-bg);">
            <h3>Samenvatting</h3>
            <p>Verwerkt: <strong><?= $processed ?></strong> | Overgeslagen: <strong><?= $skipped ?></strong> | Gefaald: <strong><?= $failed ?></strong></p>
        </div>
    <?php endif; ?>

    <div style="margin-top:2rem;">
        <a href="/superadmin" class="btn btn-secondary">← Terug naar dashboard</a>
    </div>
</div>
