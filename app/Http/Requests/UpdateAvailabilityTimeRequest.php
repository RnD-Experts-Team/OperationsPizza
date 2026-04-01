<?php

namespace App\Http\Requests;

use App\Models\Availability;
use App\Models\AvailabilityTime;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAvailabilityTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'availability_id' => ['sometimes', 'exists:availabilities,id'],
            'from' => ['sometimes', 'date_format:H:i'],
            'to' => ['sometimes', 'date_format:H:i'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            $id = $this->route('availability_time') ?? $this->route('id');

            $record = AvailabilityTime::find($id);

            $availabilityId = $this->availability_id ?? $record?->availability_id;
            $from = $this->from ?? $record?->from;
            $to = $this->to ?? $record?->to;

            if (!$availabilityId || !$from || !$to) return;

            // 1. from < to
            if ($from >= $to) {
                $validator->errors()->add('from', 'From must be less than To');
                return;
            }

            $availability = Availability::find($availabilityId);

            $availabilities = Availability::where('employee_id', $availability->employee_id)
                ->where('day_of_week', $availability->day_of_week)
                ->pluck('id');

            // 2. overlap excluding current
            $overlap = AvailabilityTime::whereIn('availability_id', $availabilities)
                ->where('id', '!=', $id)
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