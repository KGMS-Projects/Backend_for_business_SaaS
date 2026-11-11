# Service Request & Approval API

> **Quantix - Laravel Internship Take-Home Assignment**  
> Candidate Submission: Service Request & Approval API for a Business SaaS Platform  
> Built with **Laravel 12** · **PHP 8.2+** · **MySQL / SQLite** · **Laravel Sanctum** · **PHPUnit**

---

## Overview

Hi! This repository contains my backend implementation for the internal service request and approval workflow module. 

The API supports three distinct user roles with strict permissions:
1. **Employees:** Create, update, and submit requests for equipment, software, maintenance, or access.
2. **Managers:** Review submitted requests from their direct reports and either approve, reject, or return them with feedback.
3. **Operations:** Pick up approved requests, assign an owner, update status to *in progress*, and mark them *completed*.

---

## Features Implemented

* **Clean State Machine:** A centralized status transition workflow (`draft` $\rightarrow$ `submitted` $\rightarrow$ `approved` $\rightarrow$ `assigned` $\rightarrow$ `in_progress` $\rightarrow$ `completed`). Invalid transitions return a clear `422 Unprocessable Content` response.
* **Role-Based Authorization:** Custom Laravel Policies enforce who can see and modify each request. Managers can only view and act on their own direct reports' requests.
* **Audit Trail & Activity Log:** Every state change, edit, and comment is automatically logged with the actor, previous state, new state, and timestamp.
* **Asynchronous Notifications:** Queued notification jobs run after database commits to send alerts to the right users on status updates.
* **SLA Tracking:** Approved requests calculate an SLA deadline based on priority (Urgent: 8h, High: 24h, Medium: 72h, Low: 120h), with an automated overdue flag.
* **Comprehensive Testing:** 251 automated feature & unit tests covering the happy path, edge cases, permission checks, and validation rules.
* **Interactive Documentation:** Ready-to-use Postman Collection (`docs/postman_collection.json`) and OpenAPI 3.0 specification (`docs/openapi.yaml`).

---

## Quick Setup Guide

### Prerequisites
* **PHP 8.2+** with extensions: `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `zip`, `curl`
* **Composer**
* **MySQL** (via XAMPP, Laragon, or standalone) or **SQLite**

---

### Step 1: Install Dependencies
```bash
composer install
```

### Step 2: Configure Environment
Copy `.env.example` to `.env` and generate the app key:
```bash
cp .env.example .env
php artisan key:generate
```

### Step 3: Choose Your Database

#### Option A: MySQL (Default in `.env`)
1. Ensure your MySQL server is running (e.g., via XAMPP Control Panel).
2. Configure your credentials in `.env`:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=service_requests
   DB_USERNAME=root
   DB_PASSWORD=
   ```
3. Run migrations and seed data:
   ```bash
   php artisan migrate:fresh --seed
   ```
   *(If prompted that `service_requests` does not exist, type **yes** and Laravel will create it for you).*

#### Option B: SQLite (Zero configuration)
```bash
touch database/database.sqlite
# Set DB_CONNECTION=sqlite in .env
php artisan migrate:fresh --seed
```

---

### Step 4: Start the Server & Queue Worker

Run the local development server:
```bash
php artisan serve
```
The API is now running at `http://localhost:8000`.

*(Optional)* In a second terminal, run the queue worker to process asynchronous notifications:
```bash
php artisan queue:work
```

---

## Seeded Test Accounts

The seeder populates the database with realistic team members and sample requests across all statuses:

| Role | Name | Email | Password | Details |
|---|---|---|---|---|
| **Employee** | Eli | `employee@example.com` | `password` | Reports to Maya |
| **Employee** | Emma | `employee2@example.com` | `password` | Reports to Maya |
| **Employee** | Nina | `employee3@example.com` | `password` | Reports to Marcus (separate team) |
| **Manager** | Maya | `manager@example.com` | `password` | Manages Eli and Emma |
| **Manager** | Marcus | `manager2@example.com` | `password` | Manages Nina |
| **Operations** | Olivia | `ops@example.com` | `password` | Operations queue |
| **Operations** | Omar | `ops2@example.com` | `password` | Operations queue |

---

## Running the Automated Tests

All tests run against an in-memory SQLite database (`:memory:`) configured in `phpunit.xml`, so running tests is fast and will not affect your MySQL development database.

```bash
# Run all 251 tests
php artisan test

# Run code style check (Laravel Pint)
composer lint

# Run static analysis (Larastan level 8)
composer analyse

# Run all checks at once
composer check
```

---

## The Workflow Lifecycle

```text
       ┌───────────┐
       │   Draft   │ ◄───────┐
       └─────┬─────┘         │
             │ (submit)      │ (return with comment)
             ▼               │
       ┌───────────┐         │
       │ Submitted ├─────────┤
       └─────┬─────┴─────────┘
             │ (approve)     │ (reject - final)
             ▼               ▼
       ┌───────────┐   ┌───────────┐
       │ Approved  │   │ Rejected  │
       └─────┬─────┘   └───────────┘
             │ (assign)
             ▼
       ┌───────────┐
       │ Assigned  │
       └─────┬─────┘
             │ (start work)
             ▼
       ┌───────────┐
       │In Progress│
       └─────┬─────┘
             │ (complete)
             ▼
       ┌───────────┐
       │ Completed │ (final)
       └───────────┘
```

---

## API Endpoints Summary

| Method | Endpoint | Allowed Role | Purpose |
|---|---|---|---|
| `POST` | `/api/login` | Anyone | Authenticate and receive Sanctum Bearer token |
| `GET` | `/api/me` | Authenticated | View current authenticated user details |
| `POST` | `/api/logout` | Authenticated | Revoke current token |
| `POST` | `/api/requests` | Employee | Create draft request (or submit directly with `"submit": true`) |
| `GET` | `/api/requests` | All | List requests (scoped by role with filters, search, and pagination) |
| `GET` | `/api/requests/{id}` | Participants | View full request details with comments and history |
| `PUT` | `/api/requests/{id}` | Employee | Edit request details (only when in `draft` or `returned` status) |
| `POST` | `/api/requests/{id}/submit` | Employee | Submit draft request for manager review |
| `POST` | `/api/requests/{id}/decision` | Manager | Approve, reject, or return a request with a comment |
| `POST` | `/api/requests/{id}/assign` | Operations | Assign an approved request to an operations owner |
| `POST` | `/api/requests/{id}/status` | Operations | Update request status to `in_progress` or `completed` |
| `POST` | `/api/requests/{id}/comments` | Participants | Post a discussion comment |
| `GET` | `/api/requests/{id}/activities` | Participants | View the chronological audit trail |
| `GET` | `/api/notifications` | Authenticated | View user's in-app notifications |

---

## Assumptions & Design Decisions

Where the assignment brief was silent, I made the following decisions:

1. **Explicit `assigned` status:** Added between `approved` and `in_progress` to cleanly mirror the requirement of assigning work before starting it.
2. **Manager boundaries:** Managers can only see and review requests submitted by employees who have them listed as their `manager_id`. They cannot approve requests from other teams.
3. **Operations queue:** Operations staff see all requests from `approved` onwards. Any operations member can claim, assign, or re-assign a request to ensure team flexibility.
4. **Final states:** `rejected` and `completed` are final states. Requests in these statuses cannot be updated, transitioned, or commented on.
5. **Mandatory decision comments:** When a manager rejects or returns a request, a comment explaining why is mandatory so the employee understands what to fix.
6. **Consistent JSON responses:** All responses follow a unified structure:
   - Success: `{"data": ...}` (with pagination `links` and `meta` on lists)
   - Errors: `{"message": "...", "code": "...", "errors": {...}}`
   Even if a client forgets to send `Accept: application/json`, the API always responds in JSON rather than HTML.
7. **Concurrency protection:** Database transactions with `lockForUpdate` are used during status transitions so multiple concurrent actions cannot corrupt request history.

---

## What I Learned & What I Would Do Next

Building this assignment was a great hands-on experience in structuring clean, testable Laravel applications. If I were extending this project further for production, I would:

* **File Attachments:** Allow employees to upload receipts, screenshots, or error logs to their requests (using Laravel Storage / S3).
* **Fallback Approver Hierarchy:** Support multi-level approvals or backup managers when a primary manager is out of office.
* **Business-Hours SLA:** Enhance SLA calculations to only count working hours (e.g., 9 AM – 5 PM Monday–Friday) and exclude company holidays.
* **Email Provider Integration:** Connect with Mailgun/SES and add webhook handling for email notifications.
* **Docker Setup:** Add a clean `docker-compose.yml` for zero-install containerized deployment.

---

## Documentation Links

* **Architecture Note:** [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
* **AI Usage & References:** [`docs/AI_AND_REFERENCES.md`](docs/AI_AND_REFERENCES.md)
* **Postman Collection:** [`docs/postman_collection.json`](docs/postman_collection.json)
* **OpenAPI Specification:** [`docs/openapi.yaml`](docs/openapi.yaml)
