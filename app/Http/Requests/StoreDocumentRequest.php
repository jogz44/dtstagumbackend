<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tracking_number'   => ['nullable', 'string', 'max:100'],
            'origin_sender'     => ['required', 'string', 'max:255'],
            'sender_name'       => ['nullable', 'string', 'max:255'],
            'issued_by'         => ['required', 'string', 'max:255'],
            'contact_number'    => ['nullable', 'string', 'max:50'],
            'document_type'     => ['required', 'string', 'max:100'],
            'priority_level'    => ['required', 'string', 'max:100'],
            'description'       => ['required', 'string', 'max:255'],
            'due_date'          => ['nullable', 'date'],
            'remarks'           => ['nullable', 'string'],
            'is_confidential'   => ['sometimes', 'boolean'],
            'self_assign'       => ['sometimes', 'boolean'],
            'send_to_all'       => ['sometimes', 'boolean'],
            'is_internal'       => ['sometimes', 'boolean'],
            'office_assignment' => ['nullable', 'string', 'max:255'],
            'division_assignment' => ['nullable', 'string', 'max:255'],
            'assigned_user'     => ['nullable', 'string', 'max:255'],
            'is_read_only'      => ['sometimes', 'boolean'],
            'notes'             => ['nullable', 'string'],
            'attachments'       => ['nullable', 'array'],
            'attachments.*'     => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
