<?php

declare(strict_types=1);

function mileage_tracking_method_allowed(string $method, string $action): bool
{
    return $method === 'POST' || ($method === 'GET' && $action === 'status');
}

function mileage_financial_treatment_for_relationship(string $relationship): string
{
    return match ($relationship) {
        'employee' => 'worker_reimbursement',
        'contractor' => 'contractor_record_only',
        'owner' => 'organization_mileage',
        default => 'nonreimbursable',
    };
}

function mileage_financial_treatment_labels(): array
{
    return [
        'organization_mileage' => 'Organization mileage',
        'worker_reimbursement' => 'Organization repays worker',
        'contractor_record_only' => 'Contractor record only',
        'nonreimbursable' => 'Record only — no organization repayment',
    ];
}

function mileage_organization_total_exclusions(): array
{
    return ['contractor_record_only', 'nonreimbursable'];
}

interface MileageRoutingAdapter
{
    /** @return array{one_way_miles:float,provider_reference:?string}|null */
    public function oneWayDistance(array $decryptedOrigin, array $serviceLocation): ?array;
}

/** Manual saved distances remain authoritative until a map provider is configured. */
final class NullMileageRoutingAdapter implements MileageRoutingAdapter
{
    public function oneWayDistance(array $decryptedOrigin, array $serviceLocation): ?array
    {
        return null;
    }
}

interface MileageActorAdapter
{
    public function userId(): int;
    public function organizationId(): ?int;
}

/** Initial web implementation. A native access-token adapter can implement the same contract. */
final class SessionMileageActorAdapter implements MileageActorAdapter
{
    public function __construct(private readonly int $userId, private readonly ?int $organizationId) {}
    public function userId(): int { return $this->userId; }
    public function organizationId(): ?int { return $this->organizationId; }
}

/** Shared mileage calculations. Physical travel and client pricing stay separate. */
function mileage_logged_miles(string $entryMode, float $enteredMiles, bool $includeReturn): float
{
    $enteredMiles = max(0.0, $enteredMiles);
    if ($entryMode === 'total_trip') {
        return round($enteredMiles, 3);
    }
    return round($enteredMiles * ($includeReturn ? 2 : 1), 3);
}

/**
 * @return array{pricing_distance_miles:float,included_miles:float,billable_miles:float,mileage_rate:float,fixed_amount:float,client_charge:float}
 */
function mileage_calculate_charge(
    string $method,
    float $pricingDistance,
    float $includedMiles,
    bool $chargeReturn,
    float $rate,
    float $fixedAmount = 0.0,
    bool $distanceIsTotalTrip = false
): array {
    $method = in_array($method, ['actual_trip', 'origin_distance', 'fixed_fee'], true) ? $method : 'actual_trip';
    $pricingDistance = round(max(0.0, $pricingDistance), 3);
    $includedMiles = round(max(0.0, $includedMiles), 3);
    $rate = round(max(0.0, $rate), 4);
    $fixedAmount = round(max(0.0, $fixedAmount), 2);

    if ($method === 'fixed_fee') {
        return [
            'pricing_distance_miles' => 0.0,
            'included_miles' => 0.0,
            'billable_miles' => 0.0,
            'mileage_rate' => 0.0,
            'fixed_amount' => $fixedAmount,
            'client_charge' => $fixedAmount,
        ];
    }

    // Simple and origin-based rules apply the allowance to the one-way basis,
    // then optionally charge the return. Total-trip rules subtract it once.
    $billable = $distanceIsTotalTrip
        ? max(0.0, $pricingDistance - $includedMiles)
        : max(0.0, $pricingDistance - $includedMiles) * ($chargeReturn ? 2 : 1);
    $billable = round($billable, 3);

    return [
        'pricing_distance_miles' => $pricingDistance,
        'included_miles' => $includedMiles,
        'billable_miles' => $billable,
        'mileage_rate' => $rate,
        'fixed_amount' => 0.0,
        'client_charge' => round($billable * $rate, 2),
    ];
}

function mileage_charge_method_label(string $method): string
{
    return match ($method) {
        'origin_distance' => 'Billing origin to service location',
        'fixed_fee' => 'Fixed travel fee',
        default => 'Actual trip distance',
    };
}

/** @return array<int,array<string,mixed>> */
function mileage_parse_allocations(array $post, string $entryMode, float $loggedMiles): array
{
    $clientIds = (array)($post['allocation_client_id'] ?? []);
    $methods = (array)($post['allocation_charge_method'] ?? []);
    $projects = (array)($post['allocation_project_id'] ?? []);
    $contracts = (array)($post['allocation_contract_id'] ?? []);
    $locations = (array)($post['allocation_service_location_id'] ?? []);
    $origins = (array)($post['allocation_origin_id'] ?? []);
    $distances = (array)($post['allocation_pricing_distance'] ?? []);
    $included = (array)($post['allocation_included_miles'] ?? []);
    $rates = (array)($post['allocation_mileage_rate'] ?? []);
    $fixed = (array)($post['allocation_fixed_amount'] ?? []);
    $returns = (array)($post['allocation_charge_return'] ?? []);

    $rows = [];
    foreach ($clientIds as $index => $rawClientId) {
        $clientId = (int)$rawClientId;
        if ($clientId <= 0) {
            continue;
        }
        $method = (string)($methods[$index] ?? 'actual_trip');
        if (!in_array($method, ['actual_trip', 'origin_distance', 'fixed_fee'], true)) {
            throw new InvalidArgumentException('Invalid client travel charge method.');
        }
        $distance = (float)($distances[$index] ?? 0);
        // Actual total-trip allocations use a reviewed portion of the physical route.
        $distanceIsTotal = $method === 'actual_trip' && $entryMode === 'total_trip';
        if ($method === 'actual_trip' && $distance <= 0) {
            $distance = $entryMode === 'total_trip' ? $loggedMiles : (float)($post['miles'] ?? 0);
        }
        $charge = mileage_calculate_charge(
            $method,
            $distance,
            (float)($included[$index] ?? 0),
            !empty($returns[$index]),
            (float)($rates[$index] ?? 0),
            (float)($fixed[$index] ?? 0),
            $distanceIsTotal
        );
        if ($method !== 'fixed_fee' && $charge['pricing_distance_miles'] <= 0) {
            throw new InvalidArgumentException('Each mileage-based client charge needs a pricing distance.');
        }
        $rows[] = array_merge($charge, [
            'client_id' => $clientId,
            'project_id' => max(0, (int)($projects[$index] ?? 0)) ?: null,
            'contract_id' => max(0, (int)($contracts[$index] ?? 0)) ?: null,
            'service_location_id' => max(0, (int)($locations[$index] ?? 0)) ?: null,
            'origin_id' => max(0, (int)($origins[$index] ?? 0)) ?: null,
            'charge_method' => $method,
            'charge_return' => !empty($returns[$index]) ? 1 : 0,
        ]);
    }
    return $rows;
}

function mileage_validate_allocation_scope(PDO $pdo, array $row, int $userId): void
{
    $client = $pdo->prepare('SELECT id FROM clients WHERE id=? AND archived=0');
    $client->execute([(int)$row['client_id']]);
    if (!$client->fetchColumn()) {
        throw new InvalidArgumentException('A selected client is unavailable.');
    }
    if (!empty($row['project_id'])) {
        $project = $pdo->prepare('SELECT id FROM projects WHERE id=? AND client_id=?');
        $project->execute([(int)$row['project_id'], (int)$row['client_id']]);
        if (!$project->fetchColumn()) {
            throw new InvalidArgumentException('A selected project does not belong to its client.');
        }
    }
    if (!empty($row['contract_id'])) {
        $contract = $pdo->prepare('SELECT id FROM contracts WHERE id=? AND client_id=?');
        $contract->execute([(int)$row['contract_id'], (int)$row['client_id']]);
        if (!$contract->fetchColumn()) {
            throw new InvalidArgumentException('A selected contract does not belong to its client.');
        }
    }
    if (!empty($row['service_location_id'])) {
        $location = $pdo->prepare('SELECT id FROM service_locations WHERE id=? AND archived=0 AND (client_id IS NULL OR client_id=?)');
        $location->execute([(int)$row['service_location_id'], (int)$row['client_id']]);
        if (!$location->fetchColumn()) {
            throw new InvalidArgumentException('A selected service location does not belong to its client.');
        }
    }
    if (!empty($row['origin_id'])) {
        $origin = $pdo->prepare('SELECT id FROM user_mileage_origins WHERE id=? AND user_id=?');
        $origin->execute([(int)$row['origin_id'], $userId]);
        if (!$origin->fetchColumn()) {
            throw new InvalidArgumentException('A selected billing origin is unavailable.');
        }
    }
}

function mileage_replace_allocations(PDO $pdo, int $mileageLogId, ?int $orgId, int $userId, array $rows): void
{
    $existing = $pdo->prepare('SELECT COUNT(*) FROM mileage_charge_allocations WHERE mileage_log_id=? AND billed=1');
    $existing->execute([$mileageLogId]);
    if ((int)$existing->fetchColumn() > 0) {
        throw new RuntimeException('Billed client travel charges cannot be changed.');
    }
    $pdo->prepare('DELETE FROM mileage_charge_allocations WHERE mileage_log_id=?')->execute([$mileageLogId]);
    if (!$rows) {
        $pdo->prepare('UPDATE mileage_logs SET client_id=NULL,project_id=NULL,is_billable=0,bill_return_trip=0 WHERE id=?')->execute([$mileageLogId]);
        return;
    }
    $insert = $pdo->prepare(
        'INSERT INTO mileage_charge_allocations
         (mileage_log_id,organization_id,client_id,project_id,contract_id,service_location_id,origin_id,charge_method,
          pricing_distance_miles,included_miles,charge_return,billable_miles,mileage_rate,fixed_amount,client_charge,rule_snapshot)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($rows as $row) {
        mileage_validate_allocation_scope($pdo, $row, $userId);
        $snapshot = json_encode([
            'charge_method' => $row['charge_method'],
            'pricing_distance_miles' => $row['pricing_distance_miles'],
            'included_miles' => $row['included_miles'],
            'charge_return' => $row['charge_return'],
            'billable_miles' => $row['billable_miles'],
            'mileage_rate' => $row['mileage_rate'],
            'fixed_amount' => $row['fixed_amount'],
        ], JSON_UNESCAPED_SLASHES);
        $insert->execute([
            $mileageLogId, $orgId, $row['client_id'], $row['project_id'], $row['contract_id'],
            $row['service_location_id'], $row['origin_id'], $row['charge_method'], $row['pricing_distance_miles'],
            $row['included_miles'], $row['charge_return'], $row['billable_miles'], $row['mileage_rate'],
            $row['fixed_amount'], $row['client_charge'], $snapshot,
        ]);
    }
    $first = $rows[0];
    $pdo->prepare('UPDATE mileage_logs SET client_id=?,project_id=?,is_billable=1,bill_return_trip=?,mileage_rate=? WHERE id=?')
        ->execute([$first['client_id'], $first['project_id'], $first['charge_return'], $first['mileage_rate'], $mileageLogId]);
}

function mileage_haversine_miles(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthMiles = 3958.7613;
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;
    return $earthMiles * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}

function mileage_recalculate_tracking_session(PDO $pdo, int $sessionId): array
{
    $stmt = $pdo->prepare('SELECT latitude,longitude,captured_at FROM mileage_tracking_points WHERE session_id=? AND accepted=1 ORDER BY sequence_no');
    $stmt->execute([$sessionId]);
    $points = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $miles = 0.0;
    for ($i = 1, $count = count($points); $i < $count; $i++) {
        $miles += mileage_haversine_miles(
            (float)$points[$i - 1]['latitude'], (float)$points[$i - 1]['longitude'],
            (float)$points[$i]['latitude'], (float)$points[$i]['longitude']
        );
    }
    $miles = round($miles, 3);
    $lastPoint = $points ? (string)$points[array_key_last($points)]['captured_at'] : null;
    $pdo->prepare('UPDATE mileage_tracking_sessions SET calculated_miles=?,point_count=?,last_point_at=? WHERE id=?')
        ->execute([$miles, count($points), $lastPoint, $sessionId]);
    return ['calculated_miles' => $miles, 'point_count' => count($points), 'last_point_at' => $lastPoint];
}

function mileage_rule_from_post(array $post, array $defaults = []): array
{
    $method=(string)($post['travel_charge_method']??'none');
    if(!in_array($method,['actual_trip','origin_distance','fixed_fee','none'],true))$method='none';
    return [
        'charge_method'=>$method,
        'mileage_rate'=>max(0,(float)($post['travel_mileage_rate']??($defaults['rate']??0))),
        'included_miles'=>max(0,(float)($post['travel_included_miles']??($defaults['included']??0))),
        'charge_return'=>!empty($post['travel_charge_return'])?1:0,
        'fixed_amount'=>max(0,(float)($post['travel_fixed_amount']??0)),
        'origin_id'=>max(0,(int)($post['travel_origin_id']??0))?:null,
        'service_location_id'=>max(0,(int)($post['travel_service_location_id']??0))?:null,
        'estimated_one_way_miles'=>trim((string)($post['travel_estimated_one_way_miles']??''))!==''?max(0,(float)$post['travel_estimated_one_way_miles']):null,
        'terms_text'=>trim((string)($post['travel_terms_text']??''))?:null,
    ];
}

function mileage_save_document_rule(PDO $pdo,string $scope,int $documentId,?int $orgId,int $clientId,int $userId,array $rule): void
{
    if(!in_array($scope,['quote','contract'],true))throw new InvalidArgumentException('Invalid travel rule scope.');
    $column=$scope.'_id';
    $pdo->prepare("DELETE FROM travel_billing_rules WHERE scope_type=? AND {$column}=?")->execute([$scope,$documentId]);
    if(($rule['charge_method']??'none')==='none')return;
    $pdo->prepare("INSERT INTO travel_billing_rules (organization_id,scope_type,client_id,{$column},charge_method,mileage_rate,included_miles,charge_return,fixed_amount,origin_id,service_location_id,estimated_one_way_miles,terms_text,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$orgId,$scope,$clientId,$documentId,$rule['charge_method'],$rule['mileage_rate'],$rule['included_miles'],$rule['charge_return'],$rule['fixed_amount'],$rule['origin_id'],$rule['service_location_id'],$rule['estimated_one_way_miles'],$rule['terms_text'],$userId]);
}

function mileage_save_client_rule(PDO $pdo, ?int $orgId, int $clientId, int $userId, array $rule): void
{
    if ($clientId <= 0) throw new InvalidArgumentException('A client is required.');
    $pdo->prepare('DELETE FROM travel_billing_rules WHERE scope_type="client" AND client_id=?')->execute([$clientId]);
    if (($rule['charge_method'] ?? 'none') === 'none') return;
    $pdo->prepare('INSERT INTO travel_billing_rules (organization_id,scope_type,client_id,charge_method,mileage_rate,included_miles,charge_return,fixed_amount,origin_id,service_location_id,estimated_one_way_miles,terms_text,created_by) VALUES (?,"client",?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$orgId,$clientId,$rule['charge_method'],$rule['mileage_rate'],$rule['included_miles'],$rule['charge_return'],$rule['fixed_amount'],$rule['origin_id'],$rule['service_location_id'],$rule['estimated_one_way_miles'],$rule['terms_text'],$userId]);
}

function mileage_document_travel_item(array $rule): ?array
{
    $method=(string)($rule['charge_method']??'none');if($method==='none')return null;
    $description=mileage_charge_method_label($method).'. Included miles: '.number_format((float)$rule['included_miles'],3).'. '.(!empty($rule['charge_return'])?'Return travel is charged.':'Return travel is not charged.');
    if(!empty($rule['terms_text']))$description.=' '.$rule['terms_text'];
    if($method==='fixed_fee')return ['item'=>'Travel fee','description'=>$description,'quantity'=>1,'unit_price'=>(float)$rule['fixed_amount'],'line_total'=>(float)$rule['fixed_amount'],'billing_unit'=>'each','pricing_status'=>'standard'];
    $estimate=$rule['estimated_one_way_miles'];
    if($estimate===null||$estimate<=0)return ['item'=>'Travel mileage','description'=>$description.' Final quantity will be based on approved travel.','quantity'=>0.0,'unit_price'=>(float)$rule['mileage_rate'],'line_total'=>0.0,'billing_unit'=>'mile','pricing_status'=>'variable'];
    $calc=mileage_calculate_charge($method,(float)$estimate,(float)$rule['included_miles'],!empty($rule['charge_return']),(float)$rule['mileage_rate']);
    return ['item'=>'Estimated travel mileage','description'=>$description.' Estimate only; the invoice will use approved actual travel.','quantity'=>$calc['billable_miles'],'unit_price'=>$calc['mileage_rate'],'line_total'=>$calc['client_charge'],'billing_unit'=>'mile','pricing_status'=>'estimate'];
}

function mileage_copy_document_rule(PDO $pdo,int $quoteId,int $contractId,?int $orgId,int $clientId,int $userId): void
{
    $q=$pdo->prepare('SELECT * FROM travel_billing_rules WHERE scope_type="quote" AND quote_id=? ORDER BY id DESC LIMIT 1');$q->execute([$quoteId]);$rule=$q->fetch(PDO::FETCH_ASSOC);if(!$rule)return;
    mileage_save_document_rule($pdo,'contract',$contractId,$orgId,$clientId,$userId,$rule);
}
