<?php
declare(strict_types=1);

/**
 * Admin Dashboard API
 * GET /api/admin/dashboard
 * Returns tenant-level statistics for the admin dashboard
 */

$tenantId = currentTenantId();
if (!$tenantId) {
    Response::error('Geen tenant gevonden', 'NO_TENANT', 400);
}

// Instantiate models
$tenantModel  = new Tenant($db);
$userModel    = new User($db);
$walletModel  = new Wallet($db);
$tierModel    = new LoyaltyTier($db);
$txModel      = new Transaction($db);

// Revenue stats (today, this week, total payments)
$revenue = $txModel->getRevenueStats($tenantId);

// User counts
$totalUsers = $userModel->countByTenant($tenantId);

// Active tiers
$tiers = $tierModel->getByTenant($tenantId);
$activeTiers = count($tiers);

// Revenue per day for the last 7 days (for chart)
$stmt = $db->prepare(
    "SELECT DATE(`created_at`) AS day, COALESCE(SUM(`final_total_cents`), 0) AS total
     FROM `transactions`
     WHERE `tenant_id` = :tid
       AND `type` = 'payment'
       AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(`created_at`)
     ORDER BY `day` ASC"
);
$stmt->execute([':tid' => $tenantId]);
$revenueRows = $stmt->fetchAll();

// Build chart data with day labels (Mon, Tue, etc.)
$revenueChart = [];
$dutchDays = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $dayLabel = $dutchDays[(int) date('w', strtotime($date))];
    $found = false;
    foreach ($revenueRows as $row) {
        if ($row['day'] === $date) {
            $revenueChart[$dayLabel] = (int) $row['total'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $revenueChart[$dayLabel] = 0;
    }
}

// Top spenders (top 5 users by total payment amount)
$stmt = $db->prepare(
    "SELECT u.`id`, u.`first_name`, u.`last_name`,
            COALESCE(SUM(t.`final_total_cents`), 0) AS total_spent
     FROM `users` u
     LEFT JOIN `transactions` t ON t.`user_id` = u.`id` AND t.`type` = 'payment' AND t.`tenant_id` = :tid
     WHERE u.`tenant_id` = :tid AND u.`role` = 'guest'
     GROUP BY u.`id`
     ORDER BY `total_spent` DESC
     LIMIT 5"
);
$stmt->execute([':tid' => $tenantId]);
$topUsers = $stmt->fetchAll();

// Total deposits
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(`final_total_cents`), 0)
     FROM `transactions`
     WHERE `tenant_id` = :tid AND `type` = 'deposit'"
);
$stmt->execute([':tid' => $tenantId]);
$totalDeposits = (int) $stmt->fetchColumn();

Response::success([
    'revenue_today'  => $revenue['today'],
    'revenue_week'   => $revenue['week'],
    'revenue_total'  => $revenue['total'],
    'total_deposits' => $totalDeposits,
    'total_users'    => $totalUsers,
    'active_tiers'   => $activeTiers,
    'revenue'        => $revenueChart,
    'top_users'      => array_map(function ($u) {
        return [
            'id'          => (int) $u['id'],
            'first_name'  => $u['first_name'],
            'last_name'   => $u['last_name'],
            'total_spent' => (int) $u['total_spent'],
        ];
    }, $topUsers),
]);
