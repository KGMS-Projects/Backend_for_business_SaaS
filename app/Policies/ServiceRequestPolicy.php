<?php

namespace App\Policies;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Policy defining permissions for viewing, editing, and managing service requests.
 */
class ServiceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // every authenticated role has a (scoped) list
    }

    public function view(User $user, ServiceRequest $serviceRequest): Response
    {
        return $this->canView($user, $serviceRequest)
            ? Response::allow()
            : Response::deny('You do not have access to this request.');
    }

    public function create(User $user): Response
    {
        return $user->isEmployee()
            ? Response::allow()
            : Response::deny('Only employees can create requests.');
    }

    public function update(User $user, ServiceRequest $serviceRequest): Response
    {
        return $this->isRequester($user, $serviceRequest)
            ? Response::allow()
            : Response::deny('Only the employee who created this request can edit it.');
    }

    public function submit(User $user, ServiceRequest $serviceRequest): Response
    {
        return $this->isRequester($user, $serviceRequest)
            ? Response::allow()
            : Response::deny('Only the employee who created this request can submit it.');
    }

    public function decide(User $user, ServiceRequest $serviceRequest): Response
    {
        return $user->isManager() && $this->canView($user, $serviceRequest)
            ? Response::allow()
            : Response::deny("Only the requester's manager can review this request.");
    }

    public function assign(User $user, ServiceRequest $serviceRequest): Response
    {
        return $this->operationsCanAct($user, $serviceRequest)
            ? Response::allow()
            : Response::deny('Only operations users can assign work.');
    }

    public function changeStatus(User $user, ServiceRequest $serviceRequest): Response
    {
        return $this->operationsCanAct($user, $serviceRequest)
            ? Response::allow()
            : Response::deny('Only operations users can start or complete work.');
    }

    public function comment(User $user, ServiceRequest $serviceRequest): Response
    {
        return $this->canView($user, $serviceRequest)
            ? Response::allow()
            : Response::deny('Only participants can comment on this request.');
    }

    private function isRequester(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->isEmployee() && $serviceRequest->requester_id === $user->id;
    }

    private function operationsCanAct(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->isOperations() && $this->canView($user, $serviceRequest);
    }

    private function canView(User $user, ServiceRequest $serviceRequest): bool
    {
        return match (true) {
            $user->isEmployee() => $serviceRequest->requester_id === $user->id,
            $user->isManager() => $serviceRequest->status !== RequestStatus::Draft
                && $serviceRequest->requester->manager_id === $user->id,
            $user->isOperations() => in_array($serviceRequest->status, RequestStatus::visibleToOperations(), true),
            default => false,
        };
    }
}
