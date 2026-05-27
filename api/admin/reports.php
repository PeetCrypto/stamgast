<?php
declare(strict_types=1);

/**
 * Admin Reports API
 * Geeft dag/week/maand overzichten voor de boekhouding
 * 
 * GET /api/admin/reports?action=daily|weekly|monthly&date=YYYY-MM-DD
 * GET /api/admin/reports?action=transactions&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
 * GET /api/admin/reports?action=export_csv&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
 */

header('Content-Type: application/json');

$db = Database::getInstance()->getConnection();
$tenantId = currentTenantId();

if (!$tenantId) {
    Response::error('Niet ingelogd', 401);
    exit;
}

$action = $_GET['action'] ?? 'daily';
$date   = $_GET['date'] ?? date('Y-m-d');
$dateFrom = $_GET['date_from'] ?? $date;
$dateTo   = $_GET['date_to'] ?? $date;

// Valideer datums
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    Response::error('Ongeldige datum', 400);
    exit;
}

switch ($action) {
    case 'daily':
    case 'weekly':
    case 'monthly':
        $period = calculatePeriod($action, $date);
        $data = getReportData($db, $tenantId, $period['from'], $period['to']);
        $data['period'] = $period;
        $data['action'] = $action;
        Response::success($data);
        break;

    case 'transactions':
        $data = getTransactionList($db, $tenantId, $dateFrom, $dateTo);
        Response::success($data);
        break;

    case 'export_csv':
        exportCsv($db, $tenantId, $dateFrom, $dateTo);
        break;

    default:
        Response::error('Ongeldige actie. Gebruik: daily, weekly, monthly, transactions of export_csv', 400);
}

// ── Helper: periode berekenen ──────────────────────────────────────────
function calculatePeriod(string $action, string $date): array
{
    $dt = new DateTimeImmutable($date);
    switch ($action) {
        case 'weekly':
            // Week begint op maandag (ISO 8601)
            $dayOfWeek = (int) $dt->format('N'); // 1=ma, 7=zo
            $from = $dt->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
            $to = $from->modify('+6 days')->setTime(23, 59, 59);
            break;
        case 'monthly':
            $from = $dt->modify('first day of this month')->setTime(0, 0, 0);
            $to = $dt->modify('last day of this month')->setTime(23, 59, 59);
            break;
        default: // daily
            $from = $dt->setTime(0, 0, 0);
            $to = $dt->setTime(23, 59, 59);
            break;
    }
    return [
        'from'     => $from->format('Y-m-d H:i:s'),
        'to'       => $to->format('Y-m-d H:i:s'),
        'label'    => match ($action) {
            'daily'   => $dt->format('d-m-Y'),
            'weekly'  => $from->format('d-m') . ' t/m ' . $to->format('d-m-Y'),
            'monthly' => $dt->format('F Y'),
        },
        'date_from' => $from->format('Y-m-d'),
        'date_to'   => $to->format('Y-m-d'),
    ];
}

// ── Helper: rapportage data ophalen ─────────────────────────────────────
function getReportData(PDO $db, int $tenantId, string $from, string $to): array
{
    // ── 1. Betalingen (omzet) ──
    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS transaction_count,
            COALESCE(SUM(amount_alc_cents), 0)  AS gross_alc_cents,
            COALESCE(SUM(amount_food_cents), 0) AS gross_food_cents,
            COALESCE(SUM(discount_alc_cents), 0) AS discount_alc_cents,
            COALESCE(SUM(discount_food_cents), 0) AS discount_food_cents,
            COALESCE(SUM(final_total_cents), 0) AS revenue_cents,
            COALESCE(SUM(btw_alc_cents), 0)    AS btw_alc_cents,
            COALESCE(SUM(btw_food_cents), 0)   AS btw_food_cents,
            COALESCE(SUM(btw_total_cents), 0)  AS btw_total_cents,
            COALESCE(SUM(points_earned), 0)    AS points_earned
         FROM `transactions`
         WHERE `tenant_id` = :tenant_id
           AND `type` = 'payment'
           AND `created_at` BETWEEN :from AND :to"
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':from'      => $from,
        ':to'        => $to,
    ]);
    $payments = $stmt->fetch();

    // ── 2. Stortingen ──
    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS deposit_count,
            COALESCE(SUM(final_total_cents), 0) AS deposit_total_cents
         FROM `transactions`
         WHERE `tenant_id` = :tenant_id
           AND `type` = 'deposit'
           AND `created_at` BETWEEN :from AND :to"
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':from'      => $from,
        ':to'        => $to,
    ]);
    $deposits = $stmt->fetch();

    // ── 3. Bonussen gegeven ──
    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS bonus_count,
            COALESCE(SUM(final_total_cents), 0) AS bonus_total_cents
         FROM `transactions`
         WHERE `tenant_id` = :tenant_id
           AND `type` = 'bonus'
           AND `created_at` BETWEEN :from AND :to"
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':from'      => $from,
        ':to'        => $to,
    ]);
    $bonuses = $stmt->fetch();

    // ── 4. Revenue per bartender ──
    $stmt = $db->prepare(
        "SELECT
            b.first_name,
            b.last_name,
            COUNT(t.id) AS transaction_count,
            COALESCE(SUM(t.final_total_cents), 0) AS revenue_cents,
            COALESCE(SUM(t.btw_total_cents), 0) AS btw_cents
         FROM `transactions` t
         LEFT JOIN `users` b ON b.id = t.bartender_id
         WHERE t.`tenant_id` = :tenant_id
           AND t.`type` = 'payment'
           AND t.`created_at` BETWEEN :from AND :to
         GROUP BY t.bartender_id, b.first_name, b.last_name
         ORDER BY revenue_cents DESC"
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':from'      => $from,
        ':to'        => $to,
    ]);
    $bartenderBreakdown = $stmt->fetchAll();

    // ── 5. Openstaande wallet tegoeden ──
    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS wallet_count,
            COALESCE(SUM(balance_cents), 0) AS outstanding_balance_cents
         FROM `wallets`
         WHERE `tenant_id` = :tenant_id AND `balance_cents` > 0"
    );
    $stmt->execute([':tenant_id' => $tenantId]);
    $wallets = $stmt->fetch();

    return [
        'payments' => [
            'transaction_count'   => (int) $payments['transaction_count'],
            'gross_alc_cents'     => (int) $payments['gross_alc_cents'],
            'gross_food_cents'    => (int) $payments['gross_food_cents'],
            'discount_alc_cents'  => (int) $payments['discount_alc_cents'],
            'discount_food_cents' => (int) $payments['discount_food_cents'],
            'revenue_cents'       => (int) $payments['revenue_cents'],
            'btw_alc_cents'       => (int) $payments['btw_alc_cents'],
            'btw_food_cents'      => (int) $payments['btw_food_cents'],
            'btw_total_cents'     => (int) $payments['btw_total_cents'],
            'points_earned'       => (int) $payments['points_earned'],
        ],
        'deposits' => [
            'deposit_count'       => (int) $deposits['deposit_count'],
            'deposit_total_cents' => (int) $deposits['deposit_total_cents'],
        ],
        'bonuses' => [
            'bonus_count'       => (int) $bonuses['bonus_count'],
            'bonus_total_cents' => (int) $bonuses['bonus_total_cents'],
        ],
        'wallets' => [
            'wallet_count'              => (int) $wallets['wallet_count'],
            'outstanding_balance_cents' => (int) $wallets['outstanding_balance_cents'],
        ],
        'bartender_breakdown' => array_map(function (array $row): array {
            return [
                'name'              => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Onbekend',
                'transaction_count' => (int) $row['transaction_count'],
                'revenue_cents'     => (int) $row['revenue_cents'],
                'btw_cents'         => (int) $row['btw_cents'],
            ];
        }, $bartenderBreakdown),
    ];
}

// ── Helper: transactielijst ────────────────────────────────────────────
function getTransactionList(PDO $db, int $tenantId, string $from, string $to, int $limitOverride = 0): array
{
    $page  = max(1, (int) ($_GET['page'] ?? 1));
    $limit = $limitOverride > 0
        ? min($limitOverride, MAX_PAGE_SIZE)
        : min(max(1, (int) ($_GET['limit'] ?? 50)), MAX_PAGE_SIZE);
    $offset = ($page - 1) * $limit;

    // Filter op type
    $typeFilter = $_GET['type'] ?? '';
    $typeSql = '';
    $params = [
        ':tenant_id' => $tenantId,
        ':from'      => $from . ' 00:00:00',
        ':to'        => $to . ' 23:59:59',
    ];

    if (in_array($typeFilter, ['payment', 'deposit', 'bonus', 'correction'], true)) {
        $typeSql = " AND t.`type` = :type";
        $params[':type'] = $typeFilter;
    }

    // Totaal
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM `transactions` t
         WHERE t.`tenant_id` = :tenant_id
           AND t.`created_at` BETWEEN :from AND :to
           {$typeSql}"
    );
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    // Lijst met user info
    $stmt = $db->prepare(
        "SELECT
            t.id, t.type, t.final_total_cents, t.btw_total_cents,
            t.amount_alc_cents, t.amount_food_cents,
            t.discount_alc_cents, t.discount_food_cents,
            t.btw_alc_cents, t.btw_food_cents,
            t.points_earned, t.created_at,
            u.first_name, u.last_name
         FROM `transactions` t
         LEFT JOIN `users` u ON u.id = t.user_id
         WHERE t.`tenant_id` = :tenant_id
           AND t.`created_at` BETWEEN :from AND :to
           {$typeSql}
         ORDER BY t.`created_at` DESC
         LIMIT :limit OFFSET :offset"
    );
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    return [
        'transactions' => array_map(function (array $row): array {
            return [
                'id'                  => (int) $row['id'],
                'type'                => $row['type'],
                'guest_name'          => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Onbekend',
                'amount_alc_cents'    => (int) $row['amount_alc_cents'],
                'amount_food_cents'   => (int) $row['amount_food_cents'],
                'discount_alc_cents'  => (int) $row['discount_alc_cents'],
                'discount_food_cents' => (int) $row['discount_food_cents'],
                'final_total_cents'   => (int) $row['final_total_cents'],
                'btw_alc_cents'       => (int) $row['btw_alc_cents'],
                'btw_food_cents'      => (int) $row['btw_food_cents'],
                'btw_total_cents'     => (int) $row['btw_total_cents'],
                'points_earned'       => (int) $row['points_earned'],
                'created_at'          => $row['created_at'],
            ];
        }, $transactions),
        'total' => $total,
        'page'  => $page,
        'limit' => $limit,
    ];
}

// ── Helper: CSV export ────────────────────────────────────────────────
function exportCsv(PDO $db, int $tenantId, string $dateFrom, string $dateTo): void
{
    $data = getTransactionList($db, $tenantId, $dateFrom, $dateTo, 10000);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="regulr-overzicht-' . $dateFrom . '-tot-' . $dateTo . '.csv"');

    $output = fopen('php://output', 'w');
    // BOM voor Excel UTF-8 herkenning
    fwrite($output, "\xEF\xBB\xBF");

    // Header
    fputcsv($output, [
        'Datum', 'Tijd', 'Type', 'Gast', 'Alcohol (€)', 'Food (€)',
        'Korting Alc (€)', 'Korting Food (€)', 'Totaal (€)',
        'BTW Alc 21% (€)', 'BTW Food 9% (€)', 'BTW Totaal (€)',
        'Punten',
    ], ';');

    foreach ($data['transactions'] as $tx) {
        $dt = new DateTimeImmutable($tx['created_at']);
        fputcsv($output, [
            $dt->format('Y-m-d'),
            $dt->format('H:i'),
            $tx['type'],
            $tx['guest_name'],
            number_format($tx['amount_alc_cents'] / 100, 2, ',', '.'),
            number_format($tx['amount_food_cents'] / 100, 2, ',', '.'),
            number_format($tx['discount_alc_cents'] / 100, 2, ',', '.'),
            number_format($tx['discount_food_cents'] / 100, 2, ',', '.'),
            number_format($tx['final_total_cents'] / 100, 2, ',', '.'),
            number_format($tx['btw_alc_cents'] / 100, 2, ',', '.'),
            number_format($tx['btw_food_cents'] / 100, 2, ',', '.'),
            number_format($tx['btw_total_cents'] / 100, 2, ',', '.'),
            $tx['points_earned'],
        ], ';');
    }

    fclose($output);
    exit;
}
