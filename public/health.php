<?php
/**
 * Health check for an app server behind the load balancer
 * (deploy/load-balancer/): "ok <server name>" (200) while it can serve
 * pages, 503 if its shared login store (Redis) is unreachable. Docker marks
 * a server "unhealthy" from this, and /lb-status on the load balancer shows
 * which server answered. Supabase isn't checked: every server shares it,
 * so its being down isn't a reason to take one server out.
 */
require_once __DIR__ . '/../config/supabase.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

if (getenv('SESSION_REDIS_URL')) {
    $redis = shared_redis();
    $ok = false;
    try {
        $ok = $redis && $redis->ping();
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) {
        http_response_code(503);
        echo "unhealthy: shared session store unreachable\n";
        exit;
    }
}

echo 'ok ' . (getenv('APP_SERVER_NAME') ?: gethostname()) . "\n";
