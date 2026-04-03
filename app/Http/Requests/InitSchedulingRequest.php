<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitSchedulingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],

            // 🔥 فلتر اختياري
            'employee_id' => ['nullable', 'exists:employees,id'],
        ];
    }
}