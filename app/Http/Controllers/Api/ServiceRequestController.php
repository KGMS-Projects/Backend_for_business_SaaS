<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListServiceRequestsRequest;
use App\Http\Requests\StoreServiceRequestRequest;
use App\Http\Requests\UpdateServiceRequestRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Who may call each endpoint is declared in routes/api.php (`->can(...)`), so by the time
 * code here runs, the caller is allowed. Business rules live in RequestWorkflow.
 */
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
            ->with(['requester', 'assignee']) // eager load: a page of N requests costs the same number of queries as one
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', $request->sortDirection()) // stable order for pagination
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

    private function respond(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return ServiceRequestResource::make($serviceRequest->load(['requester', 'assignee']));
    }
}
