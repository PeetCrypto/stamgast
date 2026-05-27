<?php
declare(strict_types=1);
/**
 * REGULR.vip - Guest QR Page (REDIRECT)
 * Old flow: guest showed QR to bartender
 * New flow: redirect to /scan where guest scans bartender's QR
 */

redirect('/pay');
