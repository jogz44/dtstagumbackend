<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'division_id' => ['nullable', 'uuid', 'exists:divisions,id'],
            'minimum_on_time_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'in:documents_completed,average_processing_seconds,on_time_rate,overdue_completed_count,productivity_completed_count'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
