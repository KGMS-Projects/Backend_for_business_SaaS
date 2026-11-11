# Service Request & Approval API

Backend for an internal service-request module. **Employees** submit requests, **managers** approve, reject or
return them, **operations** assigns an owner and completes the work. Every request has a clear status, an owner
and a complete activity history, and the API enforces who may do what.

> Built for the Quantix Laravel internship take-home. Laravel 12 (the brief asks for 11+) · PHP 8.2+ · Sanctum ·
> SQLite by default (MySQL / PostgreSQL work too) · PHPUnit · Pint · Larastan level 8.

| | |
|---|---|
| **Tests** | 251 tests / 1,044 assertions, run in ~3 s |
| **Static analysis** | Larastan level 8, no baseline |
| **Docs** | [`docs/openapi.yaml`](docs/openapi.yaml) · [`docs/postman_collection.json`](docs/postman_collection.json) · [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) · [`docs/AI_AND_REFERENCES.md`](docs/AI_AND_REFERENCES.md) |

---

## Quick start (about 2 minutes)

Requirements: PHP 8.2+ with `pdo_sqlite`, `mbstring`, `xml`, `curl`, and Composer 2.

```bash
composer setup          # install, copy .env, generate key, create SQLite file, migrate:fresh --seed
php artisan serve       # API at http://localhost:8000
php artisan queue:work  # second terminal: sends the notifications (see "Notifications")
```

Prefer the individual commands?

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
```

**MySQL / PostgreSQL:** set `DB_CONNECTION=mysql|pgsql` and the `DB_*` values in `.env`, then `php artisan migrate:fresh --seed`.

### Run the tests and quality checks

```bash
composer test       # the whole suite (in-memory SQLite, nothing to configure)
composer lint       # Pint, formatting check   (composer format fixes it)
composer analyse    # Larastan, level 8
composer check      # all three
```

### Seeded logins (password for every account: `password`)

| Role | E-mail | Notes |
|---|---|---|
| Employee | `employee@example.com` (Eli) | reports to Maya |
| Employee | `employee2@example.com` (Emma) | reports to Maya |
| Employee | `employee3@example.com` (Nina) | reports to **Marcus** (other team; shows that managers can't cross teams) |
| Manager | `manager@example.com` (Maya) | **two direct reports**: Eli and Emma |
| Manager | `manager2@example.com` (Marcus) | one report: Nina |
| Operations | `ops@example.com` (Olivia), `ops2@example.com` (Omar) | |

`migrate:fresh --seed` creates **11 requests covering all 8 statuses** (2 draft, 3 submitted, returned, rejected,
approved, assigned, in progress, completed). They are created by driving the real workflow, so each has a genuine
history. One assigned request is deliberately overdue to show the SLA flag. Seeding does not queue notifications.

### Try it

```bash
# 1. log in
TOKEN=$(curl -s localhost:8000/api/login -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"employee@example.com","password":"password"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->data->token;')

# 2. create a request and send it for review in one call
curl -s localhost:8000/api/requests -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"title":"New laptop","description":"Mine is five years old","category":"equipment","priority":"high","submit":true}'
```

Or import `docs/postman_collection.json`: run **Auth** (three logins), then the **Employee → Manager → Operations**
folders in order; tokens and the request id are stored in collection variables for you.

---

## The workflow

```
draft ─▶ submitted ─▶ approved ─▶ assigned ─▶ in_progress ─▶ completed   (final)
  ▲          ├──────▶ rejected                                           (final)
  └─ returned ◀─┘      (manager returns with a comment; employee edits and resubmits)
```

Anything else is a **422** with a message such as
`A request cannot move from 'assigned' to 'completed'. Allowed next status: 'in_progress'.`

## API at a glance

Full detail: [`docs/openapi.yaml`](docs/openapi.yaml) (OpenAPI 3) or the Postman collection.

| Method | Endpoint | Who | Purpose |
|---|---|---|---|
| POST | `/api/login` | anyone | Issue a Bearer token (rate limited) |
| GET / POST | `/api/me`, `/api/logout` | any | Current user / revoke token |
| POST | `/api/requests` | Employee | Create as draft, or `"submit": true` to send it straight away |
| GET | `/api/requests` | all | List, **scoped to the role**; `search`, `status`, `priority`, `category`, `overdue`, `sort`, `direction`, `per_page`, `page` |
| GET | `/api/requests/{id}` | all (policy) | Details with comments and activity history |
| PUT | `/api/requests/{id}` | Employee | Edit a draft or returned request |
| POST | `/api/requests/{id}/submit` | Employee | Send for manager review |
| POST | `/api/requests/{id}/decision` | Manager | `approve` / `reject` / `return` (+ comment) |
| POST | `/api/requests/{id}/assign` | Operations | Assign an owner |
| POST | `/api/requests/{id}/status` | Operations | Move to `in_progress` or `completed` |
| POST | `/api/requests/{id}/comments` | Participants | Add a comment |
| GET | `/api/requests/{id}/activities` | Participants | The audit trail, paginated *(added)* |
| GET / POST | `/api/notifications`, `/api/notifications/read` | any | My in-app notifications *(added)* |

**Response standards.** Always JSON, even for errors and even without an `Accept` header. Successful payloads are
`{"data": …}`; lists add `links` and `meta`. Every error is `{"message": "…", "code": "…"}` (plus `errors` for
validation). Codes: `401 unauthenticated|invalid_credentials`, `403 forbidden`, `404 not_found`,
`422 validation_failed|invalid_transition|request_not_editable|request_final`, `429 too_many_requests`.

**Who sees what.** Employee: their own requests. Manager: non-draft requests of their direct reports. Operations:
requests from `approved` onwards (never rejected ones).

---

## Notifications

Each status change dispatches a queued job (`SendRequestStatusNotification`) **after the database commit**. The job
sends a database notification and an e-mail to the right people (never to the person who made the change):

| Change | Who is told |
|---|---|
| submitted | the requester's manager |
| approved | the requester and every operations user |
| rejected / returned | the requester |
| assigned | the new owner and the requester |
| in progress | the requester |
| completed | the requester and their manager |

Locally `QUEUE_CONNECTION=database` and `MAIL_MAILER=log`: run `php artisan queue:work`, then read the e-mails in
`storage/logs/laravel.log` and the in-app ones at `GET /api/notifications`. Set `NOTIFICATIONS_ENABLED=false` to switch it off.

## Project layout

```
app/Enums/                RequestStatus (the transition map), Role, DecisionAction, …
app/Services/             RequestWorkflow (the only place state changes), RequestRecipients
app/Policies/             ServiceRequestPolicy: who may do what
app/Http/                 thin controllers · Form Requests · API Resources
app/Jobs, Notifications/  queued notification pipeline
app/Exceptions/           WorkflowException (422) · ApiExceptionRenderer (one JSON error shape)
database/                 migrations (with indexes), factories, seeders
routes/api.php            routes + the policy for each one: read it as the permission matrix
tests/Unit, tests/Feature
docs/                     OpenAPI, Postman, architecture note, AI/references note
```

---

## Assumptions and decisions (where the brief was silent)

1. **An explicit `assigned` status** sits between `approved` and `in_progress`, matching the "Assign owner → Work in progress" journey.
2. **403 vs 422.** *Not allowed for you* (wrong role or team, or you can't see it) is **403**. *Not possible right now* (wrong state: approving twice, editing a submitted request, anything on a final request) is **422**. Authorisation runs before validation, so an unauthorised caller never learns the payload rules.
3. **Rejecting or returning requires a comment**; approving doesn't. Decision comments live on the activity row (who, when, comment) rather than being duplicated as discussion comments.
4. **Drafts are private**: a manager can't see or act on a draft until it is submitted. Operations never see requests still in review, returned or rejected.
5. **Any operations user can start/complete any visible request** (a shared queue), and can **re-assign** while `assigned` / `in_progress`. Tightening this to "only the assignee completes" is a one-line change in `ServiceRequestPolicy::changeStatus`. The assignee must be an operations user (422 otherwise).
6. **Comments**: anyone who can see the request may comment; comments are closed once a request is final (rejected / completed), to honour "final and cannot be changed".
7. **Only employees create requests.** "Direct reports" means one level (`users.manager_id`), not the whole subtree. An employee with no manager can submit, but nobody can approve it (see next steps).
8. **Hidden resources**: a request you may not see returns **403**, not 404 (the brief lists 403 for forbidden actions). IDs are sequential, so hiding existence would add little.
9. **PUT accepts partial updates** (at least one field). Unchanged values aren't logged.
10. **SLA**: due date = approval time + hours by priority (urgent 8 · high 24 · medium 72 · low 120, calendar hours, in `config/service_requests.php`). `is_overdue` = past due and not final.
11. **Tokens expire after 24 h** (`SANCTUM_TOKEN_EXPIRATION`); login is limited to 5 attempts/min per e-mail+IP; the API to 60 requests/min per user (or IP).
12. **Notifications** are one queued job per change that notifies all recipients. Simple and atomic to dispatch, but a mail failure retries the whole job, so a recipient could occasionally get a duplicate (at-least-once delivery).

## What I verified, and what I didn't

* ✅ 251 tests pass; Pint and Larastan level 8 are clean; `migrate:fresh --seed` works; I also ran the API over real HTTP with a real queue worker (that caught a missing `notifications` table and two other bugs before the tests existed).
* ✅ **Mutation check:** I deliberately broke the code in 24 ways (drop the transaction, remove the direct-report check, loosen the transition map, skip eager loading, …); the suite caught all 24.
* ⚠️ **Only run on SQLite.** The queries are portable (`LIKE … ESCAPE`, JSON columns, FKs), but I did not run MySQL/PostgreSQL. Note `lockForUpdate()` is a no-op on SQLite, so the row-lock itself is untested; the re-read-inside-the-transaction behaviour is.
* ⚠️ No Docker/Sail setup (optional stretch goal, skipped on purpose to keep the core solid), and the GitHub Actions workflow in `.github/` has not been executed.

## What I would do next

* **Fallback approver**: employees without a manager (or whose manager is away) currently can't get approval.
* Per-recipient notification jobs (isolated retries), notification preferences, "mark one as read".
* File attachments, business-hours SLAs, escalation of overdue requests via the scheduler.
* An `abilities` block on each response (`can_edit`, `can_decide`, …) so a client can show/hide buttons without re-implementing rules.
* ETag / optimistic locking on `PUT`, cursor pagination for very large lists.
* CI matrix on MySQL and PostgreSQL; Docker/Sail; contract tests generated from the OpenAPI file.
