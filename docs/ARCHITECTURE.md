# Architecture note

## Shape

```
HTTP ─▶ routes/api.php ──(->can(...))──▶ ServiceRequestPolicy      who may do this?        → 403
              │
              ▼
        Form Request                                                is the input valid?     → 422 validation_failed
              │
              ▼
        Controller (thin) ──▶ RequestWorkflow ──▶ RequestStatus     is it legal *now*?      → 422 invalid_transition
                                   │                                (single transition map)
                                   ├─▶ DB transaction: lock row → change status → write activity row
                                   └─▶ after commit: SendRequestStatusNotification (queued)
```

Three questions, three places, three outcomes. That separation is the main idea of the design.

## Key decisions and trade-offs

**1. The lifecycle lives in one enum.** `RequestStatus::allowedTransitions()` is the only definition of legal moves;
policies, the service and the API all ask it. A new status (say `on_hold`) is one enum case and one `match` arm,
and an exhaustive 8×8 test fails until the matrix is updated on purpose. *Trade-off:* an enum + service is lighter than
a state-machine package or one class per state; if transitions grow their own side effects, the state-class pattern
would be worth the extra code.

**2. All writes go through `RequestWorkflow`, one transaction per action.** It re-reads the row under
`lockForUpdate`, checks the rule, changes the status and **writes the audit row in the same transaction**, so history
cannot diverge from state. Two people approving at once are serialised and the second gets a clean 422 (a stale-copy test
proves the service doesn't trust the object it was handed). *Trade-off:* pessimistic locks are simple and correct at
this scale; very hot rows would favour optimistic locking.

**3. Authorisation is declared on the routes** (`->can('decide', 'serviceRequest')`), so it runs *before* body
validation and `routes/api.php` doubles as the permission matrix. Visibility is defined twice, as a query scope for lists and
a policy rule for single records, and a test asserts the two agree for every role × status.

**4. Notifications are asynchronous and transactional.** A queued job is dispatched `afterCommit`, so nobody is told
about a change that rolled back, and the API never waits for a mail server. *Trade-off:* one job per change (not per
recipient) gives at-least-once delivery and the occasional duplicate on retry; per-recipient jobs are the upgrade path.

**5. Data model built for the real queries.** Composite indexes match the access paths: `(requester_id, status)`,
`(assignee_id, status)`, `(status, created_at)`, `due_at`. Lists eager-load their relations (a test asserts the query
count doesn't grow with the page), sort columns are whitelisted, and the manager scope uses a sub-select on the indexed
`manager_id` instead of loading ids into PHP. `request_activities` is append-only (no `updated_at`).

**6. One error envelope.** A single renderer maps every exception under `/api` to `{message, code[, errors]}` with the
right status, whatever the `Accept` header. Clients can switch on `code`, not on message text.

## Deliberately left out
Attachments, Docker/Sail, user management, a fallback approver, and non-SQLite verification (see README). I preferred a
smaller core that is fully tested over a larger one that is half-finished.
