<?php
declare(strict_types=1);
namespace App\Services;
require_once __DIR__.'/EncryptedLocalOperatorKeyReceipt.php';

/** Separate key environment and authenticated domain from snapshot receipts. */
final class EncryptedDirectoryInventoryKeyReceipt extends EncryptedLocalOperatorKeyReceipt
{
    public const KEY_ENV = 'PA_DIRECTORY_INVENTORY_RECEIPT_KEY_B64';
    protected const FORMAT = 'pa.directory-inventory-key.encrypted';
}
