<?php
declare(strict_types=1);

require_once __DIR__ . '/acl.php';

function pa_on_demand_contract_change_status(PDO $pdo, int $contractId, int $userId, string $from, string $to): void
{
    if ($contractId <= 0 || $userId <= 0 || !in_array([$from, $to], [['active', 'paused'], ['paused', 'active']], true)) {
        throw new DomainException('Invalid contract transition.');
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT created_by, status, signed_pdf_path FROM contracts WHERE id=? AND contract_type=?' . $lock);
        $stmt->execute([$contractId, 'on_demand']);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contract || (string)$contract['status'] !== $from) {
            throw new DomainException('Contract is not in the required state.');
        }
        if ($to === 'active' && trim((string)($contract['signed_pdf_path'] ?? '')) === '') {
            throw new DomainException('Upload signed contract first.');
        }

        $role = acl_user_role($pdo, $userId);
        if (!in_array($role, ['admin', 'owner', 'staff'], true) && (int)$contract['created_by'] !== $userId) {
            throw new DomainException('You do not have access to this contract.');
        }

        $update = $pdo->prepare('UPDATE contracts SET status=? WHERE id=? AND contract_type=? AND status=?'
            . ($to === 'active' ? " AND signed_pdf_path IS NOT NULL AND TRIM(signed_pdf_path)<>''" : ''));
        $update->execute([$to, $contractId, 'on_demand', $from]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('Contract changed during the update.');
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
