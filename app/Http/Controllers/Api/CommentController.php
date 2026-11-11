<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Http\JsonResponse;

class CommentController extends Controller
{
    public function store(StoreCommentRequest $request, ServiceRequest $serviceRequest, RequestWorkflow $workflow): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $comment = $workflow->addComment($serviceRequest, $user, $request->validated('body'));

        return CommentResource::make($comment->load('author'))->response()->setStatusCode(201);
    }
}
