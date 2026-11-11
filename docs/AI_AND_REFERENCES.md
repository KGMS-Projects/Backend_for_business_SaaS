# AI Usage, Packages and References

## AI Usage Statement

In accordance with the assignment guidelines, AI tools (**Anthropic Claude**) were used as interactive pair-programming assistants during the development of this assignment. 

My focus was on applying sound engineering judgement, designing the domain model, and ensuring strict adherence to the business rules specified in the take-home brief.

### Where AI Was Used:
* **Boilerplate & Scaffolding:** Generating initial skeletons for Form Requests, API Resources, and migration boilerplate based on my database schema plan.
* **Brainstorming Test Permutations:** Generating repetitive test data matrices (e.g., the 8×8 status transition truth table and role-based visibility combinations) to ensure exhaustive coverage.
* **Documentation & Schema Conversion:** Formatting the Postman Collection (v2.1) and OpenAPI 3.0 specification from my route definitions.
* **Syntax & Framework Updates:** Cross-checking new features and changes in Laravel 12 (such as attribute routing conventions, rate limiting middleware, and typed model casts).

### Where Engineering Judgement & Ownership Were Applied:
* **Architecture & State Machine:** I designed the transition map in `RequestStatus` and isolated all state changes into `RequestWorkflow`, ensuring transitions occur atomically with database row locks (`lockForUpdate`).
* **Authorization & Scoping:** Enforced strict boundaries so managers can only act on direct reports (`users.manager_id`), operations staff can only handle approved work, and employees can only edit drafts or returned requests.
* **Verification & Bug Fixing:** I reviewed, refined, and executed every line of code. Through manual HTTP testing and unit tests, I caught and resolved multiple edge cases:
  - Ensured consistent JSON error responses across all failure modes (even when the client omits the `Accept: application/json` header).
  - Fixed query scoping to avoid N+1 queries during request listing with relationships.
  - Added mutation testing: deliberately broke 24 domain rules in the code to ensure our test suite fails as expected when invariants are violated.
  - Implemented automated contract tests in `OpenApiSpecTest.php` to guarantee the OpenAPI and Postman docs stay perfectly in sync with registered routes.

---

## Third-Party Packages

| Package | Purpose & Selection Rationale |
|---|---|
| `laravel/framework` (^12.0) | Core framework (clean structure, typed features, and robust HTTP foundation). |
| `laravel/sanctum` (^4.0) | Secure, lightweight API token authentication for SPA/mobile clients. |
| `laravel/pint` (^1.24) | Code style and formatting conforming strictly to Laravel conventions. |
| `larastan/larastan` (^3.0) | Static analysis (configured to Level 8) to guarantee type safety without runtime overhead. |
| `phpunit/phpunit` (^11.5) | Feature and unit testing suite. |
| `mockery/mockery` & `fakerphp/faker` | Mocking and seeding realistic fixture data. |

> **Note:** No external state machine or complex workflow dependencies were introduced. The workflow engine was implemented natively using pure PHP 8.2+ enums and Laravel transactions to keep the solution clean, transparent, and easy to maintain.

---

## References & Documentation

* **Laravel Documentation:** Eloquent ORM, Database Transactions, Authorization Policies, Form Requests & API Resources, Queues (`afterCommit`), Notifications, and HTTP Testing.
* **Laravel Sanctum Documentation:** Token-based API authentication and lifetime expiration configuration.
* **OpenAPI 3.0.3 Specification** & **Postman Collection v2.1 Schema**.
