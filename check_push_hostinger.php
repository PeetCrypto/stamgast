<?php
/**
 * Push notificatie diagnostic tool voor Hostinger
 * Voer dit uit via browser op de Hostinger server
 */

// Set error handler
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Push Diagnostic Hostinger</title>";
echo "<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
    h1 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
    .section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .section h2 { margin-top: 0; color: #4CAF50; }
    .check { display: flex; align-items: center; gap: 10px; padding: 10px; margin: 5px 0; background: #f9f9f9; border-radius: 4px; }
    .check.pass { border-left: 4px solid #4CAF50; }
    .check.fail { border-left: 4px solid #F44336; }
    .check span { flex: 1; }
    .status { font-weight: bold; }
    .status.pass { color: #4CAF50; }
    .status.fail { color: #F44336; }
    pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; }
    code { font-family: 'Courier New', monospace; }
</style></head><body>";

echo "<h1>Push Notificatie Diagnostic Tool</h1>";
echo "<p>Test resultaten voor Hostinger shared hosting</p>";

// Test 1: HTTPS check
echo "<div class='section'><h2>1. HTTPS Check</h2>";
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
echo "<div class='check " . ($isHttps ? 'pass' : 'fail') . "'>";
echo "<span class='status " . ($isHttps ? 'pass' : 'fail') . "'>" . ($isHttps ? '✓ HTTPS actief' : '✗ GEEN HTTPS - Push werkt niet op HTTP!') . "</span>";
echo "<span>Protocol: " . ($isHttps ? 'https' : 'http') . "</span>";
echo "</div>";
echo "</div>";

// Test 2: Service Worker registration
echo "<div class='section'><h2>2. Service Worker Check</h2>";
echo "<div class='check pass'><span class='status pass'>✓ sw.js bestaat</span><span>public/js/sw.js</span></div>";
echo "<div class='check " . (file_exists(__DIR__ . '/public/js/sw.js') ? 'pass' : 'fail') . "'>";
echo "<span class='status " . (file_exists(__DIR__ . '/public/js/sw.js') ? 'pass' : 'fail') . "'>" . (file_exists(__DIR__ . '/public/js/sw.js') ? '✓ Bestand gevonden' : '✗ Bestand ontbreekt') . "</span>";
echo "<span>Pad: " . __DIR__ . '/public/js/sw.js' . "</span>";
echo "</div>";

// Check Firebase Messaging SDK in SW
$swContent = file_get_contents(__DIR__ . '/public/js/sw.js');
$hasFirebase = strpos($swContent, 'firebase-messaging') !== false;
echo "<div class='check " . ($hasFirebase ? 'pass' : 'fail') . "'>";
echo "<span class='status " . ($hasFirebase ? 'pass' : 'fail') . "'>" . ($hasFirebase ? '✓ Firebase Messaging SDK ingebouwd' : '✗ Firebase Messaging SDK ontbreekt') . "</span>";
echo "</div>";
echo "</div>";

// Test 3: FCM Service Account
echo "<div class='section'><h2>3. Firebase Service Account</h2>";
$saPath = __DIR__ . '/config/regulr-vip-firebase-adminsdk-fbsvc-a78cf5314e.json';
echo "<div class='check " . (file_exists($saPath) ? 'pass' : 'fail') . "'>";
echo "<span class='status " . (file_exists($saPath) ? 'pass' : 'fail') . "'>" . (file_exists($saPath) ? '✓ Service account JSON gevonden' : '✗ Service account JSON ontbreekt') . "</span>";
echo "</div>";

if (file_exists($saPath)) {
    $sa = json_decode(file_get_contents($saPath), true);
    echo "<pre><code>Project ID: " . ($sa['project_id'] ?? 'onbekend') . "\n";
    echo "Client Email: " . ($sa['client_email'] ?? 'onbekend') . "\n";
    echo "Private Key lengte: " . strlen($sa['private_key'] ?? '') . " chars</code></pre>";
}
echo "</div>";

// Test 4: Temp directory
echo "<div class='section'><h2>4. Temp Directory (voor token caching)</h2>";
$tempDir = sys_get_temp_dir();
echo "<div class='check " . (is_writable($tempDir) ? 'pass' : 'fail') . "'>";
echo "<span class='status " . (is_writable($tempDir) ? 'pass' : 'fail') . "'>" . (is_writable($tempDir) ? '✓ Schrijfbaar' : '✗ NIET schrijfbaar') . "</span>";
echo "<span>Pad: $tempDir</span>";
echo "</div>";
echo "</div>";

// Test 5: cURL check
echo "<div class='section'><h2>5. cURL Check</h2>";
$hasCurl = function_exists('curl_init');
echo "<div class='check " . ($hasCurl ? 'pass' : 'fail') . "'>";
echo "<span class='status " . ($hasCurl ? 'pass' : 'fail') . "'>" . ($hasCurl ? '✓ cURL beschikbaar' : '✗ cURL ontbreekt') . "</span>";
echo "</div>";

// Test SSL verification
$ch = curl_init('https://www.googleapis.com');
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_exec($ch);
$sslOk = curl_error($ch) === '' && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
curl_close($ch);

echo "<div class='check " . ($sslOk ? 'pass' : 'fail') . "'>";
echo "<span class='status " . ($sslOk ? 'pass' : 'fail') . "'>" . ($sslOk ? '✓ SSL verificatie werkt' : '✗ SSL verificatie faalt') . "</span>";
echo "</div>";
echo "</div>";

// Test 6: OpenSSL check
echo "<div class='section'><h2>6. OpenSSL Check (voor JWT signing)</h2>";
$hasOpenSsl = extension_loaded('openssl');
echo "<div class='check " . ($hasOpenSsl ? 'pass' : 'fail') . "'>";
echo "<span class='status " . ($hasOpenSsl ? 'pass' : 'fail') . "'>" . ($hasOpenSsl ? '✓ OpenSSL geladen' : '✗ OpenSSL ontbreekt') . "</span>";
echo "</div>";

// Test openssl_sign
$privateKey = "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC7JL2VxM3F3J3F\n-----END PRIVATE KEY-----\n";
$signature = '';
$result = openssl_sign('test', $signature, $privateKey, OPENSSL_ALGO_SHA256);
$opensslSignOk = $result === true;
echo "<div class='check " . ($opensslSignOk ? 'pass' : 'fail') . "'>";
echo "<span class='status " . ($opensslSignOk ? 'pass' : 'fail') . "'>" . ($opensslSignOk ? '✓ openssl_sign werkt' : '✗ openssl_sign faalt') . "</span>";
echo "</div>";
echo "</div>";

// Test 7: Database FCM tokens
echo "<div class='section'><h2>7. Database - Gebruikers met FCM tokens</h2>";
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE fcm_token IS NOT NULL AND fcm_token != ''");
$stmt->execute();
$count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "<div class='check " . ($count > 0 ? 'pass' : 'fail') . "'>";
echo "<span class='status " . ($count > 0 ? 'pass' : 'fail') . "'>" . ($count > 0 ? '✓ ' . $count . ' gebruikers met FCM token' : '✗ Geen gebruikers met FCM token') . "</span>";
echo "</div>";
echo "</div>";

// Samenvatting
echo "<div class='section'><h2>Samenvatting</h2>";
echo "<p><strong>Belangrijkste problemen die push notificaties kunnen blokkeren op Hostinger:</strong></p>";
echo "<ul>";
echo "<li><strong>Geen HTTPS</strong> - Push notificaties werken ALLEEN op HTTPS (of localhost)</li>";
echo "<li><strong>Service worker niet geregistreerd</strong> - Check browser console voor errors</li>";
echo "<li><strong>Notificaties geblokkeerd in browser</strong> - Gebruiker moet toestemming geven</li>";
echo "<li><strong>FCM service account probleem</strong> - Check error logs voor JWT signing errors</li>";
echo "</ul>";

echo "<p><strong>Oplossing voor Hostinger shared hosting:</strong></p>";
echo "<ol>";
echo "<li>Zorg dat je <strong>HTTPS</strong> gebruikt (Let's Encrypt of Hostinger SSL)</li>";
echo "<li>Check in de browser console (F12) of de service worker geregistreerd is</li>";
echo "<li>Vraag gebruikers om notificaties toe te staan via de 'Altijd ingeschakeld' toggle</li>";
echo "<li>Check de PHP error log voor FCM errors (vooral openssl_sign en cURL errors)</li>";
echo "</ol>";

echo "</div></body></html>";
