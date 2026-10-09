<?php

namespace App\Services\Humanity;

use App\Models\Employee;
use App\Services\Humanity\Exceptions\HumanityException;
use Illuminate\Support\Facades\Log;

/**
 * Fills in an employee's Humanity id from their TCP one.
 *
 * TCP's connector propagates an employee into Humanity within minutes of
 * HiringPizza's TCP push, setting the new record's `eid` to the TCP employee
 * id — confirmed live: the id Humanity shows in its own links/URLs (what
 * getEmployee/assignEmployees actually need) is Humanity's OWN id and is NOT
 * the TCP id, but `eid` is a distinct field that does match it.
 *
 * Nothing of ours writes Humanity's employee records — this is a read plus a
 * local write, which is why it is safe to run from a background job as well as
 * from the shift-write path.
 */
class HumanityEmployeeLinker
{
    public function __construct(private readonly HumanityClientInterface $humanity)
    {
    }

    /**
     * One targeted lookup. Returns the Humanity id, or null if the connector
     * has not carried this employee across yet.
     *
     * Callers treat null as "not yet", never as an error: the employee may
     * simply have been pushed to TCP moments ago.
     */
    public function link(Employee $employee): ?string
    {
        if ($employee->isLinkedToHumanity()) {
            return (string) $employee->humanity_employee_id;
        }

        if (!$employee->isLinkedToTcp()) {
            return null;
        }

        try {
            $record = $this->humanity->findEmployeeByEid((string) $employee->tcp_employee_id);
        } catch (HumanityException $e) {
            Log::warning('Live Humanity eid lookup failed', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $humanityId = $record['id'] ?? null;

        if ($humanityId === null || $humanityId === '') {
            return null;
        }

        $humanityId = (string) $humanityId;

        $employee->forceFill([
            'humanity_employee_id' => $humanityId,
            'humanity_synced_at' => now(),
        ])->save();

        return $humanityId;
    }
}
