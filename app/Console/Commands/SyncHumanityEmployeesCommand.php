<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Humanity\HumanityClientInterface;
use Illuminate\Console\Command;

/**
 * Fills employees.humanity_employee_id by READING Humanity — never writing it.
 *
 * TCP's connector owns Humanity's employee records; what it carries across is
 * the TCP employee id, observable on the Humanity side as the employee's
 * `eid` and as the username prefix ("5896.lce03795" = TCP id 5896 + company
 * code). So the Humanity id an assignment needs is discoverable by matching
 * those against our replicated employees.tcp_employee_id.
 *
 * This replaces the old write loop where HiringPizza pushed employees into
 * Humanity directly — under the connector that path would be a second writer
 * on the same records.
 */
class SyncHumanityEmployeesCommand extends Command
{
    protected $signature = 'humanity:sync-employees
        {--dry-run : Report matches without writing anything}';

    protected $description = 'Link local employees to Humanity records by TCP id (read-only against Humanity)';

    public function handle(HumanityClientInterface $humanity): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $remote = $humanity->listEmployees(includeInactive: true);
        $this->info(sprintf('Fetched %d Humanity employee(s).', count($remote)));

        // tcp_employee_id -> local employee, for O(1) matching.
        $byTcpId = Employee::query()
            ->whereNotNull('tcp_employee_id')
            ->get()
            ->keyBy(fn (Employee $e) => (string) $e->tcp_employee_id);

        $linked = 0;
        $already = 0;
        $unmatchedRemote = [];

        foreach ($remote as $record) {
            $humanityId = $this->stringOrNull($record['id'] ?? null);

            if ($humanityId === null) {
                continue;
            }

            $employee = $this->matchLocal($record, $byTcpId);

            if ($employee === null) {
                $unmatchedRemote[] = sprintf(
                    '%s — %s (eid: %s, username: %s)',
                    $humanityId,
                    trim(($record['firstname'] ?? '') . ' ' . ($record['lastname'] ?? '')) ?: 'unnamed',
                    $this->stringOrNull($record['eid'] ?? null) ?? '—',
                    $this->stringOrNull($record['username'] ?? null) ?? '—',
                );

                continue;
            }

            if ((string) $employee->humanity_employee_id === $humanityId) {
                $already++;

                continue;
            }

            $linked++;

            if ($dryRun) {
                $this->line("  Would link employee {$employee->id} (TCP {$employee->tcp_employee_id}) → Humanity {$humanityId}");

                continue;
            }

            $employee->forceFill([
                'humanity_employee_id' => $humanityId,
                'humanity_synced_at' => now(),
            ])->save();
        }

        $stillUnlinked = Employee::query()
            ->whereNotNull('tcp_employee_id')
            ->whereNull('humanity_employee_id')
            ->count();

        $this->table(['metric', 'count'], [
            ['already linked', $already],
            [$dryRun ? 'would link' : 'newly linked', $linked],
            ['Humanity records matching nobody', count($unmatchedRemote)],
            ['local employees with TCP id but no Humanity id' . ($dryRun ? ' (before)' : ''), $stillUnlinked],
        ]);

        foreach (array_slice($unmatchedRemote, 0, 20) as $line) {
            $this->line("  Unmatched in Humanity: {$line}");
        }

        if (count($unmatchedRemote) > 20) {
            $this->line('  … ' . (count($unmatchedRemote) - 20) . ' more');
        }

        if ($dryRun) {
            $this->info('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * eid first (the connector's standard external-id slot), then the
     * username's "{tcpId}.{companyCode}" prefix — both observed on this
     * account; either alone is enough.
     *
     * @param  \Illuminate\Support\Collection<string, Employee>  $byTcpId
     */
    private function matchLocal(array $record, $byTcpId): ?Employee
    {
        $eid = $this->stringOrNull($record['eid'] ?? null);

        if ($eid !== null && $byTcpId->has($eid)) {
            return $byTcpId->get($eid);
        }

        $username = $this->stringOrNull($record['username'] ?? null);

        if ($username !== null && str_contains($username, '.')) {
            $prefix = strstr($username, '.', true);

            if ($prefix !== false && $byTcpId->has($prefix)) {
                return $byTcpId->get($prefix);
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
