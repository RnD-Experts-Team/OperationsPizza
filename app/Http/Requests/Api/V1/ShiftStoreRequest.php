<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ShiftStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is delegated wholesale to pizzasys via
        // AuthTokenStoreScopeMiddleware (ext.authorized), which has already run.
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'min:1'],
            'shift_date' => ['required', 'date_format:Y-m-d'],
            // Accepts "9:00" and "09:00"; the resolver normalises.
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'label' => ['nullable', 'string', 'max:120'],
            'shift_type' => ['nullable', 'in:morning,evening,night,split,custom'],
            'note' => ['nullable', 'string', 'max:2000'],
            'position_label' => ['nullable', 'string', 'max:190'],
            'slots' => ['nullable', 'integer', 'min:1', 'max:99'],
            // Overrides conflict/availability guards, not validation.
            'force' => ['nullable', 'boolean'],
            'recurring' => ['nullable', 'array'],
            'recurring.enabled' => ['nullable', 'boolean'],
            'recurring.weeks_ahead' => ['nullable', 'integer', 'min:1', 'max:52'],
            'recurring.until_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
