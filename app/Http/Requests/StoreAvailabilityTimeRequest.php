<?php

namespace App\Http\Requests;

use App\Models\Availability;
use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'availability_id' => ['required', 'exists:availabilities,id'],
            'from' => ['required', 'date_format:H:i'],
            'to' => ['required', 'date_format:H:i'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            $from = $this->from;
            $to = $this->to;

            // 1. from < to
            if ($from >= $to) {
                $validator->errors()->add('from', 'From must be less than To');
                return;
            }

            $availability = Availability::find($this->availability_id);

            if (!$availability) return;

            // كل availability لنفس الموظف + نفس اليوم
            $availabilities = Availability::where('employee_id', $availability->employee_id)
                ->where('day_of_week', $availability->day_of_week)
                ->pluck('id');

            // 2. check overlap
            $overlap = \App\Models\AvailabilityTime::whereIn('availability_id', $availabilities)
                ->where(function ($query) use ($from, $to) {
                    $query->whereBetween('from', [$from, $to])
                        ->orWhereBetween('to', [$from, $to])
                        ->orWhere(function ($q) use ($from, $to) {
                            $q->where('from', '<=', $from)
                              ->where('to', '>=', $to);
                        });
                })
                ->exists();

            if ($overlap) {
                $validator->errors()->add('from', 'This time overlaps with an existing time.');
            }
        });
    }
}