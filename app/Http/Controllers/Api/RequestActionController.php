<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignRequest;
use App\Http\Requests\ChangeStatusRequest;
use App\Http\Requests\DecisionRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Http\Request;

/** The workflow verbs: submit, decide, assign, change status. Each is a thin wrapper over RequestWorkflow. */
class RequestActionController extends Controller
{
    public function __construct(private readonly RequestWorkflow $workflow) {}

    public function submit(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return $this->respond($this->workflow->submit($serviceRequest, $this->actor($request)));
    }

    public function decision(DecisionRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return $this->respond($this->workflow->decide(
            $serviceRequest,
            $this->actor($request),
            $request->action(),
            $request->validated('comment'),
        ));
    }

    public function assign(AssignRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        /** @var User $assignee */
        $assignee = User::query()->findOrFail($request->validated('assignee_id'));

        return $this->respond($this->workflow->assign(
            $serviceRequest,
            $this->actor($request),
            $assignee,
            $request->validated('comment'),
        ));
    }

    public function status(ChangeStatusRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return $this->respond($this->workflow->changeStatus(
            $serviceRequest,
            $this->actor($request),
            $request->targetStatus(),
            $request->validated('comment'),
        ));
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function respond(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return ServiceRequestResource::make($serviceRequest->load(['requester', 'assignee']));
    }
}
