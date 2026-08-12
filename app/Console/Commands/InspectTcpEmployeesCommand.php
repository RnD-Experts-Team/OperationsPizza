<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Tcp\TcpClientInterface;
use Illuminate\Console\Command;

/**
 * Diagnostic for the employee identity chain.
 *
 * The whole integration hinges on one unverified assumption: that TCP's
 * `employeeId` (client-supplied) flows through TCP's own connector into
 * Humanity's `eid`, giving one identity end to end. This command shows the real
 * data on both sides so that can be confirmed rather than assumed.
 *
 * Read-only, and cheap: a couple of calls against a 2500/day quota.
 */
class InspectTcpEmployeesCommand extends Command
{
    protected $signature = 'tcp:inspect-employees
        {--limit=10 : How many TCP employees to show}
        {--raw : Dump one full TCP record, to see every available field}';

    protected $description = 'Compare TCP employee ids against our replicated links';

    public function handle(TcpClientInterface $tcp): int
    {
        $remote = $tcp->listEmployees();

        if ($remote === []) {
            $this->warn('TCP returned no employees. Check TCP_DRIVER and the credentials.');

            return self::SUCCESS;
        }

        $this->info(sprintf('TCP roster: %d employee(s).', count($remote)));

        if ($this->option('raw')) {
            $this->newLine();
            $this->line('<info>One full TCP record</info>');
            $this->line(json_encode($remote[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
        }

        $limit = (int) $this->option('limit');
        $rows = [];

        foreach (array_slice($remote, 0, $limit) as $employee) {
            $tcpId = (string) ($employee['employeeId'] ?? '');

            $local = $tcpId === '' ? null : Employee::query()->where('tcp_employee_id', $tcpId)->first();

            // Does our own id happen to equal TCP's? That is what the
            // client-supplied employeeId convention should produce for anyone
            // HiringPizza creates.
            $sameAsOurs = $tcpId !== '' && Employee::query()->whereKey($tcpId)->exists();

            $rows[] = [
                $tcpId,
                trim(($employee['firstName'] ?? '') . ' ' . ($employee['lastName'] ?? '')),
                $employee['exportCode'] ?? '—',
                $local === null ? 'NOT LINKED' : "#{$local->id}",
                $local?->humanity_employee_id ?? '—',
                $sameAsOurs ? 'yes' : 'no',
            ];
        }

        $this->table(
            ['tcp employeeId', 'name', 'exportCode', 'our employee', 'humanity id', 'tcpId == our id?'],
            $rows
        );

        $linked = Employee::query()->whereNotNull('tcp_employee_id')->count();
        $total = Employee::query()->count();

        $this->newLine();
        $this->line("Locally: {$linked}/{$total} employees carry a TCP link.");

        if ($linked < $total) {
            $this->warn('Employees without one cannot clock in, and their worked hours cannot be attributed.');
            $this->line("They need a '" . \App\Models\EmployeeExternalId::TCP . "' external id recorded in HiringPizza.");
        }

        $this->newLine();
        $this->line('<info>What to look for</info>');
        $this->line('  If `exportCode` holds the Humanity id, TCP matches on exportCode — leave `eid` alone.');
        $this->line('  If TCP `employeeId` equals the Humanity `eid`, the identity chain already lines up');
        $this->line('  and HiringPizza should set TCP employeeId = our employee id on create.');

        return self::SUCCESS;
    }
}
