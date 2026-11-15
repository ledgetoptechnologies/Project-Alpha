<?php
declare(strict_types=1);
namespace App\Services;
require_once __DIR__.'/OpsSnapshotReadKeyProvisioner.php';
require_once __DIR__.'/EncryptedLocalOperatorKeyReceipt.php';
/** Preserves the operational 926493 snapshot format, key environment and AAD. */
final class EncryptedOpsSnapshotKeyReceipt extends EncryptedLocalOperatorKeyReceipt
{
    public const KEY_ENV = 'PA_OPS_SNAPSHOT_RECEIPT_KEY_B64';
    protected const FORMAT = 'pa.ops-snapshot-key.encrypted';
}
