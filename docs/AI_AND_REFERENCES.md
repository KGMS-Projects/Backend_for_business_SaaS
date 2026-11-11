# AI usage, packages and references

## AI tools used

**Claude (Anthropic)**, used as a pair-programmer for most of this project: scaffolding, drafting the code
(enums, workflow service, policy, controllers, migrations), drafting the test suite, the OpenAPI and Postman files, and this
documentation. It also ran the code in a sandbox: tests, Pint, Larastan, and an HTTP smoke test with a real queue worker.

How the output was checked rather than trusted:

* Everything was executed: tests, formatting and static analysis are green, and the API was exercised over real HTTP.
* That run found real bugs which were then fixed: a missing `notifications` table, the Laravel 12 throttle-exception
  namespace, a 500 instead of JSON 401 when no `Accept` header is sent, a default sort direction, and `overdue=true` parsing.
* **Mutation check:** the production code was deliberately broken in 24 ways to confirm the tests fail when rules are violated.
* A test compares `docs/openapi.yaml` and the Postman collection against the registered routes so the docs can't drift.

* I reviewed every line of generated code, verified that business rules matched the assignment spec, and added the custom domain logic around direct report validation and atomic state transitions.

## Packages

| Package | Why |
|---|---|
| `laravel/framework` ^12 | Framework (brief asks for 11+; same structure) |
| `laravel/sanctum` ^4 | Token authentication |
| `laravel/pint` | Code formatting (Laravel preset) |
| `larastan/larastan` ^3 | Static analysis, level 8 |
| `phpunit/phpunit` ^11, `mockery/mockery`, `fakerphp/faker`, `nunomaduro/collision` | Testing and nicer test output |

No other runtime dependencies.

## References

* Laravel docs: Eloquent (casts, enums, scopes), Authorization (policies, `can` middleware), Validation / Form Requests,
  API Resources, Queues (`afterCommit`), Notifications (database and mail channels), Rate Limiting, Testing (HTTP tests, fakes).
* Laravel Sanctum docs: API token authentication and token expiration.
* OpenAPI 3.0.3 specification; Postman Collection v2.1 schema.
