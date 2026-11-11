# Architecture Note

This short note explains the key architectural decisions, structure, and trade-offs made in building the Service Request & Approval API.

---

## 1. Application Structure & Request Lifecycle

To keep the application clean and easy to maintain, each incoming request is handled through four simple layers:

1. **Routing & Authorization (`routes/api.php` & `ServiceRequestPolicy`):**  
   Before processing any request body, Laravel Policies check whether the authenticated user has permission for that action (e.g. only managers can call `/decision`, only employees can call `/submit`). If unauthorized, it immediately halts with a `403 Forbidden`.
2. **Input Validation (`FormRequest` classes):**  
   Validates required fields, formats, and allowed values. Invalid payloads fail with a `422 Unprocessable Content` response containing specific field errors.
3. **Workflow Logic (`RequestWorkflow` service):**  
   Controllers stay thin and delegate all business logic to `RequestWorkflow`. This service verifies that the status change is valid for the request's current state.
4. **Database & Audit Trail (`DB::transaction`):**  
   The status update and activity log creation are executed inside a single database transaction. This guarantees that an activity log entry is never created without the status update (and vice-versa).

---

## 2. Key Design Decisions & Trade-offs

### Centralized State Machine with PHP Enums
* **Decision:** All valid statuses and transitions are defined in `App\Enums\RequestStatus` using PHP 8.2 backed enums and `match` expressions.
* **Why:** This avoids scattered `if/else` checks throughout the codebase. Any attempt to make an illegal transition (e.g. moving directly from `draft` to `completed`) is caught in one place and rejected with a clear `422` error.
* **Trade-off:** For a service with 8 statuses, an enum-based state machine is clean, readable, and native to Laravel without needing third-party state machine packages.

### Atomic Updates & Concurrency Control
* **Decision:** Status updates use `lockForUpdate` within a database transaction.
* **Why:** If two users attempt to approve or update the same request simultaneously, the row lock prevents race conditions and ensures the second user receives an invalid transition error instead of corrupting the history.

### Asynchronous Queued Notifications
* **Decision:** State transitions dispatch a queued notification job (`SendRequestStatusNotification`) configured with `afterCommit`.
* **Why:** Notifications run asynchronously in the background so API responses remain fast. Using `afterCommit` ensures notifications are only dispatched if the database transaction commits successfully.

### Consistent JSON Error Envelope
* **Decision:** All API responses and errors are intercepted to return a unified JSON structure:
  ```json
  {
    "message": "Human readable explanation",
    "code": "error_code",
    "errors": {}
  }
  ```
* **Why:** Frontend clients can reliably parse errors and display appropriate user messages based on the `code` field, even if the client omits the `Accept: application/json` header.

---

## 3. Deliberately Left Out & Future Improvements

To focus on delivering a high-quality, fully-tested core within the take-home timeframe, the following features were left for future iteration:
* **File Attachments:** Allowing users to attach receipts or documents to service requests.
* **Multi-Tier Approvals:** Supporting approval chains for organizations where large requests require secondary approval.
* **Working-Hours SLA Calculation:** Adjusting SLA deadlines to account for company work schedules, weekends, and holidays.
