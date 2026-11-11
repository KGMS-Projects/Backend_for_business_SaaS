<?php

namespace App\Http\Requests;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateServiceRequestRequest extends FormRequest
{
    private const FIELDS = ['title', 'description', 'category', 'priority'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'category' => ['sometimes', 'required', Rule::enum(RequestCategory::class)],
            'priority' => ['sometimes', 'required', Rule::enum(RequestPriority::class)],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->hasAny(self::FIELDS)) {
                    $validator->errors()->add('title', 'Provide at least one field to update: '.implode(', ', self::FIELDS).'.');
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function requestData(): array
    {
        return $this->safe()->only(self::FIELDS);
    }
}
