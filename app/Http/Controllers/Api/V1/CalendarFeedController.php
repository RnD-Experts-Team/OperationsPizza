<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/calendar/feed?stores=03795-00001[,…]&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Operations' part of the unified calendar (calendar-feed/v1). Kept light — no
 * event per shift:
 *   shift_day     per store and day — who is scheduled, as counts
 *   shift         only MISSED planned shifts (over, nobody clocked in)
 *   actual_shift  only OPEN attendance (clocked in, not out)
 *   time_off      approved time off
 *
 * A "manager" is someone on a shift whose Humanity position name contains
 * "manager".
 *
 * Who may see which stores is pizzasys's decision: the auth middleware forwards
 * the query string and the rule for this route reads `stores` from it (policy
 * "all"). We only refuse numbers we do not know, because pizzasys silently drops
 * those instead of refusing them.
 *
 * Times are UTC (shifts already store starts_at_utc / ends_at_utc). Never sent:
 * hourly_rate, pay, birth_date, contacts, availability, notes.
 */
class CalendarFeedController extends Controller
{
    private const MAX_STORES = 50;

    private const MAX_DAYS = 62;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stores' => ['required', 'string'],
            'from'   => ['required', 'date_format:Y-m-d'],
            'to'     => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $numbers = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['stores'])))));
        if ($numbers === [] || count($numbers) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Date range may not exceed ' . self::MAX_DAYS . ' days.']);
        }

        // id => store_number
        $stores = DB::table('stores')->whereNull('deleted_at')->whereIn('store_number', $numbers)->pluck('store_number', 'id');
        $unknown = array_values(array_diff($numbers, $stores->values()->all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['stores' => 'Unknown store: ' . implode(', ', $unknown) . '.']);
        }

        $from = $data['from'];
        $to = $data['to'];

        $planned = $this->planned($stores, $from, $to);
        $actual = $this->actual($stores, $from, $to);
        $attended = $actual->pluck('shift_assignment_id')->filter()->flip();

        $events = array_merge(
            $this->shiftDays($stores, $planned, $attended),
            $this->missedShifts($stores, $planned, $attended),
            $this->openAttendance($stores, $actual),
            $this->timeOff($stores, $from, $to),
        );

        usort($events, fn ($a, $b) => [$a['date'], $a['start'] ?? '', $a['id']] <=> [$b['date'], $b['start'] ?? '', $b['id']]);

        return response()->json([
            'contract'     => 'calendar-feed/v1',
            'source'       => 'operations',
            'generated_at' => $this->utc(now()),
            'range'        => ['from' => $from, 'to' => $to],
            'stores'       => array_map(fn ($n) => ['number' => $n, 'timezone' => 'UTC'], $numbers),
            'events'       => $events,
            'meta'         => ['count' => count($events), 'truncated' => false],
        ]);
    }

    /** Assigned planned shifts, one row per (shift, employee). */
    private function planned(Collection $stores, string $from, string $to): Collection
    {
        return DB::table('shift_assignments as sa')
            ->join('shifts as s', 's.id', '=', 'sa.shift_id')
            ->join('employees as e', 'e.id', '=', 'sa.employee_id')
            ->leftJoin('humanity_positions as hp', 'hp.humanity_position_id', '=', 's.humanity_position_id')
            ->whereNull('sa.deleted_at')
            ->whereNull('s.deleted_at')
            ->where('sa.status', 'assigned')
            ->whereIn('s.store_id', $stores->keys())
            ->whereBetween('s.shift_date', [$from, $to])
            ->orderBy('s.starts_at_utc')
            ->get([
                'sa.id as assignment_id', 's.store_id', 's.shift_date', 's.starts_at_utc', 's.ends_at_utc', 's.updated_at',
                'e.id as employee_id', 'e.first_name', 'e.last_name', 'hp.name as position',
            ]);
    }

    /** Attendance in the range. */
    private function actual(Collection $stores, string $from, string $to): Collection
    {
        return DB::table('actual_shifts as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->whereNull('a.deleted_at')
            ->whereIn('a.store_id', $stores->keys())
            ->whereBetween('a.shift_date', [$from, $to])
            ->get([
                'a.id', 'a.store_id', 'a.shift_assignment_id', 'a.shift_date', 'a.starts_at_utc', 'a.ends_at_utc', 'a.is_open', 'a.updated_at',
                'e.id as employee_id', 'e.first_name', 'e.last_name',
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function shiftDays(Collection $stores, Collection $planned, Collection $attended): array
    {
        $today = Carbon::now('UTC')->toDateString();
        $now = Carbon::now('UTC');
        $events = [];

        foreach ($planned->groupBy(fn ($p) => $p->store_id . '|' . Carbon::parse($p->shift_date)->toDateString()) as $rows) {
            $first = $rows->first();
            $store = $stores[$first->store_id];
            $date = Carbon::parse($first->shift_date)->toDateString();

            $done = $rows->filter(fn ($p) => $attended->has($p->assignment_id))->count();
            $missed = $rows->filter(fn ($p) => ! $attended->has($p->assignment_id) && Carbon::parse($p->ends_at_utc, 'UTC')->lt($now))->count();
            $employees = $rows->pluck('employee_id')->unique()->count();
            $managers = $rows->filter(fn ($p) => $this->isManager($p->position))->pluck('employee_id')->unique()->count();

            $events[] = [
                'id'         => "operations:shift_day:{$store}:{$date}",
                'type'       => 'shift_day',
                'layer'      => 'people',
                'store'      => $store,
                'title'      => "Working {$employees} · managers {$managers}" . ($missed ? " · {$missed} missed" : ''),
                'all_day'    => true,
                'date'       => $date,
                'date_end'   => null,
                'start'      => null,
                'end'        => null,
                'status'     => match (true) {
                    $missed > 0     => 'missed',
                    $date === $today => 'in_progress',
                    $date > $today  => 'scheduled',
                    default         => 'done',
                },
                'people'     => [],
                'link'       => '/schedule?' . http_build_query(['store' => $store, 'date' => $date]),
                'updated_at' => $this->utc($rows->max('updated_at')),
                'counts'     => [
                    'employees' => $employees,
                    'managers'  => $managers,
                    'done'      => $done,
                    'missed'    => $missed,
                    'scheduled' => $rows->count() - $done - $missed,
                    'total'     => $rows->count(),
                ],
            ];
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function missedShifts(Collection $stores, Collection $planned, Collection $attended): array
    {
        $now = Carbon::now('UTC');

        return $planned
            ->filter(fn ($p) => ! $attended->has($p->assignment_id) && Carbon::parse($p->ends_at_utc, 'UTC')->lt($now))
            ->map(function ($p) use ($stores) {
                $store = $stores[$p->store_id];
                $date = Carbon::parse($p->shift_date)->toDateString();
                $name = $this->shortName($p->first_name, $p->last_name);

                return [
                    'id'         => 'operations:shift:' . $p->assignment_id,
                    'type'       => 'shift',
                    'layer'      => 'people',
                    'store'      => $store,
                    'title'      => "Missed: {$name}" . ($p->position ? " ({$p->position})" : ''),
                    'all_day'    => false,
                    'date'       => $date,
                    'date_end'   => null,
                    'start'      => $this->utc($p->starts_at_utc),
                    'end'        => $this->utc($p->ends_at_utc),
                    'status'     => 'missed',
                    'people'     => [$this->person($p->employee_id, $name, $p->position)],
                    'link'       => '/schedule?' . http_build_query(['store' => $store, 'date' => $date]),
                    'updated_at' => $this->utc($p->updated_at),
                    'extra'      => ['is_manager' => $this->isManager($p->position)],
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function openAttendance(Collection $stores, Collection $actual): array
    {
        return $actual
            ->filter(fn ($a) => (bool) $a->is_open)
            ->map(function ($a) use ($stores) {
                $store = $stores[$a->store_id];
                $date = Carbon::parse($a->shift_date)->toDateString();
                $name = $this->shortName($a->first_name, $a->last_name);

                return [
                    'id'         => 'operations:actual_shift:' . $a->id,
                    'type'       => 'actual_shift',
                    'layer'      => 'people',
                    'store'      => $store,
                    'title'      => "Clocked in: {$name}",
                    'all_day'    => false,
                    'date'       => $date,
                    'date_end'   => null,
                    'start'      => $this->utc($a->starts_at_utc),
                    'end'        => null,
                    'status'     => 'in_progress',
                    'people'     => [$this->person($a->employee_id, $name)],
                    'link'       => '/schedule?' . http_build_query(['store' => $store, 'date' => $date]),
                    'updated_at' => $this->utc($a->updated_at),
                    'extra'      => ['is_open' => true, 'planned' => $a->shift_assignment_id !== null],
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function timeOff(Collection $stores, string $from, string $to): array
    {
        return DB::table('time_off as t')
            ->join('employees as e', 'e.id', '=', 't.employee_id')
            ->whereNull('t.deleted_at')
            ->where('t.status', 'approved')
            ->whereIn('t.store_id', $stores->keys())
            ->where('t.start_date', '<=', $to)
            ->where('t.end_date', '>=', $from)
            ->orderBy('t.start_date')
            ->get(['t.id', 't.store_id', 't.start_date', 't.end_date', 't.type', 't.updated_at', 'e.id as employee_id', 'e.first_name', 'e.last_name'])
            ->map(function ($t) use ($stores) {
                $store = $stores[$t->store_id];
                $start = Carbon::parse($t->start_date)->toDateString();
                $end = Carbon::parse($t->end_date)->toDateString();
                $name = $this->shortName($t->first_name, $t->last_name);

                return [
                    'id'         => 'operations:time_off:' . $t->id,
                    'type'       => 'time_off',
                    'layer'      => 'people',
                    'store'      => $store,
                    'title'      => "Time off: {$name}",
                    'all_day'    => true,
                    'date'       => $start,
                    'date_end'   => $end !== $start ? $end : null,
                    'start'      => null,
                    'end'        => null,
                    'status'     => 'info',
                    'people'     => [$this->person($t->employee_id, $name)],
                    'link'       => '/schedule?' . http_build_query(['store' => $store, 'date' => $start]),
                    'updated_at' => $this->utc($t->updated_at),
                    'extra'      => ['time_off_type' => $t->type],
                ];
            })
            ->all();
    }

    private function isManager(?string $position): bool
    {
        return $position !== null && stripos($position, 'manager') !== false;
    }

    /** @return array<string, mixed> */
    private function person($id, string $name, ?string $role = null): array
    {
        $person = ['kind' => 'employee', 'id' => (int) $id, 'name' => $name];
        if ($role) {
            $person['role'] = mb_substr($role, 0, 60);
        }

        return $person;
    }

    /** "Ana Lopez" → "Ana L.": first name and the family initial, never more. */
    private function shortName(?string $first, ?string $last): string
    {
        $last = trim((string) $last);

        return trim((string) $first) . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : '');
    }

    private function utc($at): ?string
    {
        return $at ? Carbon::parse($at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
