<?php
declare(strict_types=1);

/**
 * Bartender Payment View
 * Redirects to the unified bartender dashboard
 * Kept for backward compatibility (/payment route)
 */

header('Location: ' . BASE_URL . '/bartender');
exit;
