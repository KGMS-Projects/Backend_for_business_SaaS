<?php

namespace App\Http\Requests;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query-string rules for GET /api/requests. Sort columns are whitelisted: user input never reaches ORDER BY unchecked. */
class ListServiceRequestsRequest extends FormRequest
{
    public const SORTABLE = ['created_at', 'updated_at', 'due_at', 'title'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Be liberal in what we accept from query strings:
     *  - ?status=approved,assigned  as well as  ?status[]=approved&status[]=assigned
     *  - ?overdue=true|false  as well as  ?overdue=1|0  (anything else is still a validation error)
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('status'))) {
            $this->merge(['status' => array_filter(array_map('trim', explode(',', $this->query('status'))))]);
        }

        if (is_string($this->query('overdue'))) {
            $flag = filter_var($this->query('overdue'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($flag !== null) {
                $this->merge(['overdue' => $flag]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(RequestStatus::class)],
            'priority' => ['nullable', Rule::enum(RequestPriority::class)],
            'category' => ['nullable', Rule::enum(RequestCategory::class)],
            'overdue' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('service_requests.pagination.max')],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'status' => $this->validated('status'),
            'priority' => $this->validated('priority'),
            'category' => $this->validated('category'),
            'overdue' => $this->boolean('overdue'),
        ];
    }

    public function sortColumn(): string
    {
        return $this->validated('sort') ?? 'created_at';
    }

    /** Newest first when no sort is requested; otherwise ascending unless told differently. */
    public function sortDirection(): string
    {
        return $this->validated('direction') ?? ($this->validated('sort') ? 'asc' : 'desc');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? config('service_requests.pagination.default'));
    }
}
