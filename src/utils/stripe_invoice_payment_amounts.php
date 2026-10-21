<?php
declare(strict_types=1);

/**
 * Resolve invoice principal and client surcharge from a verified successful
 * Stripe object. The caller verifies the event signature and payment status.
 * Processor fees and payouts never determine the amount credited to an invoice.
 *
 * @return array{payment_amount:float,surcharge_amount:float,gross_amount:float,payment_cents:int,surcharge_cents:int,gross_cents:int}
 */
function stripe_invoice_payment_amounts(array $stripeObject, string $objectType): array
{
    if (!in_array($objectType, ['payment_intent', 'checkout.session'], true)) {
        throw new InvalidArgumentException('Unsupported Stripe payment object type.');
    }
    if (array_key_exists('object', $stripeObject) && $stripeObject['object'] !== $objectType) {
        throw new InvalidArgumentException('Stripe payment object type does not match its event.');
    }
    $amountField = $objectType === 'checkout.session'
        ? 'amount_total'
        : (array_key_exists('amount_received', $stripeObject) ? 'amount_received' : 'amount');
    $gross = $stripeObject[$amountField] ?? null;
    if (!is_int($gross) || $gross <= 0) {
        throw new InvalidArgumentException('Stripe settled payment amount must be a positive integer in cents.');
    }

    $metadata = $stripeObject['metadata'] ?? [];
    if (!is_array($metadata) || (array_key_exists('metadata', $stripeObject) && $stripeObject['metadata'] === null)) {
        throw new InvalidArgumentException('Stripe payment metadata must be an object.');
    }
    $principal = stripe_invoice_payment_metadata_cents($metadata, ['pa_invoice_amount', 'original_amount']);
    $surcharge = stripe_invoice_payment_metadata_cents($metadata, ['pa_surcharge_amount', 'surcharge_amount']);
    if ($surcharge !== null && $surcharge > $gross) {
        throw new InvalidArgumentException('Stripe client surcharge exceeds the settled payment.');
    }
    if ($principal === null) {
        $surcharge ??= 0;
        $principal = $gross - $surcharge;
    } elseif ($principal <= 0 || $principal > $gross) {
        throw new InvalidArgumentException('Stripe invoice principal must be positive and cannot exceed the settled payment.');
    } elseif ($surcharge === null) {
        // Older PA Checkouts stored only the invoice principal.
        $surcharge = $gross - $principal;
    } elseif ($principal !== $gross - $surcharge) {
        // Compare by subtraction to avoid overflowing an integer addition.
        throw new InvalidArgumentException('Stripe invoice principal and client surcharge do not match the settled payment.');
    }
    if ($principal <= 0) {
        throw new InvalidArgumentException('Stripe invoice principal must be greater than zero.');
    }

    $amounts = [];
    foreach (['payment' => $principal, 'surcharge' => $surcharge, 'gross' => $gross] as $name => $cents) {
        $dollars = (float)($cents / 100);
        if (!is_finite($dollars) || (int)round($dollars * 100) !== $cents) {
            throw new InvalidArgumentException('Stripe payment amount cannot be represented safely.');
        }
        $amounts[$name . '_amount'] = $dollars;
        $amounts[$name . '_cents'] = $cents;
    }
    return $amounts;
}

/** @param list<string> $aliases */
function stripe_invoice_payment_metadata_cents(array $metadata, array $aliases): ?int
{
    $resolved = null;
    foreach ($aliases as $field) {
        if (!array_key_exists($field, $metadata)) continue;
        $value = $metadata[$field];
        if (!is_string($value) || !preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $value)) {
            throw new InvalidArgumentException('Stripe invoice amount metadata must contain nonnegative decimal dollar strings.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $roundUp = false;
        if (strlen($fraction) > 2) {
            // Historic Checkout builders stringified floating subtraction.
            // Permit only <= $0.000001 of noise around an exact cent, using
            // decimal digits so large values cannot hide material fractions.
            $tail = str_pad(substr($fraction, 2), 4, '0');
            $prefix = substr($tail, 0, 4);
            $nearLower = $prefix === '0000'
                || ($prefix === '0001' && trim(substr($tail, 4), '0') === '');
            $roundUp = $prefix === '9999';
            if (!$nearLower && !$roundUp) {
                throw new InvalidArgumentException('Stripe invoice amount metadata contains a material fraction of a cent.');
            }
        }
        $digits = ltrim($whole . str_pad(substr($fraction, 0, 2), 2, '0'), '0');
        if ($digits === '') $digits = '0';
        $maximum = (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException('Stripe invoice amount metadata exceeds the supported integer range.');
        }
        $cents = (int)$digits;
        if ($roundUp) {
            if ($cents === PHP_INT_MAX) {
                throw new InvalidArgumentException('Stripe invoice amount metadata exceeds the supported integer range.');
            }
            $cents++;
        }
        if ($resolved !== null && $resolved !== $cents) {
            throw new InvalidArgumentException('Stripe invoice amount metadata aliases disagree.');
        }
        $resolved = $cents;
    }
    return $resolved;
}
