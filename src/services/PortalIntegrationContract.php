<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use DateTimeImmutable;
use DateTimeZone;

/** Strict, provider-neutral parsers for the public portal integration wire contracts. */
final class PortalIntegrationContract
{
    public const CATALOG_SCOPE = 'portal.catalog.publish';
    public const PRICING_SCOPE = 'portal.pricing.preview';
    public const DRAFT_SCOPE = 'portal.quote-draft.create';
    public const DISCLAIMER = 'Planning guidance only. Final quote after staff review.';

    /** @param array<string,mixed> $delivery */
    public static function validatePortalDelivery(array $delivery,bool $relationsEnabled,bool $contactAssignmentsEnabled=false):void
    {
        $version=$delivery['schemaVersion']??null;
        if($version!==2&&!(($version===3&&$relationsEnabled)||($version===4&&$relationsEnabled&&$contactAssignmentsEnabled)))throw new DomainException('portal-envelope-invalid');
        $common=['schemaVersion','applicationKey','deliveryId','occurredAt','sourceGeneration','sourceSequence','workspaceId','kind'];
        foreach($common as$key)if(!array_key_exists($key,$delivery))throw new DomainException('portal-envelope-invalid');
        self::opaqueId($delivery['workspaceId'],'portal-public-id-invalid');self::opaqueId($delivery['deliveryId'],'portal-delivery-id-invalid');self::boundedString($delivery['sourceGeneration'],1,191,'portal-generation-invalid');self::integer($delivery['sourceSequence'],0,PHP_INT_MAX,'portal-sequence-invalid');self::boundedString($delivery['applicationKey'],2,64,'portal-application-key-invalid');self::timestamp($delivery['occurredAt'],'portal-occurred-at-invalid');
        $kind=$delivery['kind'];
        if($kind==='snapshot.activate'){
            self::exactKeys($delivery,array_merge($common,['snapshotHash','pageCount','recordCount']),'portal-snapshot-activate-invalid');self::sha256($delivery['snapshotHash'],'portal-snapshot-hash-invalid');self::integer($delivery['pageCount'],1,100,'portal-page-count-invalid');self::integer($delivery['recordCount'],0,2000,'portal-record-count-invalid');return;
        }
        if($kind==='snapshot.page'){
            $keys=array_merge($common,['snapshotHash','pageNumber','pageCount','recordCount','workspace','entities','principals','entitlements']);if($version>=3)$keys=array_merge($keys,['relations','projectLifecycles']);if($version===4)$keys[]='contactAssignments';self::exactKeys($delivery,$keys,'portal-snapshot-page-invalid');
            self::sha256($delivery['snapshotHash'],'portal-snapshot-hash-invalid');self::integer($delivery['pageNumber'],1,100,'portal-page-number-invalid');self::integer($delivery['pageCount'],1,100,'portal-page-count-invalid');self::integer($delivery['recordCount'],0,2000,'portal-record-count-invalid');if($delivery['pageNumber']>$delivery['pageCount'])throw new DomainException('portal-page-number-invalid');
            $workspace=self::arrayValue($delivery['workspace'],'portal-workspace-invalid');self::exactKeys($workspace,['publicId','rootType','rootPublicId','displayName','sourceVersion','active'],'portal-workspace-fields-invalid');self::opaqueId($workspace['publicId'],'portal-public-id-invalid');self::opaqueId($workspace['rootPublicId'],'portal-public-id-invalid');if(!in_array($workspace['rootType'],['organization','standalone_client'],true)||!is_bool($workspace['active']))throw new DomainException('portal-workspace-fields-invalid');self::plainText($workspace['displayName'],1,150,false,'portal-workspace-fields-invalid');self::boundedString($workspace['sourceVersion'],1,191,'portal-workspace-fields-invalid');
            foreach(self::listValue($delivery['entities'],0,100,'portal-entities-invalid')as$entity){$entity=self::arrayValue($entity,'portal-entity-invalid');self::exactKeys($entity,['type','publicId','parentPublicId','displayName','sourceVersion','active','primaryContact'],'portal-entity-fields-invalid');self::opaqueId($entity['publicId'],'portal-public-id-invalid');if($entity['parentPublicId']!==null)self::opaqueId($entity['parentPublicId'],'portal-public-id-invalid');if($entity['type']!=='contact'&&!empty($entity['primaryContact']))throw new DomainException('portal-primary-contact-invalid');}
            foreach(self::listValue($delivery['principals'],0,100,'portal-principals-invalid')as$principal){$principal=self::arrayValue($principal,'portal-principal-invalid');self::exactKeys($principal,['publicId','emailHint','displayName','sourceVersion','active'],'portal-principal-fields-invalid');self::opaqueId($principal['publicId'],'portal-public-id-invalid');}
            foreach(self::listValue($delivery['entitlements'],0,100,'portal-entitlements-invalid')as$entitlement)self::validateEntitlement($entitlement);
            if($version>=3){foreach(self::listValue($delivery['relations'],0,100,'portal-relations-invalid')as$relation)self::validateRelation($relation);foreach(self::listValue($delivery['projectLifecycles'],0,100,'portal-lifecycles-invalid')as$lifecycle)self::validateLifecycle($lifecycle);}
            if($version===4)foreach(self::listValue($delivery['contactAssignments'],0,100,'portal-contact-assignments-invalid')as$assignment)self::validateContactAssignment($assignment);
            $pageRecords=count($delivery['entities'])+count($delivery['principals'])+count($delivery['entitlements'])+($version>=3?count($delivery['relations'])+count($delivery['projectLifecycles']):0)+($version===4?count($delivery['contactAssignments']):0);
            if($pageRecords>100)throw new DomainException('portal-snapshot-page-invalid');
            return;
        }
        if($kind!=='event')throw new DomainException('portal-envelope-invalid');self::exactKeys($delivery,array_merge($common,['event']),'portal-event-fields-invalid');$event=self::arrayValue($delivery['event'],'portal-event-invalid');
        $resource=$event['resource']??null;$action=$event['action']??null;
        $resources=['workspace','entity','principal','entitlement'];if($version>=3)$resources=array_merge($resources,['relation','project_lifecycle']);if($version===4)$resources[]='contact_assignment';
        if(!in_array($resource,$resources,true)||!in_array($action,['upsert','tombstone'],true))throw new DomainException('portal-event-fields-invalid');
        if($action==='tombstone'){if($resource==='project_lifecycle')throw new DomainException('portal-event-fields-invalid');self::exactKeys($event,['resource','action','publicId','sourceVersion'],'portal-event-fields-invalid');self::opaqueId($event['publicId'],'portal-public-id-invalid');self::boundedString($event['sourceVersion'],1,191,'portal-source-version-invalid');return;}
        $field=match($resource){'workspace'=>'workspace','entity'=>'entity','principal'=>'principal','entitlement'=>'entitlement','relation'=>'relation','project_lifecycle'=>'projectLifecycle','contact_assignment'=>'contactAssignment'};self::exactKeys($event,['resource','action',$field],'portal-event-fields-invalid');$record=self::arrayValue($event[$field],'portal-event-fields-invalid');
        if($resource==='relation')self::validateRelation($record);elseif($resource==='project_lifecycle')self::validateLifecycle($record);elseif($resource==='contact_assignment')self::validateContactAssignment($record);elseif($resource==='principal'){self::exactKeys($record,['publicId','emailHint','displayName','sourceVersion','active'],'portal-principal-fields-invalid');self::opaqueId($record['publicId'],'portal-public-id-invalid');}elseif($resource==='entity'){self::exactKeys($record,['type','publicId','parentPublicId','displayName','sourceVersion','active','primaryContact'],'portal-entity-fields-invalid');self::opaqueId($record['publicId'],'portal-public-id-invalid');}elseif($resource==='workspace'){self::exactKeys($record,['publicId','rootType','rootPublicId','displayName','sourceVersion','active'],'portal-workspace-fields-invalid');self::opaqueId($record['publicId'],'portal-public-id-invalid');}else self::validateEntitlement($record);
    }

    /** @param array<string,mixed> $delivery */
    public static function validateCatalogDelivery(array $delivery):void
    {
        $common=['schemaVersion','applicationKey','deliveryId','occurredAt','sourceGeneration','sourceSequence','kind'];
        foreach($common as$key)if(!array_key_exists($key,$delivery))throw new DomainException('catalog-envelope-invalid');
        if(($delivery['schemaVersion']??null)!==2)throw new DomainException('catalog-envelope-invalid');
        self::boundedString($delivery['applicationKey'],2,64,'catalog-application-key-invalid');self::opaqueId($delivery['deliveryId'],'catalog-delivery-id-invalid');self::timestamp($delivery['occurredAt'],'catalog-occurred-at-invalid');self::boundedString($delivery['sourceGeneration'],1,191,'catalog-generation-invalid');self::integer($delivery['sourceSequence'],1,PHP_INT_MAX,'catalog-sequence-invalid');
        $kind=$delivery['kind']??null;
        if($kind==='snapshot.page'){
            self::exactKeys($delivery,array_merge($common,['snapshotHash','pageNumber','pageCount','itemCount','items']),'catalog-snapshot-page-invalid');self::sha256($delivery['snapshotHash'],'catalog-snapshot-hash-invalid');self::integer($delivery['pageNumber'],1,100,'catalog-page-number-invalid');self::integer($delivery['pageCount'],1,100,'catalog-page-count-invalid');self::integer($delivery['itemCount'],0,500,'catalog-item-count-invalid');if($delivery['pageNumber']>$delivery['pageCount'])throw new DomainException('catalog-page-number-invalid');foreach(self::listValue($delivery['items'],0,50,'catalog-items-invalid')as$item)self::validateCatalogItem(self::arrayValue($item,'catalog-item-invalid'));return;
        }
        if($kind==='snapshot.activate'){
            self::exactKeys($delivery,array_merge($common,['snapshotHash','pageCount','itemCount']),'catalog-snapshot-activate-invalid');self::sha256($delivery['snapshotHash'],'catalog-snapshot-hash-invalid');self::integer($delivery['pageCount'],1,100,'catalog-page-count-invalid');self::integer($delivery['itemCount'],0,500,'catalog-item-count-invalid');return;
        }
        if($kind==='event'){
            self::exactKeys($delivery,array_merge($common,['event']),'catalog-event-invalid');$event=self::arrayValue($delivery['event'],'catalog-event-invalid');$action=$event['action']??null;
            if($action==='upsert'){self::exactKeys($event,['action','item'],'catalog-event-fields-invalid');self::validateCatalogItem(self::arrayValue($event['item'],'catalog-item-invalid'));return;}
            if($action==='tombstone'){self::exactKeys($event,['action','publicId','sourceVersion'],'catalog-event-fields-invalid');self::opaqueId($event['publicId'],'catalog-item-id-invalid');self::boundedString($event['sourceVersion'],1,191,'catalog-item-version-invalid');return;}
            throw new DomainException('catalog-event-action-invalid');
        }
        throw new DomainException('catalog-kind-invalid');
    }

    /** @param array<string,mixed> $response */
    public static function validatePricingResponse(array$response):void
    {
        self::exactKeys($response,['schemaVersion','catalogVersion','coverageSquareMetres','displayMode','currency','startingAt','typicalMinimum','typicalMaximum','reasonUnavailable','disclaimer','validUntil'],'pricing-response-fields-invalid');if(($response['schemaVersion']??null)!==1||!in_array($response['displayMode'],['none','starting_at','typical_range'],true)||($response['disclaimer']??null)!==self::DISCLAIMER)throw new DomainException('pricing-response-invalid');self::boundedString($response['catalogVersion'],1,191,'pricing-response-catalog-version-invalid');self::decimal($response['coverageSquareMetres'],6,'pricing-response-coverage-invalid');self::timestamp($response['validUntil'],'pricing-response-valid-until-invalid');if($response['currency']!==null&&(!is_string($response['currency'])||preg_match('/^[A-Z]{3}$/D',$response['currency'])!==1))throw new DomainException('pricing-response-currency-invalid');if($response['reasonUnavailable']!==null)self::plainText($response['reasonUnavailable'],1,500,true,'pricing-response-reason-invalid');
        $money=static fn($v)=>$v===null||(is_string($v)&&preg_match('/^(?:0|[1-9][0-9]{0,15})\.[0-9]{2}$/D',$v)===1);if(!$money($response['startingAt'])||!$money($response['typicalMinimum'])||!$money($response['typicalMaximum']))throw new DomainException('pricing-response-money-invalid');if($response['displayMode']==='none'&&($response['currency']!==null||$response['startingAt']!==null||$response['typicalMinimum']!==null||$response['typicalMaximum']!==null||$response['reasonUnavailable']===null))throw new DomainException('pricing-response-mode-invalid');if($response['displayMode']==='starting_at'&&($response['startingAt']===null||$response['currency']===null||$response['typicalMinimum']!==null||$response['typicalMaximum']!==null||$response['reasonUnavailable']!==null))throw new DomainException('pricing-response-mode-invalid');if($response['displayMode']==='typical_range'&&($response['typicalMinimum']===null||$response['typicalMaximum']===null||$response['currency']===null||$response['startingAt']!==null||$response['reasonUnavailable']!==null))throw new DomainException('pricing-response-mode-invalid');if($response['typicalMinimum']!==null&&$response['typicalMaximum']!==null&&self::decimalCompare($response['typicalMinimum'],$response['typicalMaximum'])>0)throw new DomainException('pricing-response-range-invalid');
    }

    /** @param array<string,mixed> $response */
    public static function validateDraftResponse(array$response):void
    {
        self::exactKeys($response,['receiptId','draftQuote'],'draft-response-fields-invalid');self::opaqueId($response['receiptId'],'draft-receipt-id-invalid');$quote=self::arrayValue($response['draftQuote'],'draft-response-invalid');self::exactKeys($quote,['publicId','documentNumber','status','version','editorPath'],'draft-response-fields-invalid');self::opaqueId($quote['publicId'],'draft-quote-id-invalid');if($quote['status']!=='draft'||!is_int($quote['version'])||$quote['version']<1)throw new DomainException('draft-response-state-invalid');$expected='/quotes/'.rawurlencode($quote['publicId']).'/edit';if($quote['editorPath']!==$expected)throw new DomainException('draft-response-editor-path-invalid');if($quote['documentNumber']!==null)self::boundedString($quote['documentNumber'],1,100,'draft-response-number-invalid');
    }

    /** @param array<string,mixed> $body */
    public static function validateDraftErrorResponse(int $status,array $body):void
    {
        $expected=[409=>['IDEMPOTENCY_CONFLICT','STALE_CATALOG'],403=>['SCOPE_DENIED']];
        self::exactKeys($body,['code'],'draft-error-fields-invalid');
        if(!isset($expected[$status])||!is_string($body['code'])||!in_array($body['code'],$expected[$status],true))throw new DomainException('draft-error-response-invalid');
    }

    private static function validateRelation(mixed$value):void{$relation=self::arrayValue($value,'portal-relation-invalid');self::exactKeys($relation,['publicId','relationType','from','to','sourceVersion','active'],'portal-relation-fields-invalid');$from=self::arrayValue($relation['from'],'portal-relation-shape-invalid');$to=self::arrayValue($relation['to'],'portal-relation-shape-invalid');self::exactKeys($from,['type','publicId'],'portal-relation-shape-invalid');self::exactKeys($to,['type','publicId'],'portal-relation-shape-invalid');$direction=$from['type'].'->'.$to['type'];$allowed=$relation['relationType']==='contains'?['organization->department','organization->client','organization->project','standalone_client->project','department->project','client->project']:array_map(static fn($s)=>$s.'->contact',['organization','standalone_client','department','client','project']);if(!in_array($direction,$allowed,true))throw new DomainException('portal-relation-shape-invalid');}
    private static function validateLifecycle(mixed$value):void{$lifecycle=self::arrayValue($value,'portal-project-lifecycle-invalid');self::exactKeys($lifecycle,['projectPublicId','status','completedAt','sourceVersion'],'portal-project-lifecycle-invalid');if(!in_array($lifecycle['status'],['active','completed'],true)||($lifecycle['status']==='active'&&$lifecycle['completedAt']!==null)||($lifecycle['status']==='completed'&&$lifecycle['completedAt']===null))throw new DomainException('portal-project-lifecycle-invalid');}
    private static function validateContactAssignment(mixed$value):void{$assignment=self::arrayValue($value,'portal-contact-assignment-invalid');self::exactKeys($assignment,['publicId','contactPublicId','clientPublicId','scopeType','scopePublicId','role','primary','primaryBilling','sendProjectInvoices','canViewInvoiceLinks','sourceVersion','active'],'portal-contact-assignment-fields-invalid');foreach(['publicId','contactPublicId','clientPublicId','scopePublicId']as$key)self::opaqueId($assignment[$key]??null,'portal-public-id-invalid');if(!in_array($assignment['scopeType'],['department','project'],true)||!is_string($assignment['role'])||preg_match('/^[a-z][a-z0-9_.:-]{0,49}$/D',$assignment['role'])!==1)throw new DomainException('portal-contact-assignment-invalid');foreach(['primary','primaryBilling','sendProjectInvoices','canViewInvoiceLinks','active']as$key)if(!is_bool($assignment[$key]??null))throw new DomainException('portal-contact-assignment-invalid');if($assignment['scopeType']==='project'&&$assignment['primary'])throw new DomainException('portal-contact-assignment-primary-scope-invalid');if($assignment['scopeType']!=='project'&&($assignment['primaryBilling']||$assignment['sendProjectInvoices']||$assignment['canViewInvoiceLinks']))throw new DomainException('portal-contact-assignment-billing-scope-invalid');self::boundedString($assignment['sourceVersion'],1,191,'portal-source-version-invalid');}
    private static function validateEntitlement(mixed$value):void{$entitlement=self::arrayValue($value,'portal-entitlement-invalid');self::exactKeys($entitlement,['publicId','principalPublicId','capability','effect','scopeType','scopePublicId','sourceVersion','active','validFrom','expiresAt'],'portal-entitlement-fields-invalid');self::opaqueId($entitlement['publicId'],'portal-public-id-invalid');self::opaqueId($entitlement['principalPublicId'],'portal-public-id-invalid');self::opaqueId($entitlement['scopePublicId'],'portal-public-id-invalid');if(!in_array($entitlement['capability'],['workspace.view','directory.read','delivery.view','request.create','member.manage','delegated_share.create','viewer.share.create'],true)||!in_array($entitlement['effect'],['allow','deny'],true)||!in_array($entitlement['scopeType'],['workspace','organization','department','client','project'],true)||!is_bool($entitlement['active']))throw new DomainException('portal-entitlement-invalid');self::boundedString($entitlement['sourceVersion'],1,191,'portal-source-version-invalid');self::timestamp($entitlement['validFrom'],'portal-entitlement-window-invalid');if($entitlement['expiresAt']!==null){self::timestamp($entitlement['expiresAt'],'portal-entitlement-window-invalid');if(strcmp((string)$entitlement['expiresAt'],(string)$entitlement['validFrom'])<=0)throw new DomainException('portal-entitlement-window-invalid');}}

    /** @param array<string,mixed> $request */
    public static function validatePricingRequest(array $request): void
    {
        self::exactKeys($request, ['schemaVersion','source','scope','authorizationContext','coverageSquareMetres','services'], 'pricing-request-fields-invalid');
        if (($request['schemaVersion'] ?? null) !== 1 || ($request['scope'] ?? null) !== self::PRICING_SCOPE) {
            throw new DomainException('pricing-request-version-invalid');
        }
        self::boundedString($request['source'] ?? null, 1, 100, 'pricing-source-invalid');
        self::decimal($request['coverageSquareMetres'] ?? null, 6, 'pricing-coverage-invalid');
        $authorization = self::arrayValue($request['authorizationContext'] ?? null, 'pricing-authorization-invalid');
        self::exactKeys($authorization, ['workspaceRoot','projectPublicId'], 'pricing-authorization-fields-invalid');
        $root = self::arrayValue($authorization['workspaceRoot'] ?? null, 'pricing-workspace-root-invalid');
        self::exactKeys($root, ['type','publicId'], 'pricing-workspace-root-fields-invalid');
        if (!in_array($root['type'] ?? null, ['organization','standalone_client'], true)) {
            throw new DomainException('pricing-workspace-root-type-invalid');
        }
        self::opaqueId($root['publicId'] ?? null, 'pricing-workspace-root-id-invalid');
        self::opaqueId($authorization['projectPublicId'] ?? null, 'pricing-project-id-invalid');
        $services = self::listValue($request['services'] ?? null, 1, 10, 'pricing-services-invalid');
        $seen = [];
        foreach ($services as $service) {
            $service = self::arrayValue($service, 'pricing-service-invalid');
            self::exactKeys($service, ['publicId','sourceVersion'], 'pricing-service-fields-invalid');
            self::opaqueId($service['publicId'] ?? null, 'pricing-service-id-invalid');
            self::boundedString($service['sourceVersion'] ?? null, 1, 191, 'pricing-service-version-invalid');
            if (isset($seen[$service['publicId']])) {
                throw new DomainException('pricing-service-duplicate');
            }
            $seen[$service['publicId']] = true;
        }
    }

    /** @param array<string,mixed> $request */
    public static function validateDraftRequest(array $request): void
    {
        self::exactKeys($request, ['schemaVersion','source','request','authorization','services','workArea','attachments'], 'draft-request-fields-invalid');
        if (($request['schemaVersion'] ?? null) !== 1) {
            throw new DomainException('draft-request-version-invalid');
        }
        $summary = self::arrayValue($request['request'] ?? null, 'draft-summary-invalid');
        self::exactKeys($summary, ['publicId','revision','title','scopeSummary','deliverablesSummary'], 'draft-summary-fields-invalid');
        self::opaqueId($summary['publicId'] ?? null, 'draft-request-id-invalid');
        self::integer($summary['revision'] ?? null, 0, 2147483647, 'draft-revision-invalid');
        self::boundedString($summary['title'] ?? null, 1, 255, 'draft-title-invalid');
        self::boundedString($summary['scopeSummary'] ?? null, 1, 5000, 'draft-scope-invalid');
        self::nullableBoundedString($summary['deliverablesSummary'] ?? null, 2000, 'draft-deliverables-invalid');

        $authorization = self::arrayValue($request['authorization'] ?? null, 'draft-authorization-invalid');
        self::exactKeys($authorization, ['organizationPublicId','clientPublicId','projectPublicId'], 'draft-authorization-fields-invalid');
        self::nullableOpaqueId($authorization['organizationPublicId'] ?? null, 'draft-organization-id-invalid');
        self::opaqueId($authorization['clientPublicId'] ?? null, 'draft-client-id-invalid');
        self::nullableOpaqueId($authorization['projectPublicId'] ?? null, 'draft-project-id-invalid');

        $services = self::listValue($request['services'] ?? null, 1, 10, 'draft-services-invalid');
        $seen = [];
        foreach ($services as $service) {
            $service = self::arrayValue($service, 'draft-service-invalid');
            self::exactKeys($service, ['publicId','catalogVersion','answers'], 'draft-service-fields-invalid');
            self::opaqueId($service['publicId'] ?? null, 'draft-service-id-invalid');
            self::boundedString($service['catalogVersion'] ?? null, 1, 191, 'draft-catalog-version-invalid');
            if (!is_array($service['answers'] ?? null) || array_is_list($service['answers'])) {
                throw new DomainException('draft-service-answers-invalid');
            }
            if (strlen(json_encode($service['answers'], JSON_THROW_ON_ERROR)) > 8192) {
                throw new DomainException('draft-service-answers-too-large');
            }
            if (isset($seen[$service['publicId']])) {
                throw new DomainException('draft-service-duplicate');
            }
            $seen[$service['publicId']] = true;
        }

        $area = self::arrayValue($request['workArea'] ?? null, 'draft-area-invalid');
        self::exactKeys($area, ['revision','hash','squareMeters','acres'], 'draft-area-fields-invalid');
        self::integer($area['revision'] ?? null, 0, 2147483647, 'draft-area-revision-invalid');
        if (!is_string($area['hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $area['hash']) !== 1) {
            throw new DomainException('draft-area-hash-invalid');
        }
        $hasSquareMetres = $area['squareMeters'] !== null;
        $hasAcres = $area['acres'] !== null;
        if ($hasSquareMetres !== $hasAcres) {
            throw new DomainException('draft-area-nullability-invalid');
        }
        if ($hasSquareMetres) {
            self::finiteNonNegativeNumber($area['squareMeters'], 'draft-square-metres-invalid');
            self::finiteNonNegativeNumber($area['acres'], 'draft-acres-invalid');
        }

        $attachments = self::listValue($request['attachments'] ?? null, 0, 25, 'draft-attachments-invalid');
        foreach ($attachments as $attachment) {
            $attachment = self::arrayValue($attachment, 'draft-attachment-invalid');
            self::exactKeys($attachment, ['name','contentType','sizeBytes','sha256'], 'draft-attachment-fields-invalid');
            self::boundedString($attachment['name'] ?? null, 1, 255, 'draft-attachment-name-invalid');
            self::boundedString($attachment['contentType'] ?? null, 1, 127, 'draft-attachment-type-invalid');
            self::integer($attachment['sizeBytes'] ?? null, 0, 1073741824, 'draft-attachment-size-invalid');
            if (!is_string($attachment['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $attachment['sha256']) !== 1) {
                throw new DomainException('draft-attachment-sha-invalid');
            }
        }
    }

    /** @param array<string,mixed> $item */
    public static function validateCatalogItem(array $item): void
    {
        self::exactKeys($item, ['publicId','sourceVersion','name','summary','category','displayOrder','geometryRequirement','questions'], 'catalog-item-fields-invalid');
        self::opaqueId($item['publicId'] ?? null, 'catalog-item-id-invalid');
        self::boundedString($item['sourceVersion'] ?? null, 1, 191, 'catalog-item-version-invalid');
        self::plainText($item['name'] ?? null, 1, 255, false, 'catalog-item-name-invalid');
        self::plainText($item['summary'] ?? null, 1, 1000, true, 'catalog-item-summary-invalid');
        self::plainText($item['category'] ?? null, 1, 100, false, 'catalog-item-category-invalid');
        self::integer($item['displayOrder'] ?? null, 0, 1000000, 'catalog-item-order-invalid');
        if (!in_array($item['geometryRequirement'] ?? null, ['none','optional','required'], true)) {
            throw new DomainException('catalog-item-geometry-invalid');
        }
        $questions = self::listValue($item['questions'] ?? null, 0, 10, 'catalog-questions-invalid');
        $questionIds = [];
        foreach ($questions as $question) {
            $question = self::arrayValue($question, 'catalog-question-invalid');
            $allowed = ['id','label','type','required','helpText','options','minimum','maximum'];
            if (array_diff(array_keys($question), $allowed) !== []) {
                throw new DomainException('catalog-question-fields-invalid');
            }
            foreach (['id','label','type','required'] as $required) {
                if (!array_key_exists($required, $question)) {
                    throw new DomainException('catalog-question-fields-invalid');
                }
            }
            if (preg_match('/^[a-z][a-z0-9_:-]{0,63}$/D', (string)$question['id']) !== 1 || isset($questionIds[$question['id']])) {
                throw new DomainException('catalog-question-id-invalid');
            }
            $questionIds[$question['id']] = true;
            self::plainText($question['label'], 1, 200, false, 'catalog-question-label-invalid');
            self::plainText($question['helpText'] ?? null, 1, 500, true, 'catalog-question-help-invalid');
            if (!in_array($question['type'], ['text','number','boolean','select','multi-select'], true) || !is_bool($question['required'])) {
                throw new DomainException('catalog-question-type-invalid');
            }
            $selectType = in_array($question['type'], ['select','multi-select'], true);
            if ($selectType !== array_key_exists('options', $question)) {
                throw new DomainException('catalog-question-options-invalid');
            }
            if ($selectType) {
                $options = self::listValue($question['options'], 1, 50, 'catalog-question-options-invalid');
                $optionValues = [];
                foreach ($options as $option) {
                    $option = self::arrayValue($option, 'catalog-question-option-invalid');
                    self::exactKeys($option, ['value','label'], 'catalog-question-option-fields-invalid');
                    self::plainText($option['value'] ?? null, 1, 100, false, 'catalog-question-option-value-invalid');
                    self::plainText($option['label'] ?? null, 1, 200, false, 'catalog-question-option-label-invalid');
                    if (isset($optionValues[$option['value']])) throw new DomainException('catalog-question-option-value-invalid');
                    $optionValues[$option['value']] = true;
                }
            }
            $hasMinimum = array_key_exists('minimum', $question);
            $hasMaximum = array_key_exists('maximum', $question);
            if ($question['type'] !== 'number' && ($hasMinimum || $hasMaximum)) throw new DomainException('catalog-question-range-invalid');
            if ($question['type'] === 'number') {
                foreach (['minimum','maximum'] as $bound) if (array_key_exists($bound,$question) && (!is_int($question[$bound])&&!is_float($question[$bound]) || !is_finite((float)$question[$bound]))) throw new DomainException('catalog-question-range-invalid');
                if ($hasMinimum && $hasMaximum && $question['minimum'] > $question['maximum']) throw new DomainException('catalog-question-range-invalid');
            }
        }
    }

    /** @param string|list<string> $secrets */
    public static function verifySignedRequest(string $applicationKey, string $scope, string $path, string $rawBody, array $server, string|array $secrets): void
    {
        $secrets=is_array($secrets)?array_values(array_filter($secrets,static fn($secret):bool=>is_string($secret)&&strlen($secret)>=32)):[$secrets];
        if ($secrets === [] || strlen((string)$secrets[0]) < 32) {
            throw new DomainException('integration-signing-key-unavailable');
        }
        $timestamp = trim((string)($server['HTTP_X_PORTAL_INTEGRATION_TIMESTAMP'] ?? ''));
        $bodyDigest = strtolower(trim((string)($server['HTTP_X_PORTAL_INTEGRATION_BODY_SHA256'] ?? '')));
        $signature = strtolower(trim((string)($server['HTTP_X_PORTAL_INTEGRATION_SIGNATURE'] ?? '')));
        if (trim((string)($server['HTTP_X_PORTAL_INTEGRATION_APPLICATION_KEY'] ?? '')) !== $applicationKey
            || ($scope === self::PRICING_SCOPE && ($server['HTTP_X_PORTAL_INTEGRATION_SCOPE'] ?? '') !== $scope)
            || preg_match('/^[a-f0-9]{64}$/D', $bodyDigest) !== 1
            || !hash_equals(hash('sha256', $rawBody), $bodyDigest)
            || preg_match('/^sha256=([a-f0-9]{64})$/D', $signature, $match) !== 1) {
            throw new DomainException('integration-signature-invalid');
        }
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $timestamp) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $timestamp, new DateTimeZone('UTC'))
            : false;
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s.v\Z') !== $timestamp || abs(time() - $parsed->getTimestamp()) > 300) {
            throw new DomainException('integration-timestamp-invalid');
        }
        $idempotency = $scope === self::DRAFT_SCOPE ? trim((string)($server['HTTP_IDEMPOTENCY_KEY'] ?? '')) : $scope;
        if ($idempotency === '' || strlen($idempotency) > 255) {
            throw new DomainException('integration-idempotency-key-invalid');
        }
        $input = $timestamp . "\nPOST\n" . $path . "\n" . $idempotency . "\n" . $bodyDigest;
        $valid=false;foreach($secrets as$secret)$valid=hash_equals(hash_hmac('sha256',$input,$secret),$match[1])||$valid;
        if (!$valid) {
            throw new DomainException('integration-signature-invalid');
        }
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function exactKeys(array $value, array $keys, string $error): void
    {
        $actual = array_keys($value);
        sort($actual); sort($keys);
        if ($actual !== $keys) {
            throw new DomainException($error);
        }
    }

    private static function opaqueId(mixed $value, string $error): void
    {
        if (!is_string($value) || strlen($value) < 2 || strlen($value) > 191
            || ctype_digit($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw new DomainException($error);
        }
    }

    private static function nullableOpaqueId(mixed $value, string $error): void
    {
        if ($value !== null) self::opaqueId($value, $error);
    }

    private static function boundedString(mixed $value, int $min, int $max, string $error): void
    {
        if (!is_string($value) || mb_strlen($value) < $min || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new DomainException($error);
        }
    }

    private static function plainText(mixed $value, int $min, int $max, bool $nullable, string $error): void
    {
        if ($value === null && $nullable) return;
        self::boundedString($value, $min, $max, $error);
        if (str_contains($value, '<') || str_contains($value, '>')
            || preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]/u', $value) === 1) {
            throw new DomainException($error);
        }
    }

    private static function nullableBoundedString(mixed $value, int $max, string $error): void
    {
        if ($value !== null) self::boundedString($value, 1, $max, $error);
    }

    private static function decimal(mixed $value, int $scale, string $error): void
    {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,15})\.[0-9]{' . $scale . '}$/D', $value) !== 1) {
            throw new DomainException($error);
        }
    }

    private static function integer(mixed $value, int $min, int $max, string $error): void
    {
        if (!is_int($value) || $value < $min || $value > $max) throw new DomainException($error);
    }

    private static function finiteNonNegativeNumber(mixed $value, string $error): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0) throw new DomainException($error);
    }

    private static function sha256(mixed $value,string $error):void{if(!is_string($value)||preg_match('/^[a-f0-9]{64}$/D',$value)!==1)throw new DomainException($error);}
    private static function timestamp(mixed $value,string $error):void{$parsed=is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D',$value)===1?DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z',$value,new DateTimeZone('UTC')):false;if($parsed===false||$parsed->format('Y-m-d\TH:i:s.v\Z')!==$value)throw new DomainException($error);}
    private static function decimalCompare(string$a,string$b):int{[$aw,$af]=explode('.',$a,2);[$bw,$bf]=explode('.',$b,2);$aw=ltrim($aw,'0')?:'0';$bw=ltrim($bw,'0')?:'0';if(strlen($aw)!==strlen($bw))return strlen($aw)<=>strlen($bw);$whole=strcmp($aw,$bw);return$whole!==0?$whole:strcmp($af,$bf);}

    /** @return array<string,mixed> */
    private static function arrayValue(mixed $value, string $error): array
    {
        if (!is_array($value) || array_is_list($value)) throw new DomainException($error);
        return $value;
    }

    /** @return list<mixed> */
    private static function listValue(mixed $value, int $min, int $max, string $error): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $min || count($value) > $max) throw new DomainException($error);
        return $value;
    }
}
