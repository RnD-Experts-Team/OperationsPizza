<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Services\Humanity\HumanityEmployeeLinker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Links one employee to their Humanity record, shortly after their TCP id
 * arrives.
 *
 * Dispatched from the event handler that lifts a new TCP id off a HiringPizza
 * snapshot, delayed so TCP's own connector (which runs every ~5 min) has had
 * time to carry the employee into Humanity first. Without this, a link only
 * appeared when someone tried to SCHEDULE the employee, or when the nightly
 * humanity:sync-employees ran — up to a day later.
 *
 * Deliberately best-effort: a miss just means the connector had not finished,
 * and the two existing paths (the live lookup on a shift write, and the
 * nightly backstop) still cover it. Retrying hard here would spend Humanity
 * calls racing a clock we don't control.
 */
class SyncEmployeeToHumanityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $employeeId)
    {
        // Shares the lane with the bulk shift work so Humanity's rate limit is
        // enforced in one place rather than raced from two queues.
        $this->onQueue('humanity');
    }

    public function handle(HumanityEmployeeLinker $linker): void
    {
        $employee = Employee::find($this->employeeId);

        if ($employee === null || $employee->isLinkedToHumanity() || !$employee->isLinkedToTcp()) {
            return;
        }

        $linker->link($employee);
    }
}
