<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // An owner must be somebody who can actually do the work.
            'assignee_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::Operations->value)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['assignee_id.exists' => 'The assignee must be an existing operations user.'];
    }
}
