<?php
/**
 * Simple Login Test - Form based POST
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'stamgast_db';

echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Simple Login Test</title>
    <style>
        body { background: #0f0f0f; color: #fff; font-family: sans-serif; padding: 40px; }
        .success { color: #4CAF50; }
        .error { color: #f44336; }
        pre { background: #222; padding: 15px; }
    </style>
</head>
<body>
    <h1>Simple Login Test</h1>';

// Start session
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    echo "<p>Received: email=$email, password=$password</p>";
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND tenant_id = 1");
        $stmt->execute([$email]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$userData) {
            echo "<p class='error'>User not found!</p>";
        } else {
            echo "<p>User found: " . $userData['email'] . "</p>";
            
            // Get pepper (must match config/app.php exactly)
            define('APP_PEPPER', 'change-this-to-a-random-string-in-production-32chars-min');
            $pepperedPassword = $password . APP_PEPPER;
            
            // Test password
            if (password_verify($pepperedPassword, $userData['password_hash'])) {
                echo "<p class='success'>PASSWORD VERIFIED!</p>";
                
                // Set session
                $_SESSION['user_id'] = $userData['id'];
                $_SESSION['tenant_id'] = $userData['tenant_id'];
                $_SESSION['role'] = $userData['role'];
                $_SESSION['email'] = $userData['email'];
                $_SESSION['first_name'] = $userData['first_name'];
                
                echo "<p class='success'>SESSION SET!</p>";
                echo "<p>Redirecting...</p>";
                echo "<script>setTimeout(() => window.location.href = '/superadmin', 1000);</script>";
            } else {
                echo "<p class='error'>PASSWORD WRONG!</p>";
                echo "<p>Stored: " . substr($userData['password_hash'], 0, 30) . "...</p>";
                echo "<p>Test: " . substr($pepperedPassword, 0, 20) . "...</p>";
            }
        }
    } catch (Exception $e) {
        echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
    }
} else {
    echo '<form method="post">
        <p>Email: <input type="email" name="email" value="admin@stamgast.nl"></p>
        <p>Password: <input type="password" name="password" value="admin123"></p>
        <button type="submit">Login</button>
    </form>';
}

echo '</body></html>';