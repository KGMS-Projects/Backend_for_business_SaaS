<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\RequestActivity;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Decides who should hear about a status change. The person who made the change is never notified.
 */
final class RequestRecipients
{
    /**
     * @return Collection<int, User>
     */
    public function for(RequestActivity $activity): Collection
    {
        $request = $activity->serviceRequest;
        $requester = $request->requester;

        /** @var list<User|null> $candidates */
        $candidates = match ($activity->to_status) {
            // Needs a decision from the requester's manager.
            RequestStatus::Submitted => [$requester->manager],
            // The requester hears the good news; operations has new work waiting.
            RequestStatus::Approved => [$requester, ...User::query()->where('role', Role::Operations->value)->get()->all()],
            RequestStatus::Rejected, RequestStatus::Returned, RequestStatus::InProgress => [$requester],
            RequestStatus::Assigned => [$request->assignee, $requester],
            RequestStatus::Completed => [$requester, $requester->manager],
            default => [],
        };

        return collect($candidates)
            ->filter()
            ->reject(fn (User $user) => $user->id === $activity->actor_id)
            ->unique('id')
            ->values();
    }
}
