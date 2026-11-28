<?php
// One "New" page (document/new) creates quotes, contracts and invoices.
// ?as= picks the document type, ?kind= the kind of work (the list kind keys).

declare(strict_types=1);

require_once __DIR__ . '/../views/components/document_list.php';

const DOC_NEW_TYPES = [
    'quote' => 'quotes.create',
    'contract' => 'contracts.create',
    'invoice' => 'invoices.create',
];

/** Stored *_type value for each kind key. */
const DOC_NEW_KIND_TYPES = [
    'one_time' => 'regular',
    'recurring' => 'long_term',
    'as_needed' => 'on_demand',
];

function document_new_as(mixed $as): string
{
    return is_string($as) && isset(DOC_NEW_TYPES[$as]) ? $as : 'quote';
}

/**
 * URL of the New page. $kind is a list kind key or a stored *_type value;
 * 'all', '' and unknown values leave the kind unset.
 */
function document_new_url(string $as, ?string $kind = null, array $extra = []): string
{
    $query = ['page' => 'document/new', 'as' => document_new_as($as)];
    if ($kind !== null && in_array($kind, DOC_NEW_KIND_TYPES, true)) {
        $kind = doc_list_kind_of($kind);
    }
    if ($kind !== null && isset(DOC_NEW_KIND_TYPES[$kind])) {
        $query['kind'] = $kind;
    }
    return '/?' . http_build_query($query + $extra);
}
