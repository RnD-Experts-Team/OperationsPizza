<?php

namespace App\Http\Requests;

use App\Models\MasterSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMasterScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'store_id' => $this->route('store'),
        ]);
    }

    public function rules(): array
    {
        return [
            'store_id' => ['sometimes', 'exists:stores,id'],

            'start_date' => [
                'sometimes',
                'date',
                function ($attribute, $value, $fail) {
                    if (date('l', strtotime($value)) !== 'Tuesday') {
                        $fail('start_date must be a Tuesday.');
                    }
                },
            ],

            'end_date' => [
                'sometimes',
                'date',
                function ($attribute, $value, $fail) {
                    if (date('l', strtotime($value)) !== 'Monday') {
                        $fail('end_date must be a Monday.');
                    }
                },
            ],

            'schedules' => ['sometimes', 'array', 'min:1'],

            'schedules.*.id' => ['sometimes', 'integer', 'exists:schedules,id'],

            'schedules.*.employee_id' => [
                'nullable',
                Rule::exists('employees', 'id')->where(function ($query) {
                    $masterId = $this->route('id');
                    $currentMaster = MasterSchedule::find($masterId);

                    $storeId = $this->input('store_id') ?? $currentMaster?->store_id;

                    $query->where('store_id', $storeId);
                }),
            ],

            'schedules.*.date' => ['required_with:schedules', 'date'],
            'schedules.*.start_time' => ['required_with:schedules', 'date_format:H:i'],
            'schedules.*.end_time' => ['required_with:schedules', 'date_format:H:i'],

            // 🔥 actual fields
            'schedules.*.actual_start_time' => ['nullable', 'date_format:H:i'],
            'schedules.*.actual_end_time' => ['nullable', 'date_format:H:i'],

            'schedules.*.skill_id' => ['required_with:schedules', 'exists:skills,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {

            $startDate = $this->input('start_date');
            $endDate = $this->input('end_date');
            $schedules = $this->input('schedules', []);

            // 🔥 date range
            if ($startDate && $endDate) {
                $start = Carbon::parse($startDate)->startOfDay();
                $end = Carbon::parse($endDate)->startOfDay();

                if ($start->gte($end)) {
                    $validator->errors()->add('start_date', 'start_date must be before end_date.');
                }

                if (!$start->copy()->addDays(6)->isSameDay($end)) {
                    $validator->errors()->add(
                        'end_date',
                        'The date range must be exactly 7 days (Tuesday to Monday).'
                    );
                }
            }

            foreach ($schedules as $index => $schedule) {

                // 🔥 shift validation
                if (isset($schedule['start_time'], $schedule['end_time'])) {
                    $startTime = Carbon::createFromFormat('H:i', $schedule['start_time']);
                    $endTime = Carbon::createFromFormat('H:i', $schedule['end_time']);

                    if ($endTime->lte($startTime)) {
                        $validator->errors()->add(
                            "schedules.$index.end_time",
                            'end_time must be after start_time.'
                        );
                    }
                }

                // 🔥 actual validation
                if (isset($schedule['actual_start_time'], $schedule['actual_end_time'])) {

                    $actualStart = Carbon::createFromFormat('H:i', $schedule['actual_start_time']);
                    $actualEnd = Carbon::createFromFormat('H:i', $schedule['actual_end_time']);

                    if ($actualEnd->lte($actualStart)) {
                        $validator->errors()->add(
                            "schedules.$index.actual_end_time",
                            'actual_end_time must be after actual_start_time.'
                        );
                    }
                }

                // 🔥 داخل range
                if (isset($schedule['date']) && $startDate && $endDate) {
                    $scheduleDate = Carbon::parse($schedule['date'])->startOfDay();
                    $start = Carbon::parse($startDate)->startOfDay();
                    $end = Carbon::parse($endDate)->startOfDay();

                    if ($scheduleDate->lt($start) || $scheduleDate->gt($end)) {
                        $validator->errors()->add(
                            "schedules.$index.date",
                            'schedule date must be within the master schedule range.'
                        );
                    }
                }
            }
        });
    }
}