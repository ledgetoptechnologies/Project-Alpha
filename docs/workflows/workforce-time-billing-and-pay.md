# Workforce, time, billing, and pay workflow

Project Alpha separates the service a client receives from the work a person performs. This keeps client pricing independent from worker compensation while allowing both to use the same Job and Time Entry.

## Domain model

- **Project:** an optional long-running engagement that groups several Jobs, such as a sports season.
- **Job:** one concrete unit and scope of work. A quote, contract, and invoice converted from one another keep the same Job.
- **Service:** the client-facing description and price. Services may be fixed, hourly, or base-price-plus-overage.
- **Work Activity:** an internal classification such as On-site Work, Editing, Mapping, Driving, or Maintenance.
- **Assignment:** the worker-specific agreement for a Job, including the compensation method and effective rate snapshot.
- **Time Entry:** a timer session or manual log of what the worker did.
- **Earning Snapshot:** the immutable calculation of gross compensation created from confirmed work.
- **Worker Statement:** a pay-period grouping of approved earnings and adjustments.
- **Worker Payment Record:** the admin-confirmed fact that money was paid; this is separate from the statement calculation.
- **Payroll Export:** an audited CSV containing approved gross earnings for external payroll software.

## Projects, Jobs, and documents

A one-off service uses a Job without a Project. Long-running engagements use a Project containing one or more Jobs. Each document family belongs to its Job:

1. Create or select the Job when preparing the quote, contract, or invoice.
2. Converting a quote to a contract or invoice preserves the Job and Project.
3. Workers record time against the Assignment or Job.
4. Client, Project, and Service context is inherited from the Job rather than independently rewritten on the Time Entry.

Unclassified time is allowed and does not create a hidden Job. An admin must assign a real Job before that time can become client-billable.

## Services and Work Activities

Services and Work Activities are independent by default. This supports businesses where a client-facing service and employee labor do not have matching names, such as a bleacher rental performed through Setup, Driving, and Maintenance activities.

A Service may optionally have one exclusive Work Activity link. The link is useful when the client service and internal labor represent the same work, such as Mapping or On-site Work.

- Users may create a matching Activity or link an existing unlinked Activity.
- Linked records show the counterpart on both settings pages.
- Names, client prices, and compensation rules remain independent.
- Unlinking allows either record to be linked again later.
- An unused linked pair may be permanently deleted. Once either record has history, deleting the pair deactivates both records instead.
- Packages use the links belonging to their contained Services rather than linking the package itself.

## Time and automatic invoice attachment

Workers select a Work Activity and may select an Assignment or Job. They do not choose client billing rates or worker compensation treatment.

After time is confirmed:

- If the Job has exactly one mutable draft invoice, billable time attaches to it automatically.
- If no draft invoice exists, or more than one is available, the entry appears in **Work Review → Needs billing context**.
- Finalized invoices are never automatic destinations.
- An admin may move the entry while the affected billing records remain mutable. Conflicting client, Project, or Job context requires an explicit correction and is never silently overwritten.

Selecting a draft invoice while entering time records the intended destination, but it does not create a financial line before confirmation. The draft invoice editor shows matching pending time and its current review state. A verified Owner can use **Confirm and add** for their own time. Built-in `admin` and `owner` account roles may also confirm their own completed time. In either case, the Worker Profile's explicit compensation policy determines whether confirmed time can create an eligible earning; confirmation does not itself approve or pay that earning. Other employee and contractor entries remain behind the normal reviewer control. Once confirmed, the selected draft invoice is updated automatically and its totals are recalculated.

Verified-Owner confirmation and administrative self-confirmation are different authority paths, not compensation policies. The verified **Owner relationship** can allow self-confirmation; an explicit `rules` Worker Profile may earn compensation, while a nonpayable policy suppresses it. Administrative self-confirmation is an account-role privilege and does not change the Worker Profile's compensation policy. Permission grants such as time-management access do not provide this self-confirmation bypass. Closed periods and finalized historical records remain unchanged and must be handled as audited exceptions.

Owner self-confirmation depends on the verified **Owner relationship** in the Worker Profile, not an `admin` or `owner` account role by itself. Verifying that relationship reconciles completed pending entries in open review periods through the normal approval service. Closed periods and finalized historical records remain unchanged and must be handled as audited exceptions.

Compensation policy is independent of account role and worker relationship. A newly created Worker Profile without an explicit policy starts at **Needs setup**; an administrator must choose the intended policy before payable work is processed. Changing a policy governs future calculations and does not erase approved earning snapshots. Pay-period statements still include already-approved earnings after a later policy change. Worker Payment Records remain a separate confirmation that money was actually paid.

Hourly Service lines on quotes and contracts are estimates. They show estimated hours, hourly price, and an estimated total. They do not become collectible invoice charges during conversion. Confirmed time creates the actual hourly invoice lines. Fixed-price time is operational history and does not create an additional client charge.

## Corrections and disputes

Immutable means the historical calculation cannot be silently replaced; it does not mean mistakes cannot be corrected.

Before approval, the available action follows the entry state:

- Draft or returned time can be edited directly by its worker or an authorized workforce manager.
- Submitted time can be **Withdrawn and edited**. The submitted revision and review history remain intact, while the live entry returns to draft and must be submitted again.
- Confirmed time cannot be overwritten in place. Workers use **Request correction**; authorized administrators use **Edit**, provide a reason, and PA records the change through the correction ledger.
- Stale revisions, a second pending correction for the same revision, and concurrent review decisions are rejected without partial updates.

| Entry state | Worker action | Authorized administrator action | Audit result |
|---|---|---|---|
| Draft or returned | **Edit** their own entry | **Edit** an accessible entry | Previous revision is archived; the entry stays draft |
| Submitted and awaiting review | **Withdraw and edit** their own entry | **Withdraw and edit** an accessible entry | Submitted revision is marked withdrawn; edited entry returns to draft |
| Confirmed | **Request correction** | **Edit** with a required reason | Original approval remains intact; a corrected revision and deltas are recorded |
| Included on an open statement | **Request correction** | **Edit** with a required reason | Original earning snapshot remains intact; open projections are rebuilt |
| Closed or settled period | **Request correction** | **Edit** with a required reason | Closed statement stays locked; the adjustment moves to the next open period |
| Attached to a finalized invoice | **Request correction** | **Edit** with a required reason | Finalized invoice stays locked; billing uses an explicit charge, credit, move, or absorbed exception |

1. A worker requests a correction with proposed time and a reason, or an authorized admin creates it directly.
2. Work Review shows the original and proposed values together with separate worker-pay and client-billing effects.
3. Approval creates a new Time Entry revision and preserves the prior revision and snapshots.
4. Hourly and base-plus-overage impacts use the rate or rule snapshot that applied to the original work. Fixed Assignment pay does not change only because the logged duration changes.

Statement handling depends on its state:

- A draft statement is rebuilt.
- An issued, unpaid, and unexported statement is voided and reissued.
- A paid, exported, or settled statement receives a linked adjustment on the next open statement.

The client decision is independent:

- Draft invoices update their actual-time line.
- For finalized invoices, an admin chooses an audited charge or credit, moves the delta to another draft invoice, or absorbs it with a reason.
- A credit on a paid invoice becomes a client-account credit. An admin can apply it to a future invoice or record/refund the actual payment.

Project Alpha records gross worker corrections but does not withdraw money. External payroll determines how a previously paid negative adjustment is legally handled.

## Pay-period deadline

The organization configures the pay-period deadline time in the Workforce settings. Workers who have not approved the period receive reminders four, two, and one hour before the deadline.

At the deadline, Project Alpha submits and confirms completed entries, creates their snapshots, and locks worker editing. Running or incomplete entries remain in the exception queue. Later changes use the admin correction workflow.

Statements calculate what is owed. Worker Payment Records separately document what was actually paid. Payroll exports contain stable statement, earning, worker, work-date, method, quantity, rate, gross-delta, currency, and correction identifiers for reconciliation.

## Compensation-policy migration verification

On September 23, 2026, an isolated MySQL 8.4 fixture was initialized from the
pre-change baseline. The normal migration runner applied and validated all
pending migrations through `0107_decouple_worker_compensation_policy.sql`.
The column default changed from `rules` to `needs_setup`; the workforce
database lifecycle test then passed (1 test, 29 assertions). Focused workflow
tests passed separately (28 tests, 153 assertions). The temporary fixture was
removed. These are local checks, not evidence of a production migration,
deployment, or payroll acceptance.
