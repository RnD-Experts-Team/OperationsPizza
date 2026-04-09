<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\DayOff;

class UpdateDayOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $managerNote = $this->managerNote;

        if (is_string($managerNote)) {
            $managerNote = trim($managerNote);
            $managerNote = preg_replace('/\s+/', ' ', $managerNote);
        }

        $this->merge([
            'acceptedStatus' => is_string($this->acceptedStatus)
                ? strtolower(trim($this->acceptedStatus))
                : $this->acceptedStatus,

            'managerNote' => $managerNote,
        ]);
    }

    public function rules(): array
    {
        $dayOffId = $this->route('day_off');
        $storeId = $this->route('store');

        return [
            'date' => [
                'sometimes',
                'date',
                function ($attribute, $value, $fail) use ($dayOffId, $storeId) {
                    $dayOff = DayOff::where('id', $dayOffId)
                        ->whereHas('employee', function ($q) use ($storeId) {
                            $q->where('store_id', $storeId);
                        })
                        ->first();

                    if (!$dayOff) {
                        return;
                    }

                    $exists = DayOff::where('employee_id', $dayOff->employee_id)
                        ->where('date', $value)
                        ->where('id', '!=', $dayOffId)
                        ->exists();

                    if ($exists) {
                        $fail('This employee already has a day off request on this date.');
                    }
                }
            ],

            'managerNote' => ['required', 'string'],

            'acceptedStatus' => [
                'required',
                Rule::in(['pending', 'approved', 'rejected'])
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'date.date' => 'Date must be a valid date.',
            'managerNote.required' => 'Manager note is required.',
            'managerNote.string' => 'Manager note must be a string.',
            'acceptedStatus.required' => 'Accepted status is required.',
            'acceptedStatus.in' => 'Accepted status is invalid.',
        ];
    }
}