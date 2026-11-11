<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\DecisionAction;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Exceptions\WorkflowException;
use App\Jobs\SendRequestStatusNotification;
use App\Models\Comment;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Handles service request workflow transitions, status updates, and activity logging.
 */
class RequestWorkflow
{
    /**
     * @param  array{title: string, description: string, category: string, priority?: string|null}  $data
     */
    public function create(User $actor, array $data, bool $submit = false): ServiceRequest
    {
        return DB::transaction(function () use ($actor, $data, $submit) {
            $request = new ServiceRequest($data);
            $request->priority = RequestPriority::tryFrom((string) ($data['priority'] ?? '')) ?? RequestPriority::Medium;
            $request->requester()->associate($actor);
            $request->forceFill(['status' => RequestStatus::Draft])->save();

            $this->record($request, $actor, ActivityType::Created, null, RequestStatus::Draft);

            if ($submit) {
                $this->transition($request, RequestStatus::Submitted, $actor, null);
            }

            return $request;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the editable fields (title, description, category, priority).
     */
    public function update(ServiceRequest $request, User $actor, array $data): ServiceRequest
    {
        return $this->locked($request, function (ServiceRequest $request) use ($actor, $data) {
            if (! $request->status->isEditable()) {
                throw WorkflowException::notEditable($request->status);
            }

            $request->fill($data);

            $changes = [];
            foreach (array_keys($request->getDirty()) as $field) {
                $changes[$field] = [
                    'from' => $request->getRawOriginal($field),
                    'to' => $request->getAttributes()[$field],
                ];
            }

            if ($changes === []) {
                return $request; // nothing changed, nothing to audit
            }

            $request->save();
            $this->record($request, $actor, ActivityType::Updated, $request->status, $request->status, null, ['changes' => $changes]);

            return $request;
        });
    }

    /** Draft or Returned -> Submitted. */
    public function submit(ServiceRequest $request, User $actor, ?string $comment = null): ServiceRequest
    {
        return $this->locked($request, fn (ServiceRequest $request) => $this->transition($request, RequestStatus::Submitted, $actor, $comment));
    }

    /** Manager review: Submitted -> Approved | Rejected | Returned. */
    public function decide(ServiceRequest $request, User $actor, DecisionAction $action, ?string $comment = null): ServiceRequest
    {
        return $this->locked($request, fn (ServiceRequest $request) => $this->transition($request, $action->targetStatus(), $actor, $comment));
    }

    /**
     * Operations picks an owner. From "approved" this also moves the request to "assigned";
     * from "assigned" / "in_progress" it just hands the work to someone else.
     */
    public function assign(ServiceRequest $request, User $actor, User $assignee, ?string $comment = null): ServiceRequest
    {
        return $this->locked($request, function (ServiceRequest $request) use ($actor, $assignee, $comment) {
            if (! $request->status->acceptsAssignment()) {
                throw WorkflowException::cannotAssign($request->status);
            }

            $meta = ['assignee_id' => $assignee->id, 'previous_assignee_id' => $request->assignee_id];

            if ($request->status === RequestStatus::Approved) {
                return $this->transition($request, RequestStatus::Assigned, $actor, $comment, ActivityType::Assigned, ['assignee_id' => $assignee->id], $meta);
            }

            // Re-assignment: same status, new owner.
            $request->forceFill(['assignee_id' => $assignee->id])->save();
            $activity = $this->record($request, $actor, ActivityType::Assigned, $request->status, $request->status, $comment, $meta);
            $this->announce($activity);

            return $request;
        });
    }

    /** Operations moves work forward: Assigned -> InProgress -> Completed. */
    public function changeStatus(ServiceRequest $request, User $actor, RequestStatus $to, ?string $comment = null): ServiceRequest
    {
        if (! in_array($to, [RequestStatus::InProgress, RequestStatus::Completed], true)) {
            throw new InvalidArgumentException('Operations can only move a request to in_progress or completed.');
        }

        return $this->locked($request, fn (ServiceRequest $request) => $this->transition($request, $to, $actor, $comment));
    }

    public function addComment(ServiceRequest $request, User $actor, string $body): Comment
    {
        return $this->locked($request, function (ServiceRequest $request) use ($actor, $body) {
            if ($request->status->isFinal()) {
                throw WorkflowException::commentsClosed($request->status);
            }

            /** @var Comment $comment */
            $comment = $request->comments()->create(['user_id' => $actor->id, 'body' => $body]);
            $this->record($request, $actor, ActivityType::Commented, null, null, null, ['comment_id' => $comment->id]);

            return $comment;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Run $callback inside a transaction on a freshly re-read, row-locked copy of the request.
     * (lockForUpdate is a no-op on SQLite, which serialises writers anyway; it matters on MySQL/PostgreSQL.)
     *
     * @template T
     *
     * @param  Closure(ServiceRequest): T  $callback
     * @return T
     */
    private function locked(ServiceRequest $request, Closure $callback): mixed
    {
        return DB::transaction(function () use ($request, $callback) {
            /** @var ServiceRequest $fresh */
            $fresh = ServiceRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            return $callback($fresh);
        });
    }

    /**
     * The one guarded status change. Throws WorkflowException if the lifecycle forbids it.
     *
     * @param  array<string, mixed>  $attributes  extra columns to set together with the status
     * @param  array<string, mixed>  $meta
     */
    private function transition(
        ServiceRequest $request,
        RequestStatus $to,
        User $actor,
        ?string $comment,
        ActivityType $type = ActivityType::Transitioned,
        array $attributes = [],
        array $meta = [],
    ): ServiceRequest {
        $from = $request->status;

        if (! $from->canTransitionTo($to)) {
            throw WorkflowException::invalidTransition($from, $to);
        }

        $request->forceFill(['status' => $to] + $attributes + $this->timestampsFor($request, $to))->save();

        $this->announce($this->record($request, $actor, $type, $from, $to, $comment, $meta));

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function timestampsFor(ServiceRequest $request, RequestStatus $to): array
    {
        return match ($to) {
            RequestStatus::Submitted => ['submitted_at' => now()],
            // The SLA clock starts when operations receives the request.
            RequestStatus::Approved => ['due_at' => now()->addHours($request->priority->slaHours())],
            RequestStatus::Completed => ['completed_at' => now()],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function record(
        ServiceRequest $request,
        User $actor,
        ActivityType $type,
        ?RequestStatus $from = null,
        ?RequestStatus $to = null,
        ?string $comment = null,
        array $meta = [],
    ): RequestActivity {
        return $request->activities()->create([
            'actor_id' => $actor->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'comment' => $comment,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** Queue the notification; it only runs once the surrounding transaction has committed. */
    private function announce(RequestActivity $activity): void
    {
        if (config('service_requests.notifications_enabled')) {
            SendRequestStatusNotification::dispatch($activity)->afterCommit();
        }
    }
}
