<?php
declare(strict_types=1);

/** An explicit reporting declaration, never a conversion or guessed default. */
function financial_reporting_currency_value(mixed $value): ?string
{
    if($value===null||$value==='')return null;
    if(!is_string($value)||preg_match('/^[A-Z]{3}$/D',$value)!==1)throw new InvalidArgumentException('Reporting currency must be an uppercase three-letter currency code, or blank.');
    return $value;
}

/** Defense in depth for the existing installation-settings POST handler. */
function financial_reporting_currency_change(mixed $value,bool $systemTab,bool $canManage,string $role,bool $csrfValid): ?string
{
    if(!$systemTab||!$canManage||!in_array($role,['admin','owner'],true)||!$csrfValid)throw new RuntimeException('Reporting currency settings access denied.');
    return financial_reporting_currency_value($value);
}
