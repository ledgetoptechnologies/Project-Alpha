<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

// Remove payloads retained by older versions without waiting for row expiry.
for ($batch = 0; $batch < 20; $batch++) {
    $stmt = $pdo->prepare('UPDATE webhook_event_log SET raw_payload = NULL WHERE raw_payload IS NOT NULL LIMIT 5000');
    $stmt->execute();
    if ($stmt->rowCount() < 5000) {
        break;
    }
}

// Keep at most 30 days of bounded webhook attempt metadata. Batch deletions so
// retention does not hold a long table lock on busy installations.
$cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
for ($batch = 0; $batch < 20; $batch++) {
    $stmt = $pdo->prepare('DELETE FROM webhook_event_log WHERE received_at < ? LIMIT 5000');
    $stmt->execute([$cutoff]);
    if ($stmt->rowCount() < 5000) {
        break;
    }
}
