<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/api_response.php';
require_once __DIR__ . '/../../utils/crypto.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../services/RoutingProviders.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) {
    api_json_failure(401, 'authentication_required', 'Sign in to estimate a route.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json_failure(400, 'invalid_method', 'Route estimates require a POST request.');
}
$csrf = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrf === '' || empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $csrf)) {
    api_json_failure(403, 'invalid_csrf', 'The route estimate request expired. Refresh the page and try again.');
}
if (!rate_limit_check($pdo, 'route_estimate_user_' . $userId, 20, 60, false)) {
    api_json_failure(429, 'rate_limited', 'Too many route estimates were requested. Wait a minute and try again.');
}
if (empty($appConfig['address_route_assistance_enabled'])) {
    api_json_failure(403, 'feature_disabled', 'Address and route assistance is disabled. Enter mileage manually.');
}
$originId = max(0, (int)($_POST['origin_id'] ?? 0));
$serviceLocationId = max(0, (int)($_POST['service_location_id'] ?? 0));
if ($originId <= 0 || $serviceLocationId <= 0) {
    api_json_failure(422, 'invalid_route', 'Choose a saved billing origin and service location.');
}

try {
    $originStatement = $pdo->prepare('SELECT location_enc FROM user_mileage_origins WHERE id=? AND user_id=?');
    $originStatement->execute([$originId, $userId]);
    $originEncrypted = $originStatement->fetchColumn();
    if (!is_string($originEncrypted) || !can_access_record($pdo, 'service_locations', $serviceLocationId, $userId)) {
        api_json_failure(403, 'route_access_denied', 'The selected route is not available.');
    }
    $destinationStatement = $pdo->prepare('SELECT address_line1,address_line2,city,state,postal_code,country FROM service_locations WHERE id=? AND archived=0');
    $destinationStatement->execute([$serviceLocationId]);
    $destination = $destinationStatement->fetch(PDO::FETCH_ASSOC);
    if (!$destination) {
        api_json_failure(422, 'location_not_found', 'The service location was not found.');
    }
    $cached = $pdo->prepare(
        'SELECT distance_miles,duration_seconds,provider,attribution,expires_at
         FROM route_estimate_cache WHERE user_mileage_origin_id=? AND service_location_id=? AND expires_at>NOW() LIMIT 1'
    );
    $cached->execute([$originId, $serviceLocationId]);
    $row = $cached->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        api_json_success(['data' => $row]);
    }

    $originJson = is_string($originEncrypted) ? crypto_decrypt($originEncrypted) : null;
    $origin = is_string($originJson) ? json_decode($originJson, true) : null;
    if (!is_array($origin)) {
        api_json_failure(422, 'origin_unavailable', 'The saved billing origin could not be decrypted.');
    }
    $routesKey = crypto_decrypt((string)($appConfig['google_routes_api_key_enc'] ?? ''));
    if (!is_string($routesKey) || $routesKey === '') {
        api_json_failure(503, 'provider_not_configured', 'Google Routes is not configured. Enter mileage manually.');
    }
    $estimate = (new GoogleRoutesProvider($routesKey))->estimateOneWay(new RouteRequest($origin, $destination));
    $save = $pdo->prepare(
        'INSERT INTO route_estimate_cache
          (user_mileage_origin_id,service_location_id,distance_miles,duration_seconds,provider,attribution,expires_at)
         VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE distance_miles=VALUES(distance_miles),duration_seconds=VALUES(duration_seconds),
          provider=VALUES(provider),attribution=VALUES(attribution),expires_at=VALUES(expires_at),created_at=NOW()'
    );
    $save->execute([$originId,$serviceLocationId,$estimate->distanceMiles,$estimate->durationSeconds,$estimate->provider,$estimate->attribution,$estimate->expiresAt]);
    api_json_success(['data' => [
        'distance_miles'=>$estimate->distanceMiles,'duration_seconds'=>$estimate->durationSeconds,
        'provider'=>$estimate->provider,'attribution'=>$estimate->attribution,'expires_at'=>$estimate->expiresAt,
    ]]);
} catch (OverflowException $error) {
    api_json_failure(429, 'provider_quota', $error->getMessage());
} catch (InvalidArgumentException $error) {
    api_json_failure(422, 'route_unresolved', $error->getMessage());
} catch (PDOException $error) {
    @error_log('[route-estimate] ' . $error->getMessage());
    api_json_failure(503, 'schema_out_of_date', 'Route assistance is unavailable until database migrations finish.');
} catch (Throwable $error) {
    @error_log('[route-estimate] ' . $error->getMessage());
    api_json_failure(502, 'routing_provider_unavailable', 'The routing provider is temporarily unavailable. Enter mileage manually.');
}
