<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
           'employee_id' => [
            'required',
                Rule::exists('employees', 'id')->where(function ($query) {
                    $query->where('store_id', $this->route('store')->id);
                }),
              ],

            'skill_id' => [
                'required',
                'exists:skills,id',
                Rule::unique('employee_skills')->where(function ($q) {
                    return $q->where('employee_id', $this->employee_id);
                }),
            ],

            'rating' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}