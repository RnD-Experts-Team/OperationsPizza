<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ShiftUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'shift_date' => ['sometimes', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['sometimes', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shift_type' => ['sometimes', 'in:morning,evening,night,split,custom'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'position_label' => ['sometimes', 'nullable', 'string', 'max:190'],
            'slots' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'force' => ['nullable', 'boolean'],
        ];
    }
}
