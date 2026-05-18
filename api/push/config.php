<?php
declare(strict_types=1);

/**
 * GET /api/push/config
 * Returns Firebase config for client-side FCM initialization
 */

// Validate request method
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    Response::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

// Allow access without login ONLY for the fcm-test tool
$isTestTool = str_contains($_SERVER['HTTP_REFERER'] ?? '', 'fcm-test');

if (!isLoggedIn() && !$isTestTool) {
    Response::unauthorized();
}

Response::success([
    'provider'           => 'fcm',
    'firebase_api_key'   => FIREBASE_API_KEY,
    'project_id'         => FIREBASE_PROJECT_ID,
    'messaging_sender_id'=> FIREBASE_MESSAGING_SENDER_ID,
    'app_id'             => FIREBASE_APP_ID,
    'vapid_key'          => VAPID_PUBLIC_KEY,
]);
