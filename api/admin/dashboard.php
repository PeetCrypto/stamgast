<?php
declare(strict_types=1);

/**
 * Admin Dashboard API - Uitgebreide Versie
 * GET /api/admin/dashboard
 * Returns tenant-level statistics for the admin dashboard
 * 
 * Features:
 * - Live Monitor (actieve gasten, gemiddelde besteding, piektijden)
 * - Whale Tracker (top spenders 30 dagen)
 * - Saldo & Liquiditeit (uitstaand saldo, burn rate)
 * - Marketing Performance (verjaardagen, bonus effect)
 * - Personeelscontrole (omzet per barman, correctie-log)
 */

$db = Database::getInstance()->getConnection();
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

// ==========================================
// 1. REVENUE STATS (basis)
// ==========================================
$revenue = $txModel->getRevenueStats($tenantId);

// ==========================================
// 2. USER COUNTS
// ==========================================
$totalUsers = $userModel->countByTenant($tenantId);

// ==========================================
// 3. ACTIVE TIERS
// ==========================================
$tiers = $tierModel->getByTenant($tenantId);
$activeTiers = count($tiers);

// ==========================================
// 4. REVENUE PER DAY (last 7 days - chart)
// ==========================================
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

// ==========================================
// 5. TOP SPENDERS (all time)
// ==========================================
$stmt = $db->prepare(
    "SELECT u.`id`, u.`first_name`, u.`last_name`, u.`photo_url`,
            COALESCE(SUM(t.`final_total_cents`), 0) AS total_spent
     FROM `users` u
     LEFT JOIN `transactions` t ON t.`user_id` = u.`id` AND t.`type` = 'payment' AND t.`tenant_id` = :tid1
     WHERE u.`tenant_id` = :tid2 AND u.`role` = 'guest'
     GROUP BY u.`id`
     ORDER BY `total_spent` DESC
     LIMIT 5"
);
$stmt->execute([':tid1' => $tenantId, ':tid2' => $tenantId]);
$topUsers = $stmt->fetchAll();

// ==========================================
// 6. TOTAL DEPOSITS
// ==========================================
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(`final_total_cents`), 0)
     FROM `transactions`
     WHERE `tenant_id` = :tid AND `type` = 'deposit'"
);
$stmt->execute([':tid' => $tenantId]);
$totalDeposits = (int) $stmt->fetchColumn();

// ==========================================
// ==========================================
// LIVE MONITOR - NIEUWE METRICS
// ==========================================
// ==========================================

// ------------------------------------------
// 6a. Actieve Gasten Vandaag (unieke scans)
// ------------------------------------------
$stmt = $db->prepare(
    "SELECT COUNT(DISTINCT `user_id`) AS active_guests
     FROM `transactions`
     WHERE `tenant_id` = :tid 
       AND `type` = 'payment'
       AND DATE(`created_at`) = CURDATE()"
);
$stmt->execute([':tid' => $tenantId]);
$activeGuestsToday = (int) $stmt->fetchColumn();

// ------------------------------------------
// 6b. Gemiddelde Besteding per Gast
// ------------------------------------------
$stmt = $db->prepare(
    "SELECT 
        COUNT(DISTINCT `user_id`) AS total_guests,
        COALESCE(AVG(total_per_guest), 0) AS avg_per_guest
     FROM (
        SELECT `user_id`, SUM(`final_total_cents`) AS total_per_guest
        FROM `transactions`
        WHERE `tenant_id` = :tid AND `type` = 'payment'
        GROUP BY `user_id`
     ) AS guest_totals"
);
$stmt->execute([':tid' => $tenantId]);
$spendingData = $stmt->fetch();
$avgSpendingPerGuest = (int) $spendingData['avg_per_guest'];

// ------------------------------------------
// 6c. Piektijden (uur van de dag)
// ------------------------------------------
$stmt = $db->prepare(
    "SELECT HOUR(`created_at`) AS hour, COALESCE(SUM(`final_total_cents`), 0) AS total
     FROM `transactions`
     WHERE `tenant_id` = :tid 
       AND `type` = 'payment'
       AND `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY HOUR(`created_at`)
     ORDER BY `hour` ASC"
);
$stmt->execute([':tid' => $tenantId]);
$hourlyData = $stmt->fetchAll();

// Build hourly chart data (0-23)
$peakHours = [];
for ($h = 0; $h < 24; $h++) {
    $peakHours[$h] = 0;
}
foreach ($hourlyData as $row) {
    $hour = (int) $row['hour'];
    $peakHours[$hour] = (int) $row['total'];
}

// ==========================================
// WHALE TRACKER - Top 30 dagen
// ==========================================

$stmt = $db->prepare(
    "SELECT u.`id`, u.`first_name`, u.`last_name`, u.`photo_url`, u.`push_token`,
            COALESCE(SUM(t.`final_total_cents`), 0) AS total_spent_30d,
            COUNT(t.`id`) AS visits_30d
     FROM `users` u
     LEFT JOIN `transactions` t ON t.`user_id` = u.`id` 
        AND t.`type` = 'payment' 
        AND t.`tenant_id` = :tid1
        AND t.`created_at` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     WHERE u.`tenant_id` = :tid2 AND u.`role` = 'guest'
     GROUP BY u.`id`
     HAVING total_spent_30d > 0
     ORDER BY `total_spent_30d` DESC
     LIMIT 5"
);
$stmt->execute([':tid1' => $tenantId, ':tid2' => $tenantId]);
$whales = $stmt->fetchAll();

// ==========================================
// SALDO & LIQUIDITEIT
// ==========================================

// ------------------------------------------
// Totaal Uitstaand Saldo (alle wallets)
// ------------------------------------------
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(w.`balance_cents`), 0) 
     FROM `wallets` w
     JOIN `users` u ON w.`user_id` = u.`id`
     WHERE w.`tenant_id` = :tid AND u.`role` != 'superadmin'"
);
$stmt->execute([':tid' => $tenantId]);
$totalOutstandingBalance = (int) $stmt->fetchColumn();

// ------------------------------------------
// Burn Rate (hoeveel gestort geld wordt uitgegeven)
// ------------------------------------------
$stmt = $db->prepare(
    "SELECT 
        COALESCE(SUM(CASE WHEN `type` = 'deposit' THEN `final_total_cents` ELSE 0 END), 0) AS total_deposited,
        COALESCE(SUM(CASE WHEN `type` = 'payment' THEN `final_total_cents` ELSE 0 END), 0) AS total_spent
     FROM `transactions`
     WHERE `tenant_id` = :tid
       AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
);
$stmt->execute([':tid' => $tenantId]);
$burnData = $stmt->fetch();
$totalDeposited30d = (int) $burnData['total_deposited'];
$totalSpent30d = (int) $burnData['total_spent'];

// Burn rate percentage
$burnRate = $totalDeposited30d > 0 ? round(($totalSpent30d / $totalDeposited30d) * 100, 1) : 0;

// ==========================================
// MARKETING PERFORMANCE
// ==========================================

// ------------------------------------------
// Verjaardagen deze week
// ------------------------------------------
$stmt = $db->prepare(
    "SELECT `id`, `first_name`, `last_name`, `birthdate`
     FROM `users`
     WHERE `tenant_id` = :tid 
       AND `role` = 'guest'
       AND `birthdate` IS NOT NULL
       AND WEEK(`birthdate`, 1) = WEEK(CURDATE(), 1)
       AND MONTH(`birthdate`) = MONTH(CURDATE())
     ORDER BY DAY(`birthdate`) ASC"
);
$stmt->execute([':tid' => $tenantId]);
$birthdaysThisWeek = $stmt->fetchAll();

// ------------------------------------------
// Dinsdag-bonus effect (vergelijk dinsdag met andere dagen)
// ------------------------------------------
$stmt = $db->prepare(
    "SELECT 
        DAYNAME(`created_at`) AS day_name,
        COUNT(*) AS transaction_count,
        COALESCE(SUM(`final_total_cents`), 0) AS total
     FROM `transactions`
     WHERE `tenant_id` = :tid 
       AND `type` = 'payment'
       AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
     GROUP BY DAYNAME(`created_at`)
     ORDER BY `total` DESC"
);
$stmt->execute([':tid' => $tenantId]);
$dayPerformance = $stmt->fetchAll();

// Check if Tuesday had special bonus
$stmt = $db->prepare(
    "SELECT 
        COALESCE(SUM(`final_total_cents`), 0) AS tuesday_total,
        COUNT(*) AS tuesday_count
     FROM `transactions`
     WHERE `tenant_id` = :tid 
       AND `type` = 'payment'
       AND DAYNAME(`created_at`) = 'Tuesday'
       AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)"
);
$stmt->execute([':tid' => $tenantId]);
$tuesdayData = $stmt->fetch();
$tuesdayBonusEffect = [
    'total' => (int) $tuesdayData['tuesday_total'],
    'count' => (int) $tuesdayData['tuesday_count']
];

// ==========================================
// PERSONEELSCONTROLE (Anti-Fraude)
// ==========================================

// ------------------------------------------
// Omzet per Barman
// ------------------------------------------
$stmt = $db->prepare(
    "SELECT u.`id`, u.`first_name`, u.`last_name`,
            COUNT(t.`id`) AS transaction_count,
            COALESCE(SUM(t.`final_total_cents`), 0) AS total_revenue
     FROM `users` u
     LEFT JOIN `transactions` t ON t.`bartender_id` = u.`id` AND t.`type` = 'payment' AND t.`tenant_id` = :tid1
     WHERE u.`tenant_id` = :tid2 AND u.`role` = 'bartender'
     GROUP BY u.`id`
     ORDER BY `total_revenue` DESC"
);
$stmt->execute([':tid1' => $tenantId, ':tid2' => $tenantId]);
$bartenderStats = $stmt->fetchAll();

// ------------------------------------------
// Correctie-log (rode vlaggen)
// ------------------------------------------
$stmt = $db->prepare(
    "SELECT t.`id`, t.`user_id`, t.`bartender_id`, t.`description`, 
            t.`final_total_cents`, t.`created_at`,
            u_guest.`first_name` AS guest_first_name,
            u_guest.`last_name` AS guest_last_name,
            u_bartender.`first_name` AS bartender_first_name
     FROM `transactions` t
     LEFT JOIN `users` u_guest ON u_guest.`id` = t.`user_id`
     LEFT JOIN `users` u_bartender ON u_bartender.`id` = t.`bartender_id`
     WHERE t.`tenant_id` = :tid 
       AND (t.`type` = 'correction' OR t.`description` LIKE '%correctie%' OR t.`description` LIKE '%annul%')
       AND t.`created_at` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     ORDER BY t.`created_at` DESC
     LIMIT 10"
);
$stmt->execute([':tid' => $tenantId]);
$correctionLog = $stmt->fetchAll();

// ==========================================
// PUSH GESCHIEDENIS (recente 7 dagen)
// ==========================================
$stmt = $db->prepare(
    "SELECT `action`, `metadata`, `created_at`
     FROM `audit_log`
     WHERE `tenant_id` = :tid
       AND `action` LIKE 'push.%'
       AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     ORDER BY `created_at` DESC
     LIMIT 20"
);
$stmt->execute([':tid' => $tenantId]);
$recentPush = $stmt->fetchAll();

// Push stats (7dagen)
$pushSent7d = 0;
$pushFailed7d = 0;
foreach ($recentPush as $entry) {
    $details = json_decode($entry['metadata'], true) ?? [];
    $pushSent7d += (int) ($details['sent'] ?? 0);
    $pushFailed7d += (int) ($details['failed'] ?? 0);
}

// ==========================================
// RESPONSE
// ==========================================
Response::success([
    // Basis Stats
    'revenue_today'     => $revenue['today'],
    'revenue_week'      => $revenue['week'],
    'revenue_total'     => $revenue['total'],
    'total_deposits'    => $totalDeposits,
    'total_users'       => $totalUsers,
    'active_tiers'      => $activeTiers,
    
    // Revenue Chart
    'revenue'           => $revenueChart,
    
    // Top Spenders (all time)
    'top_users'         => array_map(function ($u) {
        return [
            'id'          => (int) $u['id'],
            'first_name'  => $u['first_name'],
            'last_name'   => $u['last_name'],
            'photo_url'   => $u['photo_url'] ?? null,
            'total_spent' => (int) $u['total_spent'],
        ];
    }, $topUsers),
    
    // ======================================
    // LIVE MONITOR
    // ======================================
    'live_monitor' => [
        'active_guests_today'  => $activeGuestsToday,
        'avg_spending'         => $avgSpendingPerGuest,
        'peak_hours'           => $peakHours,
    ],
    
    // ======================================
    // WHALE TRACKER
    // ======================================
    'whales' => array_map(function ($w) {
        return [
            'id'              => (int) $w['id'],
            'first_name'      => $w['first_name'],
            'last_name'       => $w['last_name'],
            'photo_url'       => $w['photo_url'] ?? null,
            'has_push'        => !empty($w['push_token']),
            'total_spent_30d' => (int) $w['total_spent_30d'],
            'visits_30d'      => (int) $w['visits_30d'],
        ];
    }, $whales),
    
    // ======================================
    // SALDO & LIQUIDITEIT
    // ======================================
    'liquidity' => [
        'total_outstanding'   => $totalOutstandingBalance,
        'burn_rate_percent'   => $burnRate,
        'deposited_30d'       => $totalDeposited30d,
        'spent_30d'           => $totalSpent30d,
    ],
    
    // ======================================
    // MARKETING PERFORMANCE
    // ======================================
    'marketing' => [
        'birthdays_this_week' => array_map(function ($b) {
            return [
                'id'         => (int) $b['id'],
                'first_name' => $b['first_name'],
                'last_name'  => $b['last_name'],
                'birthdate'  => $b['birthdate'],
                'age'        => (int) ((time() - strtotime($b['birthdate'])) / (365.25 * 24 * 60 * 60)),
            ];
        }, $birthdaysThisWeek),
        'day_performance'     => array_map(function ($d) {
            return [
                'day'   => $d['day_name'],
                'count' => (int) $d['transaction_count'],
                'total' => (int) $d['total'],
            ];
        }, $dayPerformance),
        'tuesday_effect'      => $tuesdayBonusEffect,
    ],
    
    // ======================================
    // PERSONEELSCONTROLE
    // ======================================
    'staff' => [
        'bartender_stats' => array_map(function ($b) {
            return [
                'id'                => (int) $b['id'],
                'first_name'        => $b['first_name'],
                'last_name'         => $b['last_name'],
                'transaction_count' => (int) $b['transaction_count'],
                'total_revenue'     => (int) $b['total_revenue'],
            ];
        }, $bartenderStats),
        'correction_log'   => array_map(function ($c) {
            return [
                'id'              => (int) $c['id'],
                'guest_name'      => $c['guest_first_name'] . ' ' . $c['guest_last_name'],
                'bartender_name'  => $c['bartender_first_name'] ?? 'Onbekend',
                'amount'          => (int) $c['final_total_cents'],
                'description'     => $c['description'] ?? 'Correctie',
                'created_at'      => $c['created_at'],
            ];
        }, $correctionLog),
    ],
    
    // ======================================
    // PUSH GESCHIEDENIS
    // ======================================
    'recent_push' => array_map(function ($p) {
        return [
            'action'     => $p['action'],
            'details'    => json_decode($p['metadata'], true) ?? [],
            'created_at' => $p['created_at'],
        ];
    }, $recentPush),
    
    'push_stats' => [
        'sent_7d'   => $pushSent7d,
        'failed_7d' => $pushFailed7d,
    ],
]);
