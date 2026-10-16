<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_capabilities.php';
require_once __DIR__ . '/api_scopes.php';
require_once __DIR__ . '/payment_accounting.php';
require_once __DIR__ . '/financial_reporting_currency.php';

/** Exact calendar dates, at most one year, with no ignored/duplicate query keys. */
function api_v2_financial_summary_period(string $query): array
{
    $values=[];
    foreach(explode('&',$query) as $part){
        if($part==='')continue;
        $pair=explode('=',$part,2);$name=urldecode($pair[0]);
        if(!in_array($name,['from','to'],true)||isset($values[$name]))throw new InvalidArgumentException('Invalid period');
        $values[$name]=urldecode($pair[1]??'');
    }
    foreach(['from','to'] as $name){
        $value=$values[$name]??'';
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)||$value<'1900-01-01')throw new InvalidArgumentException('Invalid period');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Invalid period');
    }
    $days=(int)(new DateTimeImmutable($values['from']))->diff(new DateTimeImmutable($values['to']))->format('%r%a');
    if($days<0||$days>365)throw new InvalidArgumentException('Invalid period');
    return ['from'=>$values['from'],'to'=>$values['to'],'dateBasis'=>'stored-calendar-date'];
}

/** Scope and revocation are rechecked while locking the current identity. */
function api_v2_financial_summary_identity(PDO $pdo,int $keyId,array $headers): ?array
{
    if(!$pdo->inTransaction())throw new LogicException('Transaction required');
    $stmt=$pdo->prepare('SELECT app.application_id,h.source_instance_id,h.history_epoch,k.scopes FROM api_keys k JOIN api_v2_applications app ON app.id=k.api_v2_application_id JOIN api_v2_history_identity h ON h.singleton=1 WHERE k.id=? AND k.revoked_at IS NULL FOR UPDATE');
    $stmt->execute([$keyId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row||!api_v2_identity_is_valid($row)||!api_key_has_scope($row['scopes'],'api.capabilities.read',false)||!api_key_has_scope($row['scopes'],'financial.summary.read',false)||in_array('full',api_normalize_scopes($row['scopes']),true))return null;
    foreach(['sourceInstanceId'=>'source_instance_id','applicationId'=>'application_id','historyEpoch'=>'history_epoch'] as $header=>$column)if(!hash_equals((string)$row[$column],(string)($headers[$header]??'')))return null;
    unset($row['scopes']);return $row;
}

/** MySQL DECIMAL strings stay strings: no float or native-integer conversion. */
function api_v2_financial_summary_decimal(mixed $value): string
{
    $value=(string)$value;
    if(!preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/D',$value,$m))throw new RuntimeException('Invalid accounting amount');
    $whole=ltrim($m[2],'0');$whole=$whole===''?'0':$whole;$fraction=str_pad($m[3]??'',2,'0');
    return ($m[1]==='-'&&($whole!=='0'||$fraction!=='00')?'-':'').$whole.'.'.$fraction;
}

/** Company-wide, canonical payment ledger only. Parent project receipts must
 * never be added to their allocated child payments. Existing refunds/disputes
 * adjust their original payment-date cohort, rather than refund-event cashflow.
 * Expenses have no per-row currency: require the explicit installation
 * reporting declaration before assigning amounts to a currency. */
function api_v2_financial_summary_read(PDO $pdo,array $identity,array $period,string $requestId): array
{
    if(!$pdo->inTransaction())throw new LogicException('Transaction required');
    $clock=$pdo->query("SELECT DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ') as_of, CURRENT_DATE() today")->fetch(PDO::FETCH_ASSOC);
    $income=payment_accounting_net_income_expr('p');
    $baseValue=$pdo->query("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='financial_base_currency' LIMIT 1")->fetchColumn();
    $baseCurrency=financial_reporting_currency_value($baseValue===false?null:$baseValue);
    // Every supplied income currency must agree. The expense declaration
    // never assigns a currency to standalone or broken-linked payments.
    $currency="CASE WHEN REGEXP_LIKE(COALESCE(i.currency,pi.currency,c.currency),'^[A-Z]{3}$','c') AND (i.currency IS NULL OR CAST(i.currency AS BINARY)=CAST(COALESCE(pi.currency,c.currency,i.currency) AS BINARY)) AND (pi.currency IS NULL OR c.currency IS NULL OR CAST(pi.currency AS BINARY)=CAST(c.currency AS BINARY)) THEN COALESCE(i.currency,pi.currency,c.currency) ELSE NULL END";
    $stmt=$pdo->prepare("SELECT $currency currency,COUNT(*) n,CAST(SUM(p.amount) AS CHAR) received,CAST(SUM($income) AS CHAR) adjusted,SUM(p.processor_fee_source='actual') actual,SUM(p.processor_fee_source='estimated') estimated,SUM(p.processor_fee_source IS NULL OR p.processor_fee_source NOT IN ('actual','estimated')) unknown FROM payments p LEFT JOIN invoices i ON i.id=p.invoice_id LEFT JOIN contracts c ON c.id=p.contract_id LEFT JOIN project_invoice_payments pp ON pp.id=p.project_invoice_payment_id LEFT JOIN project_invoices pi ON pi.id=pp.project_invoice_id WHERE p.status='succeeded' AND p.payment_date BETWEEN ? AND ? GROUP BY currency ORDER BY currency");
    $stmt->execute([$period['from'],$period['to']]);$paymentRows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt=$pdo->prepare("SELECT status,COUNT(*) n FROM expenses WHERE status<>'void' AND expense_date BETWEEN ? AND ? GROUP BY status ORDER BY status");$stmt->execute([$period['from'],$period['to']]);$expenseCounts=['pending'=>0,'confirmed'=>0,'reimbursed'=>0];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){if(!array_key_exists($row['status'],$expenseCounts))throw new RuntimeException('Unknown expense status');$expenseCounts[$row['status']]=(int)$row['n'];}
    $expenseAmounts=null;
    if($baseCurrency!==null){
        $expense=$pdo->prepare("SELECT CAST(COALESCE(SUM(COALESCE(total_amount,amount,0)),0) AS CHAR) total,CAST(COALESCE(SUM(CASE WHEN status='pending' THEN COALESCE(total_amount,amount,0) ELSE 0 END),0) AS CHAR) pending,CAST(COALESCE(SUM(CASE WHEN status='confirmed' THEN COALESCE(total_amount,amount,0) ELSE 0 END),0) AS CHAR) confirmed,CAST(COALESCE(SUM(CASE WHEN status='reimbursed' THEN COALESCE(total_amount,amount,0) ELSE 0 END),0) AS CHAR) reimbursed FROM expenses WHERE status<>'void' AND expense_date BETWEEN ? AND ?");$expense->execute([$period['from'],$period['to']]);$expenseAmounts=$expense->fetch(PDO::FETCH_ASSOC);
    }
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM project_invoice_payments pp WHERE pp.status='succeeded' AND pp.payment_date BETWEEN ? AND ? AND (NOT EXISTS(SELECT 1 FROM payments p WHERE p.project_invoice_payment_id=pp.id AND p.status='succeeded') OR pp.amount<>(SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.project_invoice_payment_id=pp.id AND p.status='succeeded'))");$stmt->execute([$period['from'],$period['to']]);$unallocated=(int)$stmt->fetchColumn();
    $receivables=$pdo->prepare("SELECT currency,COUNT(*) n,CAST(SUM(balance_due) AS CHAR) outstanding,CAST(SUM(CASE WHEN due_date<? THEN balance_due ELSE 0 END) AS CHAR) overdue FROM (SELECT CASE WHEN REGEXP_LIKE(currency,'^[A-Z]{3}$','c') THEN currency ELSE NULL END currency,balance_due,due_date FROM invoices i WHERE status IN ('sent','unpaid','partial','overdue') AND collection_mode='direct' AND NOT EXISTS(SELECT 1 FROM project_invoice_items item WHERE item.invoice_id=i.id) UNION ALL SELECT CASE WHEN REGEXP_LIKE(currency,'^[A-Z]{3}$','c') THEN currency ELSE NULL END currency,balance_due,due_date FROM project_invoices WHERE status IN ('sent','unpaid','partial','overdue')) r GROUP BY currency ORDER BY currency");$receivables->execute([$clock['today']]);
    $buckets=[];$unknown=0;$unknownReceivables=0;$estimated=0;
    $empty=static fn(string $currency):array=>['currency'=>$currency,'decimalScale'=>2,'payments'=>['count'=>0,'received'=>'0.00','adjustedNetIncome'=>'0.00','processorFeeQuality'=>['actual'=>0,'estimated'=>0,'unknown'=>0]],'expenses'=>['count'=>null,'total'=>null,'pending'=>null,'confirmed'=>null,'reimbursed'=>null],'recordedOperatingResult'=>null,'receivables'=>['count'=>0,'outstanding'=>'0.00','overdue'=>'0.00']];
    foreach($paymentRows as $row){$estimated+=(int)$row['estimated'];if($row['currency']===null||preg_match('/^[A-Z]{3}$/D',(string)$row['currency'])!==1){$unknown+=(int)$row['n'];continue;}$key=$row['currency'];$buckets[$key]=$empty($key);$buckets[$key]['payments']=['count'=>(int)$row['n'],'received'=>api_v2_financial_summary_decimal($row['received']),'adjustedNetIncome'=>api_v2_financial_summary_decimal($row['adjusted']),'processorFeeQuality'=>['actual'=>(int)$row['actual'],'estimated'=>(int)$row['estimated'],'unknown'=>(int)$row['unknown']]];}
    foreach($receivables->fetchAll(PDO::FETCH_ASSOC) as $row){if(!preg_match('/^[A-Z]{3}$/D',(string)$row['currency'])){$unknownReceivables+=(int)$row['n'];continue;}$key=$row['currency'];$buckets[$key]??=$empty($key);$buckets[$key]['receivables']=['count'=>(int)$row['n'],'outstanding'=>api_v2_financial_summary_decimal($row['outstanding']),'overdue'=>api_v2_financial_summary_decimal($row['overdue'])];}
    if($baseCurrency!==null){
        $buckets[$baseCurrency]??=$empty($baseCurrency);
        if(count($buckets)>128)throw new RuntimeException('Currency aggregation limit');
        $subtract=$pdo->prepare('SELECT CAST(CAST(? AS DECIMAL(65,2))-CAST(? AS DECIMAL(65,2)) AS CHAR)');
        foreach($buckets as $key=>&$bucket){
            $bucket['expenses']=['count'=>$key===$baseCurrency?array_sum($expenseCounts):0];
            foreach(['total','pending','confirmed','reimbursed'] as $field)$bucket['expenses'][$field]=$key===$baseCurrency?api_v2_financial_summary_decimal($expenseAmounts[$field]):'0.00';
            $subtract->execute([$bucket['payments']['adjustedNetIncome'],$bucket['expenses']['total']]);$bucket['recordedOperatingResult']=api_v2_financial_summary_decimal($subtract->fetchColumn());
        }
        unset($bucket);
    }
    ksort($buckets,SORT_STRING);
    $payload=['apiVersion'=>'2','sourceInstanceId'=>$identity['source_instance_id'],'applicationId'=>$identity['application_id'],'historyEpoch'=>$identity['history_epoch'],'requestId'=>$requestId,'asOf'=>$clock['as_of'],'period'=>$period,'scope'=>'company','accountingBasis'=>'payment-date-current-adjustments-and-recorded-expense-date','coverage'=>['complete'=>$unknown===0&&$unknownReceivables===0&&$unallocated===0&&($baseCurrency!==null||array_sum($expenseCounts)===0),'currencyPolicy'=>$baseCurrency===null?'linked-document-currency-only':'explicit-company-base-and-linked-document-currency','baseCurrency'=>$baseCurrency,'unknownPaymentCurrencyCount'=>$unknown,'unknownReceivableCurrencyCount'=>$unknownReceivables,'unallocatedProjectPaymentCount'=>$unallocated,'expenseCurrencyKnown'=>$baseCurrency!==null,'recordedExpenseCounts'=>['total'=>array_sum($expenseCounts)]+$expenseCounts+['other'=>0],'processorFeeEstimateCount'=>$estimated],'currencies'=>array_values($buckets)];
    $snapshot=$payload;unset($snapshot['requestId'],$snapshot['asOf']);$payload['snapshotSha256']=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));return $payload;
}
