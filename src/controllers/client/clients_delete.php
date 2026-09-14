<?php
// src/controllers/clients_delete.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=client/clients-list&error=Invalid%20client');
  exit;
}

// Fetch client
$st = $pdo->prepare('SELECT * FROM clients WHERE id=?');
$st->execute([$id]);
$client = $st->fetch(PDO::FETCH_ASSOC);
if (!$client) {
  header('Location: /?page=client/clients-list&error=Client%20not%20found');
  exit;
}

$projection=new App\Services\PortalProjectionMutationService();$pdo->beginTransaction();
try {
  $beforeScopes=$projection->lockedClientScopes($pdo,$id);
  // Refresh after the locking read so the archived identity and profile fields
  // cannot come from a stale pre-transaction snapshot.
  $st->execute([$id]);
  $client = $st->fetch(PDO::FETCH_ASSOC);
  if (!$client) {
    throw new DomainException('Client not found while preparing the archive.');
  }
  // 1) Archive client basic row
  $affectedPrincipalWorkspaces=(new App\Services\ClientArchivePortalStateService())->archive(
    $pdo,
    $client,
    (int)($_SESSION['user']['id'] ?? 0)
  );

  // Helper to archive a set
  $arch = $pdo->prepare('INSERT INTO archived_entities (client_id, entity_type, entity_id, payload) VALUES (?,?,?,JSON_OBJECT())');
  // We will build payloads manually to avoid JSON encoding issues in SQL; instead use PHP JSON.
  $arch = $pdo->prepare('INSERT INTO archived_entities (client_id, entity_type, entity_id, payload) VALUES (?,?,?,?)');

  // 2) Quotes and items
  $quotes = $pdo->prepare('SELECT * FROM quotes WHERE client_id=?');
  $quotes->execute([$id]);
  $quotes = $quotes->fetchAll(PDO::FETCH_ASSOC);
  foreach ($quotes as $q) {
    $arch->execute([$id, 'quote', (int)$q['id'], json_encode($q, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $qi = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
    $qi->execute([$q['id']]);
    foreach ($qi->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $arch->execute([$id, 'quote_item', (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
  }

  // 3) Contracts and items
  $contracts = $pdo->prepare('SELECT * FROM contracts WHERE client_id=?');
  $contracts->execute([$id]);
  foreach ($contracts->fetchAll(PDO::FETCH_ASSOC) as $co) {
    $arch->execute([$id, 'contract', (int)$co['id'], json_encode($co, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $ci = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
    $ci->execute([$co['id']]);
    foreach ($ci->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $arch->execute([$id, 'contract_item', (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
  }

  // 4) Invoices and items
  $invoices = $pdo->prepare('SELECT * FROM invoices WHERE client_id=?');
  $invoices->execute([$id]);
  foreach ($invoices->fetchAll(PDO::FETCH_ASSOC) as $inv) {
    $arch->execute([$id, 'invoice', (int)$inv['id'], json_encode($inv, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $ii = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=?');
    $ii->execute([$inv['id']]);
    foreach ($ii->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $arch->execute([$id, 'invoice_item', (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
  }

  // 5) Payments (by joining invoices for this client)
  $pay = $pdo->prepare('SELECT p.* FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.client_id=?');
  $pay->execute([$id]);
  foreach ($pay->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $arch->execute([$id, 'payment', (int)$p['id'], json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  }

  // 6) Delete client (will cascade to related tables based on FKs in schema)
  api_v2_directory_record_delete($pdo, 'client', (string)$client['public_id']);
  $delete = $pdo->prepare('DELETE FROM clients WHERE id=?');
  $delete->execute([$id]);
  if ($delete->rowCount() !== 1) throw new DomainException('Client changed while preparing the archive.');
  $projection->afterMutation($pdo,$beforeScopes);
  $projection->queueWorkspaceIds($pdo,$affectedPrincipalWorkspaces);

  $pdo->commit();
  header('Location: /?page=client/clients-list&archived=1');
  exit;
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=client/clients-edit&id='.$id.'&error=Archive%20failed&details='.urlencode($e->getMessage()));
  exit;
}
