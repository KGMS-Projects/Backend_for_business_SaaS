<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The audit trail of one request, oldest first, paginated. */
class ActivityController extends Controller
{
    public function index(Request $request, ServiceRequest $serviceRequest): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('service_requests.pagination.max')],
        ]);

        $activities = $serviceRequest->activities()
            ->with('actor')
            ->paginate((int) ($validated['per_page'] ?? config('service_requests.pagination.default')))
            ->withQueryString();

        return ActivityResource::collection($activities);
    }
}
