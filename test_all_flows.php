<?php
declare(strict_types=1);

/**
 * STAMGAST — Full Flow Test Script
 * Tests ALL processes end-to-end in mock mode:
 *
 * 1. Database connectivity & seed data
 * 2. Authentication (password verification)
 * 3. Tenant management (create/read/update)
 * 4. Package/tier management (CRUD)
 * 5. Wallet deposit (mock mode — instant, no Mollie)
 * 6. POS payment / checkout (bartender scans QR, processes payment)
 * 7. Transaction history
 * 8. Platform fee calculation
 *
 * Run: php test_all_flows.php
 */

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo "║  STAMGAST — FULL FLOW TEST SUITE (Mock Mode)               ║\n";
echo "║  Tests: Deposit → Checkout → Packages → Tenant → Fees      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$passed = 0;
$failed = 0;
$errors = [];

function test(string $name, bool $result, string $detail = ''): void
{
    global $passed, $failed, $errors;
    if ($result) {
        $passed++;
        echo "  ✅ {$name}\n";
    } else {
        $failed++;
        $msg = "  ❌ {$name}" . ($detail ? " — {$detail}" : '');
        echo "{$msg}\n";
        $errors[] = $msg;
    }
}

function section(string $title): void
{
    echo "\n── {$title} " . str_repeat('─', max(1, 58 - strlen($title))) . "\n";
}

// ==========================================================================
// BOOTSTRAP
// ==========================================================================
section('BOOTSTRAP');

$root = __DIR__;
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/utils/helpers.php';

test('APP_ENV is development', APP_ENV === 'development');
test('APP_DEBUG is true', APP_DEBUG === true);
test('MOLLIE_MODE_DEFAULT is mock', MOLLIE_MODE_DEFAULT === 'mock');

// Database
try {
    $db = Database::getInstance()->getConnection();
    test('Database connection', true);
} catch (\Throwable $e) {
    test('Database connection', false, $e->getMessage());
    echo "\n  ⛔ Cannot continue without database. Aborting.\n";
    exit(1);
}

// Load models & services
require_once $root . '/models/Tenant.php';
require_once $root . '/models/User.php';
require_once $root . '/models/Wallet.php';
require_once $root . '/models/Transaction.php';
require_once $root . '/models/LoyaltyTier.php';
require_once $root . '/models/PlatformFee.php';
require_once $root . '/models/PlatformSetting.php';
require_once $root . '/utils/audit.php';
require_once $root . '/services/MollieService.php';
require_once $root . '/services/WalletService.php';
require_once $root . '/services/PaymentService.php';

// ==========================================================================
// 1. SEED DATA VERIFICATION
// ==========================================================================
section('1. SEED DATA VERIFICATION');

$tenantModel = new Tenant($db);
$tenant = $tenantModel->findById(1);
test('Tenant exists (id=1)', $tenant !== null, $tenant ? '' : 'Run seed.sql first');
test('Tenant mollie_status = mock', ($tenant['mollie_status'] ?? '') === 'mock');
test('Tenant mollie_connect_status = active', ($tenant['mollie_connect_status'] ?? '') === 'active');
test('Tenant has mollie_connect_id', !empty($tenant['mollie_connect_id']));

$userModel = new User($db);
$superadmin = $userModel->findByEmailGlobal('superadmin@stamgast.app');
test('Superadmin exists', $superadmin !== null);
test('Superadmin has NULL tenant_id', $superadmin === null || $superadmin['tenant_id'] === null);

$admin = $userModel->findByEmailGlobal('admin@stamgast.app');
test('Admin exists', $admin !== null);

$bartender = $userModel->findByEmailGlobal('bartender@stamgast.app');
test('Bartender exists', $bartender !== null);

$guest1 = $userModel->findByEmailGlobal('guest1@example.com');
test('Guest1 exists', $guest1 !== null);

// ==========================================================================
// 2. AUTHENTICATION (Password Verification)
// ==========================================================================
section('2. AUTHENTICATION');

test('Superadmin password "Admin123!" verifies',
    $superadmin && password_verify('Admin123!' . APP_PEPPER, $superadmin['password_hash']));
test('Admin password "Admin123!" verifies',
    $admin && password_verify('Admin123!' . APP_PEPPER, $admin['password_hash']));
test('Bartender password "Bar123!" verifies',
    $bartender && password_verify('Bar123!' . APP_PEPPER, $bartender['password_hash']));
test('Guest password "Guest123!" verifies',
    $guest1 && password_verify('Guest123!' . APP_PEPPER, $guest1['password_hash']));
test('Wrong password does NOT verify',
    $guest1 && !password_verify('wrongpassword' . APP_PEPPER, $guest1['password_hash']));

// ==========================================================================
// 3. WALLET BALANCE
// ==========================================================================
section('3. WALLET BALANCE');

$walletModel = new Wallet($db);

$guest1Wallet = $walletModel->findByUserAndTenant(4, 1);
test('Guest1 wallet exists', $guest1Wallet !== null);
test('Guest1 balance = 15000 cents (€150)', $guest1Wallet && (int)$guest1Wallet['balance_cents'] === 15000);

$guest2Wallet = $walletModel->findByUserAndTenant(5, 1);
test('Guest2 balance = 7500 cents (€75)', $guest2Wallet && (int)$guest2Wallet['balance_cents'] === 7500);

$guest3Wallet = $walletModel->findByUserAndTenant(6, 1);
test('Guest3 balance = 500 cents (€5)', $guest3Wallet && (int)$guest3Wallet['balance_cents'] === 500);

// ==========================================================================
// 4. LOYALTY TIERS / PACKAGES
// ==========================================================================
section('4. LOYALTY TIERS / PACKAGES');

$tierModel = new LoyaltyTier($db);
$tiers = $tierModel->getActiveByTenant(1);
test('Tenant has 4 active tiers', count($tiers) === 4, 'Found: ' . count($tiers));

if (count($tiers) >= 4) {
    test('Bronze topup = €50', $tiers[0]['topup_amount_cents'] === 5000);
    test('Silver topup = €100', $tiers[1]['topup_amount_cents'] === 10000);
    test('Gold topup = €250', $tiers[2]['topup_amount_cents'] === 25000);
    test('Platinum topup = €500', $tiers[3]['topup_amount_cents'] === 50000);
}

// Tier determination
$bronzeTier = $tierModel->determineTier(1, 0);
test('0 deposits → Bronze', $bronzeTier['name'] === 'Bronze');

$silverTier = $tierModel->determineTier(1, 10000);
test('10000 deposits → Silver', $silverTier['name'] === 'Silver');

$goldTier = $tierModel->determineTier(1, 50000);
test('50000 deposits → Gold', $goldTier['name'] === 'Gold');

$platinumTier = $tierModel->determineTier(1, 200000);
test('200000 deposits → Platinum', $platinumTier['name'] === 'Platinum');

// ==========================================================================
// 5. MOCK DEPOSIT FLOW (Opwaarderen)
// ==========================================================================
section('5. MOCK DEPOSIT FLOW (Opwaarderen)');

$walletService = new WalletService($db);

// Test: successful mock deposit
try {
    $result = $walletService->createDeposit(4, 1, 5000); // Guest1 deposits €50
    test('Mock deposit returns status=mock', ($result['status'] ?? '') === 'mock');
    test('Mock deposit returns checkout_url=null', $result['checkout_url'] === null);
    test('Mock deposit has payment_id with mock_ prefix', str_starts_with($result['payment_id'], 'mock_'));
    test('Mock deposit has transaction_id', $result['transaction_id'] > 0);
    test('Mock deposit fee_cents calculated', $result['fee_cents'] > 0);

    // Verify wallet was credited
    $updatedWallet = $walletModel->findByUserAndTenant(4, 1);
    test('Wallet credited: 15000 + 5000 = 20000', (int)$updatedWallet['balance_cents'] === 20000);
} catch (\Throwable $e) {
    test('Mock deposit succeeds', false, $e->getMessage());
}

// Test: deposit validation
try {
    $walletService->createDeposit(4, 1, 100); // Too low
    test('Deposit below minimum rejected', false, 'Should have thrown');
} catch (\InvalidArgumentException $e) {
    test('Deposit below minimum (€1) rejected', true);
}

try {
    $walletService->createDeposit(4, 1, 100000); // Too high
    test('Deposit above maximum rejected', false, 'Should have thrown');
} catch (\InvalidArgumentException $e) {
    test('Deposit above maximum (€1000) rejected', true);
}

// ==========================================================================
// 6. POS PAYMENT / CHECKOUT (Afrekenen)
// ==========================================================================
section('6. POS PAYMENT / CHECKOUT (Afrekenen)');

$paymentService = new PaymentService($db);

// Test: normal payment with Bronze tier (no discount)
try {
    $result = $paymentService->processPayment(4, 1, 3, 1000, 500); // €10 alcohol + €5 food
    test('Payment succeeds', $result['success'] === true);
    test('Payment has transaction_id', $result['transaction_id'] > 0);
    test('Final total = 1500 (no discount for Bronze)', $result['final_total'] === 1500);
    test('Discount applied = 0 (Bronze)', $result['discount_applied'] === 0);
    test('Points earned = 15 (1x multiplier)', $result['points_earned'] === 15);

    // Verify wallet was deducted
    $afterPayment = $walletModel->findByUserAndTenant(4, 1);
    test('Wallet deducted: 20000 - 1500 = 18500', (int)$afterPayment['balance_cents'] === 18500);
} catch (\Throwable $e) {
    test('POS payment succeeds', false, $e->getMessage());
}

// Test: insufficient balance
try {
    $paymentService->processPayment(6, 1, 3, 50000, 0); // Guest3 only has €5
    test('Insufficient balance rejected', false, 'Should have thrown');
} catch (\RuntimeException $e) {
    test('Insufficient balance rejected', str_contains($e->getMessage(), 'Onvoldoende'));
}

// Test: zero amounts
try {
    $paymentService->processPayment(4, 1, 3, 0, 0);
    test('Zero amounts rejected', false, 'Should have thrown');
} catch (\InvalidArgumentException $e) {
    test('Zero amounts rejected', true);
}

// Test: negative amounts
try {
    $paymentService->processPayment(4, 1, 3, -100, 0);
    test('Negative amounts rejected', false, 'Should have thrown');
} catch (\InvalidArgumentException $e) {
    test('Negative amounts rejected', true);
}

// ==========================================================================
// 7. DISCOUNT CALCULATION (Tier-based)
// ==========================================================================
section('7. DISCOUNT CALCULATION');

// First, give guest2 enough deposits to reach Silver tier
// Guest2 has 7500 cents balance. Let's deposit more to reach Silver (10000 total deposits needed)
// We need to add deposits. Let's do a mock deposit first.
try {
    $walletService->createDeposit(5, 1, 10000); // Guest2 deposits €100 → total deposits = 10000 → Silver

    // Now process a payment with Silver discount
    $result = $paymentService->processPayment(5, 1, 3, 2000, 1000); // €20 alcohol + €10 food
    test('Silver payment succeeds', $result['success'] === true);

    // Silver: 5% alcohol discount (capped at 25%), 5% food discount
    // Alcohol discount: 2000 * 5% = 100
    // Food discount: 1000 * 5% = 50
    // Final: (2000-100) + (1000-50) = 1900 + 950 = 2850
    test('Silver discount applied = 150', $result['discount_applied'] === 150);
    test('Silver final total = 2850', $result['final_total'] === 2850);
} catch (\Throwable $e) {
    test('Silver discount calculation', false, $e->getMessage());
}

// Test alcohol discount cap at 25% (Dutch law)
// We can't easily set a tier to >25% without modifying DB, so let's verify the constant
test('ALCOHOL_DISCOUNT_MAX = 25 (Dutch law)', ALCOHOL_DISCOUNT_MAX === 25);

// ==========================================================================
// 8. PLATFORM FEE CALCULATION
// ==========================================================================
section('8. PLATFORM FEE CALCULATION');

// Verify fee records were created for deposits
$feeModel = new PlatformFee($db);

// Find the fee for the first deposit (guest1, 5000 cents)
$fees = $feeModel->getByTenant(1);
test('Platform fee records exist', $fees['total'] > 0);

if ($fees['total'] > 0) {
    $firstFee = $fees['fees'][0];
    test('Fee has gross_amount_cents', (int)$firstFee['gross_amount_cents'] > 0);
    test('Fee has fee_percentage = 1.00', (float)$firstFee['fee_percentage'] === 1.00);
    test('Fee has fee_min_cents = 25', (int)$firstFee['fee_min_cents'] === 25);

    // For 5000 cents at 1%: 5000 * 0.01 = 50 cents (above min of 25)
    // In mock mode, fee_amount_cents should be the calculated value
    test('Mock fee_amount_cents = 50 (1% of 5000)', (int)$firstFee['fee_amount_cents'] === 50);
}

// ==========================================================================
// 9. TRANSACTION HISTORY
// ==========================================================================
section('9. TRANSACTION HISTORY');

$transactionModel = new Transaction($db);
$history = $transactionModel->getByUser(4, 1, 1, 20);
test('Guest1 has transaction history', count($history['transactions']) > 0);

// Should have: 1 deposit + 1 payment = 2 transactions
$txCount = count($history['transactions']);
test("Guest1 has 2 transactions (1 deposit + 1 payment)", $txCount === 2, "Found: {$txCount}");

// Check deposit totals
$totalDeposits = $transactionModel->getTotalDeposits(4, 1);
test('Guest1 total deposits = 5000', $totalDeposits === 5000, "Found: {$totalDeposits}");

// ==========================================================================
// 10. TENANT CREATION (Superadmin flow)
// ==========================================================================
section('10. TENANT CREATION');

$newTenantData = [
    'name'                  => 'Test Restaurant',
    'slug'                  => 'test-restaurant',
    'brand_color'           => '#2196F3',
    'secondary_color'       => '#4CAF50',
    'contact_email'         => 'owner@testrestaurant.nl',
    'contact_name'          => 'Henk Test',
    'mollie_status'         => 'mock',
    'platform_fee_percentage' => 1.50,
    'platform_fee_min_cents'  => 50,
];

try {
    $newTenantId = $tenantModel->create($newTenantData);
    test('New tenant created', $newTenantId > 0);

    $newTenant = $tenantModel->findById($newTenantId);
    test('New tenant has UUID', !empty($newTenant['uuid']));
    test('New tenant has secret_key', !empty($newTenant['secret_key']));
    test('New tenant mollie_status = mock', ($newTenant['mollie_status'] ?? '') === 'mock');
    test('New tenant mollie_connect_status = none (default)', ($newTenant['mollie_connect_status'] ?? '') === 'none');
    test('New tenant platform_fee = 1.50%', (float)$newTenant['platform_fee_percentage'] === 1.50);
    test('New tenant platform_fee_min = 50 cents', (int)$newTenant['platform_fee_min_cents'] === 50);
} catch (\Throwable $e) {
    test('Tenant creation', false, $e->getMessage());
}

// ==========================================================================
// 11. WALLET SERVICE — getBalance()
// ==========================================================================
section('11. WALLET SERVICE — getBalance()');

$balance = $walletService->getBalance(4, 1);
test('getBalance returns balance_cents', isset($balance['balance_cents']));
test('getBalance returns points_cents', isset($balance['points_cents']));
test('getBalance returns tier', isset($balance['tier']));
test('Guest1 tier is Bronze', ($balance['tier']['name'] ?? '') === 'Bronze');

// ==========================================================================
// 12. MOLLIESERVICE MOCK MODE
// ==========================================================================
section('12. MOLLIESERVICE MOCK MODE');

$mockMollie = new MollieService('', 'mock');
test('MollieService mock instantiation', true);

try {
    $payment = $mockMollie->createPayment(1000, 'Test', '/redirect', '/webhook', 'user1');
    test('Mock payment has mock_ prefix', str_starts_with($payment['payment_id'], 'mock_'));
    test('Mock payment has checkout_url', !empty($payment['checkout_url']));
} catch (\Throwable $e) {
    test('Mock payment creation', false, $e->getMessage());
}

try {
    $status = $mockMollie->getPaymentStatus('mock_test123');
    test('Mock status = paid', $status['status'] === 'paid');
    test('Mock isPaid returns true', $mockMollie->isPaid($status['status']));
} catch (\Throwable $e) {
    test('Mock payment status', false, $e->getMessage());
}

// ==========================================================================
// 13. PLATFORM SETTINGS
// ==========================================================================
section('13. PLATFORM SETTINGS');

$platformSetting = new PlatformSetting($db);
test('mollie_mode_default = mock', $platformSetting->get('mollie_mode_default') === 'mock');
test('mollie_connect_api_key is empty (mock mode)', $platformSetting->get('mollie_connect_api_key') === null || $platformSetting->get('mollie_connect_api_key') === '');

// ==========================================================================
// 14. CROSS-TENANT ISOLATION
// ==========================================================================
section('14. CROSS-TENANT ISOLATION');

// Guest1 belongs to tenant 1, should NOT see tenant 2's data
$guest1OnTenant2 = $walletModel->findByUserAndTenant(4, 2);
test('Guest1 has no wallet on tenant 2', $guest1OnTenant2 === null);

// ==========================================================================
// SUMMARY
// ==========================================================================
echo "\n╔══════════════════════════════════════════════════════════════╗\n";
$total = $passed + $failed;
echo "║  RESULTS: {$passed}/{$total} passed";
if ($failed > 0) {
    echo ", {$failed} FAILED";
}
echo str_repeat(' ', max(0, 33 - strlen("{$passed}/{$total} passed"))) . "║\n";

if ($failed > 0) {
    echo "║                                                              ║\n";
    echo "║  FAILED TESTS:                                               ║\n";
    foreach ($errors as $err) {
        $line = substr($err, 0, 58);
        echo "║  {$line}" . str_repeat(' ', max(0, 58 - strlen($line))) . "║\n";
    }
}

echo "╚══════════════════════════════════════════════════════════════╝\n\n";

exit($failed > 0 ? 1 : 0);
