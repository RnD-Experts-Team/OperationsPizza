<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('employee_skill');

        // 👇 store صار Model
        $storeId = $this->route('store')->id;

        // جلب القيم الحالية
        $employeeSkill = \App\Models\EmployeeSkill::find($id);

        $employeeId = $this->employee_id ?? $employeeSkill?->employee_id;

        return [
            'employee_id' => [
                'sometimes',
                Rule::exists('employees', 'id')->where(function ($q) use ($storeId) {
                    $q->where('store_id', $storeId);
                }),
            ],

            'skill_id' => [
                'sometimes',
                'exists:skills,id',
                Rule::unique('employee_skills')
                    ->where(function ($q) use ($employeeId) {
                        return $q->where('employee_id', $employeeId);
                    })
                    ->ignore($id),
            ],

            'rating' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}