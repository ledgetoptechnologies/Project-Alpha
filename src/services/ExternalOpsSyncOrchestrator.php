<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Runs one bounded manual synchronization pass for the single External
 * Operations connection.
 *
 * Portal provisioning is not a second integration. This coordinator activates
 * the portal producer from the saved connection, reconciles a restart-safe
 * slice of historical roots, and then drains both event families through the
 * same receiver contract.
 */
final class ExternalOpsSyncOrchestrator
{
    /**
     * @param null|callable(string,list<string>,string,int):array{status:int,body?:string,error?:string} $ordinaryTransport
     * @param null|callable(string,list<string>,string,int):array{status:int,body?:string,error?:string} $portalTransport
     * @return array{
     *   ready:bool,
     *   activation:array{attempted:bool,ready:bool,transition_state:string},
     *   reconciliation:array{ready:bool,considered:int,completed:int,retrying:int,failed:int,remaining:int},
     *   ordinary:array{processed:int,delivered:int,failed:int},
     *   portal:array{processed:int,delivered:int,failed:int,dead_lettered:int}
     * }
     */
    public function run(
        PDO $pdo,
        int $reconcileLimit = 25,
        int $ordinaryLimit = 50,
        int $portalLimit = 50,
        ?callable $ordinaryTransport = null,
        ?callable $portalTransport = null,
        int $maxRuntimeSeconds = 20
    ): array {
        if ($pdo->inTransaction()) {
            throw new \DomainException('External Operations synchronization owns its transactions.');
        }

        $maxRuntimeSeconds = max(2, min(300, $maxRuntimeSeconds));
        $startedAt = microtime(true);
        $deadline = $startedAt + $maxRuntimeSeconds;
        $provisioning = new PortalClientProvisioningService();
        $activation = $provisioning->activateConfiguredConnection($pdo);
        $config = (new ExternalOpsConfigService())->load($pdo);
        $reconciliation = $provisioning->reconcileHistoricalBatch(
            $pdo,
            (string)($config['application_key'] ?? ''),
            $reconcileLimit
        );

        $ordinary = ['processed'=>0,'delivered'=>0,'failed'=>0];
        $portal = ['processed'=>0,'delivered'=>0,'failed'=>0,'dead_lettered'=>0];
        $ready = !empty($config['delivery_ready']) && !empty($reconciliation['ready']);
        if (!$ready) {
            return compact('ready', 'activation', 'reconciliation', 'ordinary', 'portal');
        }

        // Reserve at least half of the remaining request window for portal
        // projections, because this action may have just created them. Stable
        // event IDs make a concurrent scheduled sender safe to replay at the
        // receiver, while both senders retain their normal retry state.
        $remaining = max(0, (int)floor($deadline - microtime(true)));
        if ($remaining > 1) {
            $ordinaryBudget = max(1, (int)floor($remaining / 2));
            $ordinary = (new ExternalOpsOutboxSender())->deliverDue(
                $pdo,
                $config,
                $ordinaryLimit,
                $ordinaryTransport,
                $ordinaryBudget
            );
        }

        $remaining = max(0, (int)floor($deadline - microtime(true)));
        if ($remaining > 0) {
            $portal = (new PortalProjectionOutboxSender())->deliverDue(
                $pdo,
                $portalLimit,
                $portalTransport,
                $remaining
            );
        }

        return compact('ready', 'activation', 'reconciliation', 'ordinary', 'portal');
    }
}
