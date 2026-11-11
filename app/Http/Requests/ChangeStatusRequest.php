<?php

namespace App\Http\Requests;

use App\Enums\RequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // This endpoint only moves work forward. Approvals, rejections etc. have their own endpoints.
            'status' => ['required', Rule::in([RequestStatus::InProgress->value, RequestStatus::Completed->value])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function targetStatus(): RequestStatus
    {
        return RequestStatus::from($this->validated('status'));
    }
}
