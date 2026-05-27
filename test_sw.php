<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Service Worker Test</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f5f5f5; }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .ok { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        pre { background: #333; color: #fff; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Service Worker Test</h1>
    <div id="status"></div>
    
    <script>
    const status = document.getElementById('status');
    
    function log(msg, type = 'info') {
        const div = document.createElement('div');
        div.className = 'status ' + type;
        div.textContent = msg;
        status.appendChild(div);
        console.log(msg);
    }
    
    // Check SW support
    if (!('serviceWorker' in navigator)) {
        log('Service Worker niet ondersteund in deze browser', 'error');
    } else {
        log('Service Worker ondersteund', 'ok');
        
        // Register SW
        navigator.serviceWorker.register('/js/sw.js', { scope: '/' })
            .then(function(reg) {
                log('Service Worker geregistreerd: ' + reg.scope, 'ok');
                log('Active: ' + reg.active + ', Waiting: ' + reg.waiting + ', Installing: ' + reg.installing, 'ok');
                
                // Check if SW is active
                if (reg.active) {
                    log('Service Worker is actief', 'ok');
                } else {
                    log('Service Worker is nog niet actief', 'error');
                }
            })
            .catch(function(err) {
                log('Service Worker registratie mislukt: ' + err.message, 'error');
            });
    }
    
    // Check push support
    if ('PushManager' in window) {
        log('Push Manager ondersteund', 'ok');
    } else {
        log('Push Manager niet ondersteund', 'error');
    }
    
    // Check notification permission
    if ('Notification' in window) {
        log('Notification API ondersteund', 'ok');
        log('Permission: ' + Notification.permission, 'info');
    } else {
        log('Notification API niet ondersteund', 'error');
    }
    </script>
</body>
</html>
