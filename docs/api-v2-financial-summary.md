# Company financial summary (API v2)

`GET /api/v2/financial-summary?from=2026-01-01&to=2026-01-31` requires
**both** explicit `api.capabilities.read` and `financial.summary.read` scopes.
`financial.documents.read`, legacy `financial.read`, and `full` do not authorize
this endpoint. Send the current `X-PA-Source-Instance-ID`, `X-PA-Application-ID`,
and `X-PA-History-Epoch` headers. There is no customer mapping prerequisite or
customer selector. Dates are inclusive stored calendar dates, strictly valid,
from 1900 onward, ordered, and at most 365 days apart. Unknown and repeated
query parameters fail with 400. GET is the only method. Responses are no-store
and do not open a browser session.

The endpoint is available by default and advertised by capability discovery.
It returns company-wide aggregate counts/amounts only, without customer
identifiers, document links, bearer tokens, or payment details. It never edits
financial records. Ordinary API authentication still records usage/rate limits.
Missing scope returns 403, missing/revoked key 401, mismatched/unbound identity
409, and unavailable/incompatible data 503.

```ts
type Amount = string; // exact signed decimal, two places; never JSON float
type FinancialSummary = {
  apiVersion: "2";
  sourceInstanceId: string; applicationId: string; historyEpoch: string;
  requestId: string; asOf: string; snapshotSha256: string;
  period: { from: string; to: string; dateBasis: "stored-calendar-date" };
  scope: "company";
  accountingBasis: "payment-date-current-adjustments-and-recorded-expense-date";
  coverage: {
    complete: boolean;
    currencyPolicy: "linked-document-currency-only" | "explicit-company-base-and-linked-document-currency";
    baseCurrency: string | null;
    unknownPaymentCurrencyCount: number;
    unknownReceivableCurrencyCount: number;
    unallocatedProjectPaymentCount: number;
    expenseCurrencyKnown: boolean;
    recordedExpenseCounts: {
      total: number; pending: number; confirmed: number;
      reimbursed: number; other: number;
    };
    processorFeeEstimateCount: number;
  };
  currencies: Array<{
    currency: string; decimalScale: 2;
    payments: {
      count: number; received: Amount | null; adjustedNetIncome: Amount | null;
      processorFeeQuality: { actual: number; estimated: number; unknown: number };
    };
    expenses: {
      count: number | null; total: Amount | null; pending: Amount | null;
      confirmed: Amount | null; reimbursed: Amount | null;
    };
    recordedOperatingResult: Amount | null;
    receivables: { count: number; outstanding: Amount; overdue: Amount };
  }>;
};
```

All reads share one repeatable-read transaction. The current API key,
application, and history identity are locked and validated within it. The
snapshot hash includes identities, period, aggregate values and coverage,
excluding volatile request ID and observation time. It is a representation
hash, not a historical accounting close or a promise of synchronized snapshots
between separate PA servers. Consumers must reauthorize staff after fetching,
merge only identical currencies/scales using exact arithmetic, and label any
missing server or incomplete coverage. No exchange-rate conversion is implied.

`received` sums succeeded canonical `payments.amount` for the selected payment
dates. `adjustedNetIncome` uses PA's existing SQL accounting expression:
processor net when known, gross less fees when available, otherwise amount plus
retained surcharge, less current refunds and disputes, floored at zero per
payment. Processor fee quality is exposed because historical values may be
estimated or unknown. Later refunds/disputes revise the original payment-date
cohort: this is **not** refund-event cashflow, accounting profit, or bank balance.

Project receipt parents in `project_invoice_payments` are not added to their
child `payments`, which would double count. A succeeded parent in the selected
period with missing/unequal succeeded child allocation marks coverage incomplete.
Only payment rows with agreeing linked invoice/project invoice/contract currency
enter currency buckets; unknown or conflicting currencies are counted separately.
No default USD or workforce currency is assigned to financial records.

Current receivables are independent of the selected payment/expense period:
direct invoices that are not project-invoice components, plus project invoices,
in sent/unpaid/partial/overdue states. Draft, cancelled, void, and paid invoices
are excluded. Overdue means `due_date` before the database's current calendar
date. Quotes and contracts are never counted as income or receivables.

PA expenses have no stored per-row currency. An installation administrator can
explicitly declare their currency in **Settings → Business & installation →
Company financial reporting** (`financial_base_currency`). The default is
unset; no environment variable or Compose edit is needed. This reporting
declaration never converts amounts or rewrites financial history. It must be
confirmed against the actual currency of existing expenses.
When unset, expense counts are reported once in `recordedExpenseCounts`, while
currency-specific expense fields and operating results are null. When set, all
non-void period expense counts/amounts belong to exactly the declared base
currency; other currency buckets have zero expenses. Operating results use
exact SQL DECIMAL subtraction. This expense declaration never assigns a
currency to standalone payments. Income requires agreeing linked document
currencies; missing, malformed, or conflicting currencies stay unknown. Invalid stored policy fails closed.
The setting is read in the same repeatable snapshot as the aggregates.
Known currency buckets remain useful with incomplete coverage. `decimalScale:2`
describes PA's actual DECIMAL storage, not an invented ISO currency minor-unit
exponent. Currencies are never summed together.
