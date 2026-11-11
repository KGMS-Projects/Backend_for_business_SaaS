<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignRequest;
use App\Http\Requests\ChangeStatusRequest;
use App\Http\Requests\DecisionRequest;
use App\Http\Requests\ListServiceRequestsRequest;
use App\Http\Requests\StoreServiceRequestRequest;
use App\Http\Requests\UpdateServiceRequestRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceRequestController extends Controller
{
    public function __construct(private readonly RequestWorkflow $workflow) {}

    public function index(ListServiceRequestsRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $requests = ServiceRequest::query()
            ->visibleTo($user)
            ->filter($request->filters())
            ->with(['requester', 'assignee'])
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', $request->sortDirection())
            ->paginate($request->perPage())
            ->withQueryString();

        return ServiceRequestResource::collection($requests);
    }

    public function store(StoreServiceRequestRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $serviceRequest = $this->workflow->create($user, $request->requestData(), $request->shouldSubmit());

        return $this->respond($serviceRequest)->response()->setStatusCode(201);
    }

    public function show(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return ServiceRequestResource::make(
            $serviceRequest->load(['requester', 'assignee', 'comments.author', 'activities.actor']),
        );
    }

    public function update(UpdateServiceRequestRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->workflow->update($serviceRequest, $user, $request->requestData()));
    }

    public function submit(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->workflow->submit($serviceRequest, $user));
    }

    public function decision(DecisionRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->workflow->decide(
            $serviceRequest,
            $user,
            $request->action(),
            $request->validated('comment'),
        ));
    }

    public function assign(AssignRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        /** @var User $assignee */
        $assignee = User::query()->findOrFail($request->validated('assignee_id'));

        return $this->respond($this->workflow->assign(
            $serviceRequest,
            $user,
            $assignee,
            $request->validated('comment'),
        ));
    }

    public function status(ChangeStatusRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->workflow->changeStatus(
            $serviceRequest,
            $user,
            $request->targetStatus(),
            $request->validated('comment'),
        ));
    }

    private function respond(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return ServiceRequestResource::make($serviceRequest->load(['requester', 'assignee']));
    }
}
