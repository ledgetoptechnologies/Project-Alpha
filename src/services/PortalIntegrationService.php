<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

require_once __DIR__ . '/../utils/document_pricing_adjustments.php';

final class PortalIntegrationService
{
    /** @return array<string,mixed> */
    public function profile(PDO $pdo, string $applicationKey, string $capability): array
    {
        $applicationKey = ExternalOpsIntegrationService::normalizeApplicationKey($applicationKey);
        $allowed = [
            'portal_projection_enabled', 'relation_projection_enabled', 'contact_assignment_projection_enabled', 'catalog_projection_enabled',
            'service_assignment_projection_enabled', 'pricing_preview_enabled', 'draft_quote_enabled',
        ];
        if (!in_array($capability, $allowed, true)) throw new DomainException('integration-capability-invalid');
        $statement = $pdo->prepare("SELECT * FROM portal_integration_profiles WHERE application_key=? AND enabled=1 AND {$capability}=1");
        $statement->execute([$applicationKey]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$profile) throw new DomainException('integration-disabled');
        return $profile;
    }

    /** @return array<string,mixed> */
    public function pricingHint(PDO $pdo, int $apiKeyId, string $applicationKey, array $request): array
    {
        PortalIntegrationContract::validatePricingRequest($request);
        $profile = $this->profile($pdo, $applicationKey, 'pricing_preview_enabled');
        if (trim((string)($profile['pricing_source'] ?? '')) === '' || !hash_equals((string)$profile['pricing_source'], (string)$request['source'])) {
            throw new DomainException('pricing-source-denied');
        }
        $authorization = $request['authorizationContext'];
        $root = $authorization['workspaceRoot'];
        $project = $this->authorizedProject($pdo, (int)$profile['id'], (string)$root['type'], (string)$root['publicId'], (string)$authorization['projectPublicId']);
        $services = $this->resolveServices($pdo, $request['services'], 'sourceVersion');
        $pricing = (new QuoteDraftDomainService())->priceServices($services);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $response = [
            'schemaVersion' => 1,
            'catalogVersion' => $this->catalogVersion($services),
            'coverageSquareMetres' => (string)$request['coverageSquareMetres'],
            'displayMode' => $pricing['mode'],
            'currency' => $pricing['available'] ? $pricing['currency'] : null,
            'startingAt' => $pricing['mode']==='starting_at' ? $pricing['total'] : null,
            'typicalMinimum' => $pricing['typicalMinimum'],
            'typicalMaximum' => $pricing['typicalMaximum'],
            'reasonUnavailable' => $pricing['available'] ? null : $pricing['reason'],
            'disclaimer' => PortalIntegrationContract::DISCLAIMER,
            'validUntil' => $now->modify('+10 minutes')->format('Y-m-d\TH:i:s.000\Z'),
        ];
        return $response;
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function createDraftQuote(PDO $pdo,int $apiKeyId,string $applicationKey,string $idempotencyKey,string $payloadHash,array$request,string$correlationId):array
    {
        PortalIntegrationContract::validateDraftRequest($request);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255 || preg_match('/^[\x21-\x7E]+$/D', $idempotencyKey) !== 1) {
            throw new DomainException('idempotency-key-invalid');
        }
        $profile = $this->profile($pdo, $applicationKey, 'draft_quote_enabled');
        if (trim((string)($profile['draft_source'] ?? '')) === '' || !hash_equals((string)$profile['draft_source'], (string)$request['source'])) {
            throw new DomainException('draft-source-denied');
        }
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) $pdo->beginTransaction();
            $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $profileLock = $pdo->prepare('SELECT id FROM portal_integration_profiles WHERE id=?' . $suffix);
            $profileLock->execute([(int)$profile['id']]);
            $existing = $pdo->prepare('SELECT payload_hash,response_json FROM portal_draft_quote_commands WHERE integration_profile_id=? AND api_key_id=? AND idempotency_hash=?' . $suffix);
            $idempotencyHash = hash('sha256', $idempotencyKey);
            $existing->execute([(int)$profile['id'], $apiKeyId, $idempotencyHash]);
            $receipt = $existing->fetch(PDO::FETCH_ASSOC);
            if ($receipt) {
                if (!hash_equals((string)$receipt['payload_hash'], $payloadHash)) {
                    (new PortalIntegrationAuditService())->recordCommand($pdo,$applicationKey,$apiKeyId,PortalIntegrationContract::DRAFT_SCOPE,'conflicted',$correlationId,'IDEMPOTENCY_CONFLICT');
                    if($ownsTransaction)$pdo->commit();
                    return ['status' => 409, 'body' => ['code' => 'IDEMPOTENCY_CONFLICT']];
                }
                $body = json_decode((string)$receipt['response_json'], true, 32, JSON_THROW_ON_ERROR);
                (new PortalIntegrationAuditService())->recordCommand($pdo,$applicationKey,$apiKeyId,PortalIntegrationContract::DRAFT_SCOPE,'replayed',$correlationId,null,'quote',(string)($body['draftQuote']['publicId']??''));
                if ($ownsTransaction) $pdo->commit();
                return ['status' => 200, 'body' => $body];
            }

            $auth = $request['authorization'];
            $client = $this->authorizedClient($pdo, (int)$profile['id'], (string)$auth['clientPublicId'], $auth['organizationPublicId']);
            $project = null;
            if ($auth['projectPublicId'] !== null) {
                $visibility = ProjectLifecycleSchema::visibility($pdo, '', true);
                $statement = $pdo->prepare("SELECT * FROM projects WHERE public_id=? AND client_id=? AND status<>'cancelled' AND {$visibility}");
                $statement->execute([(string)$auth['projectPublicId'], (int)$client['id']]);
                $project = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
                if($project===null){(new PortalIntegrationAuditService())->recordCommand($pdo,$applicationKey,$apiKeyId,PortalIntegrationContract::DRAFT_SCOPE,'denied',$correlationId,'SCOPE_DENIED');if($ownsTransaction)$pdo->commit();return['status'=>403,'body'=>['code'=>'SCOPE_DENIED']];}
            }
            try { $services = $this->resolveServices($pdo, $request['services'], 'catalogVersion'); }
            catch(DomainException$error){if($error->getMessage()==='stale-catalog'){(new PortalIntegrationAuditService())->recordCommand($pdo,$applicationKey,$apiKeyId,PortalIntegrationContract::DRAFT_SCOPE,'conflicted',$correlationId,'STALE_CATALOG');if($ownsTransaction)$pdo->commit();return['status'=>409,'body'=>['code'=>'STALE_CATALOG']];}throw$error;}
            $pricing=(new QuoteDraftDomainService())->priceServices($services);
            $subtotal=$pricing['total'];
            $publicId = bin2hex(random_bytes(16));
            $insert = $pdo->prepare(
                'INSERT INTO quotes (public_id,client_id,project_id,organization_id,status,quote_type,billing_mode,subtotal,total,scope,custom_fields,created_at)
                 VALUES (?,?,?,?,\'draft\',\'regular\',\'fixed\',?,?,?, ?,CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                $publicId, (int)$client['id'], $project ? (int)$project['id'] : null,
                $client['organization_id'] === null ? null : (int)$client['organization_id'],
                $subtotal, $subtotal, (string)$request['request']['scopeSummary'],
                json_encode(['integration' => ['source' => $request['source'], 'requestPublicId' => $request['request']['publicId'], 'revision' => $request['request']['revision'], 'authorization'=>$request['authorization'], 'services'=>$request['services'], 'workArea'=>$request['workArea'], 'attachments'=>$request['attachments']]], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ]);
            $quoteId = (int)$pdo->lastInsertId();
            require_once __DIR__ . '/../utils/quote_numbers.php';
            $docNumber = pa_next_quote_doc_number($pdo,'regular');
            $pdo->prepare('UPDATE quotes SET doc_number=? WHERE id=?')->execute([$docNumber, $quoteId]);
            $line = $pdo->prepare('INSERT INTO quote_items (quote_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,pricing_status,sort_order,catalog_snapshot) VALUES (?,?,?,?,1,?,?,?,?,?,?)');
            foreach ($pricing['lines'] as $index => $lineItem) {
                $service=$services[$index];
                $line->execute([
                    $quoteId, $lineItem['item_library_id'], $lineItem['item'], $lineItem['description'],
                    $lineItem['unit_price'], $lineItem['line_total'], $lineItem['billing_unit'], $lineItem['pricing_status'], $index,
                    json_encode($lineItem['catalog_snapshot']+['answers'=>$request['services'][$index]['answers']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                ]);
            }
            pricing_finalize_document_revision(
                $pdo,
                $client['organization_id'] === null ? null : (int)$client['organization_id'],
                'quote',
                $quoteId,
                null,
                false,
                (string)($pricing['currency'] ?? 'USD')
            );
            $receiptPublicId = bin2hex(random_bytes(16));
            $response = [
                'receiptId' => $receiptPublicId,
                'draftQuote' => [
                    'publicId' => $publicId,
                    'documentNumber' => 'Q-DRAFT-' . $docNumber,
                    'status' => 'draft',
                    'version' => 1,
                    'editorPath' => '/quotes/' . rawurlencode($publicId) . '/edit',
                ],
            ];
            $pdo->prepare('INSERT INTO portal_draft_quote_commands (integration_profile_id,api_key_id,idempotency_hash,payload_hash,receipt_public_id,quote_id,response_json) VALUES (?,?,?,?,?,?,?)')
                ->execute([(int)$profile['id'], $apiKeyId, $idempotencyHash, $payloadHash, $receiptPublicId, $quoteId, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            (new PortalIntegrationAuditService())->recordCommand($pdo,$applicationKey,$apiKeyId,PortalIntegrationContract::DRAFT_SCOPE,'allowed',$correlationId,null,'quote',$publicId,['receipt_id'=>$receiptPublicId,'source_request_id'=>$request['request']['publicId']]);
            if ($ownsTransaction) $pdo->commit();
            return ['status' => 201, 'body' => $response];
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @return array{schemaVersion:int,sourceGeneration:string,items:list<array<string,mixed>>} */
    public function catalog(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT portal_public_id,portal_source_version,item_name,portal_summary,portal_category,portal_display_order,portal_geometry_requirement,portal_questions_json FROM item_library WHERE portal_requestable=1 AND is_active=1 AND entry_type='service' ORDER BY portal_display_order,portal_public_id")->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $row) {
            $item = [
                'publicId' => (string)$row['portal_public_id'],
                'name' => (string)$row['item_name'], 'summary' => $row['portal_summary']!==null?(string)$row['portal_summary']:null,
                'category' => (string)$row['portal_category'], 'displayOrder' => (int)$row['portal_display_order'],
                'geometryRequirement' => (string)$row['portal_geometry_requirement'],
                'questions' => $row['portal_questions_json'] ? json_decode((string)$row['portal_questions_json'], true, 32, JSON_THROW_ON_ERROR) : [],
            ];
            $item=['publicId'=>$item['publicId'],'sourceVersion'=>PortalSourceVersion::from($item)]+array_slice($item,1,null,true);
            PortalIntegrationContract::validateCatalogItem($item);
            $items[] = $item;
        }
        $fingerprint = hash('sha256', json_encode($items, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return ['schemaVersion' => 2, 'sourceGeneration' => 'catalog-' . substr($fingerprint, 0, 24), 'items' => $items];
    }

    /** @param list<array<string,mixed>> $requested @return list<array<string,mixed>> */
    private function resolveServices(PDO $pdo, array $requested, string $versionField): array
    {
        $statement = $pdo->prepare("SELECT * FROM item_library WHERE portal_public_id=? AND portal_requestable=1 AND is_active=1 AND entry_type='service'");
        $resolved = [];
        foreach ($requested as $item) {
            $statement->execute([(string)$item['publicId']]);
            $service = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$service) throw new DomainException('stale-catalog');
            $visible=['publicId'=>(string)$service['portal_public_id'],'name'=>(string)$service['item_name'],'summary'=>$service['portal_summary']!==null?(string)$service['portal_summary']:null,'category'=>(string)$service['portal_category'],'displayOrder'=>(int)$service['portal_display_order'],'geometryRequirement'=>(string)$service['portal_geometry_requirement'],'questions'=>$service['portal_questions_json']?json_decode((string)$service['portal_questions_json'],true,32,JSON_THROW_ON_ERROR):[]];
            $effectiveVersion=PortalSourceVersion::from($visible);
            if(!hash_equals($effectiveVersion,(string)$item[$versionField]))throw new DomainException('stale-catalog');
            $service['portal_source_version']=$effectiveVersion;
            if ($resolved !== [] && $service['pricing_currency'] !== $resolved[0]['pricing_currency']) throw new DomainException('mixed-currency-policy');
            $resolved[] = $service;
        }
        return $resolved;
    }

    /** @return array<string,mixed> */
    private function authorizedProject(PDO $pdo, int $profileId, string $rootType, string $rootPublicId, string $projectPublicId): array
    {
        (new PortalWorkspaceAuthorizationService())->requireRoot($pdo, $profileId, $rootType, $rootPublicId);
        $visibility = ProjectLifecycleSchema::visibility($pdo, 'p', true);
        if ($rootType === 'organization') {
            $statement = $pdo->prepare("SELECT p.* FROM projects p JOIN organizations o ON o.id=p.organization_id WHERE p.public_id=? AND o.public_id=? AND p.status<>'cancelled' AND {$visibility}");
        } else {
            $statement = $pdo->prepare("SELECT p.* FROM projects p JOIN clients c ON c.id=p.client_id WHERE p.public_id=? AND c.public_id=? AND c.organization_id IS NULL AND p.status<>'cancelled' AND {$visibility}");
        }
        $statement->execute([$projectPublicId, $rootPublicId]);
        $project = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$project) throw new DomainException('scope-denied');
        return $project;
    }

    /** @return array<string,mixed> */
    private function authorizedClient(PDO $pdo, int $profileId, string $clientPublicId, mixed $organizationPublicId): array
    {
        if ($organizationPublicId === null) {
            (new PortalWorkspaceAuthorizationService())->requireRoot($pdo, $profileId, 'standalone_client', $clientPublicId);
            $statement = $pdo->prepare('SELECT * FROM clients WHERE public_id=? AND organization_id IS NULL AND archived=0 AND deleted_at IS NULL');
            $statement->execute([$clientPublicId]);
        } else {
            (new PortalWorkspaceAuthorizationService())->requireRoot($pdo, $profileId, 'organization', (string)$organizationPublicId);
            $statement = $pdo->prepare('SELECT c.* FROM clients c JOIN organizations o ON o.id=c.organization_id WHERE c.public_id=? AND o.public_id=? AND c.archived=0 AND c.deleted_at IS NULL');
            $statement->execute([$clientPublicId, (string)$organizationPublicId]);
        }
        $client = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$client) throw new DomainException('scope-denied');
        return $client;
    }

    /** @param list<array<string,mixed>> $services */
    private function catalogVersion(array $services): string
    {
        return 'catalog-' . substr(hash('sha256', json_encode(array_map(fn($s) => [$s['portal_public_id'], $s['portal_source_version']], $services), JSON_THROW_ON_ERROR)), 0, 24);
    }


}
