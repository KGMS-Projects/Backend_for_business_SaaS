<?php

namespace App\Http\Requests;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation is declared on the route (`->can('create', ...)`) so it runs before validation. */
class StoreServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(RequestCategory::class)],
            'priority' => ['nullable', Rule::enum(RequestPriority::class)],
            // true = create and send for manager review in one call; false/omitted = save as draft.
            'submit' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{title: string, description: string, category: string, priority?: string|null}
     */
    public function requestData(): array
    {
        /** @var array{title: string, description: string, category: string, priority?: string|null} */
        return $this->safe()->only(['title', 'description', 'category', 'priority']);
    }

    public function shouldSubmit(): bool
    {
        return $this->boolean('submit');
    }
}
