<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Tcp\TcpClientInterface;
use App\Services\Tcp\TcpRateLimiter;
use Illuminate\Console\Command;

/**
 * Dumps TCP's whole employee roster to a CSV, with our local links alongside.
 *
 * WHY A COMMAND AND NOT A SCRIPT
 * ------------------------------
 * TCP's API is not something you can curl casually: every call needs an OAuth
 * client_credentials token, an API key header and a company id, and each one
 * comes out of a 2500-requests-per-24h budget shared by the WHOLE service. Going
 * through TcpClientInterface means the token manager, the rate limiter and the
 * daily-quota accounting all apply, so this export shows up in `tcp:quota` like
 * everything else instead of quietly eating the budget.
 *
 * COST
 * ----
 * listEmployees() pages at 50 records per request, so the whole roster costs
 * ceil(count / 50) + 1 calls -- about 11 for 500 employees. Cheap, but not
 * free, and it is a full scan every time. Do not put this on a schedule.
 *
 * READ-ONLY
 * ---------
 * GETs only. It runs fine with TCP_WRITES_ENABLED=false and never touches
 * Humanity. It also writes nothing to our database -- the local columns are
 * read out of the employee replica purely to sit next to the TCP ones.
 *
 * COLUMNS
 * -------
 * TCP's employee record has more fields than we model, and which ones are
 * populated depends on account configuration. Rather than hardcode a guess,
 * the header is the union of every top-level scalar key present across the
 * roster, with the identity fields pinned to the front. Nested values are
 * written as JSON so nothing is silently dropped.
 */
class ExportTcpEmployeesCommand extends Command
{
    protected $signature = 'tcp:export-employees
        {--path= : Output file. Defaults to storage/app/tcp-employees-{timestamp}.csv}
        {--columns= : Comma-separated TCP fields to keep, instead of every discovered one}
        {--no-local : Skip the our-replica columns and export TCP data only}
        {--delimiter=, : Field delimiter. Use ";" for Excel on a comma-decimal locale}';

    protected $description = 'Export every TCP employee and their ids to a CSV';

    /**
     * Shown first when present, because these are the ones anybody opening the
     * file is actually looking for. `exportCode` matters most: it holds OUR
     * employee id, and it is the idempotency link that stops a second push
     * creating a duplicate person in TCP.
     */
    private const PINNED = ['employeeId', 'exportCode', 'firstName', 'middleName', 'lastName'];

    public function handle(TcpClientInterface $tcp, TcpRateLimiter $limiter): int
    {
        if (config('tcp.driver') !== 'http') {
            $this->warn('TCP_DRIVER is "' . config('tcp.driver') . '", not "http".');
            $this->warn('You will export the in-memory fake roster, not the real TCP account.');

            if (!$this->confirm('Continue anyway?', false)) {
                return self::SUCCESS;
            }
        }

        $this->line(sprintf('TCP quota: %d request(s) left today.', $limiter->remainingToday(interactive: true)));

        $this->line('Fetching the TCP roster (50 records per request)...');

        $remote = $tcp->listEmployees();

        if ($remote === []) {
            $this->warn('TCP returned no employees. Check the credentials and TCP_COMPANY_ID.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d employee(s) returned.', count($remote)));

        $columns = $this->resolveColumns($remote);
        $withLocal = !$this->option('no-local');
        $locals = $withLocal ? $this->localLinks($remote) : [];

        $header = $columns;

        if ($withLocal) {
            // Prefixed so they can never collide with a TCP field name.
            $header = array_merge($header, [
                'local_employee_id',
                'local_name',
                'local_humanity_employee_id',
                'local_link_status',
            ]);
        }

        $path = $this->resolvePath();
        $handle = @fopen($path, 'w');

        if ($handle === false) {
            $this->error("Could not open {$path} for writing.");

            return self::FAILURE;
        }

        // Excel will not detect UTF-8 in a CSV without this, and mangles any
        // accented name. Harmless everywhere else.
        fwrite($handle, "\xEF\xBB\xBF");

        $delimiter = $this->resolveDelimiter();

        fputcsv($handle, $header, $delimiter);

        $unlinked = 0;

        foreach ($remote as $employee) {
            $row = [];

            foreach ($columns as $column) {
                $row[] = $this->scalar($employee[$column] ?? null);
            }

            if ($withLocal) {
                $tcpId = (string) ($employee['employeeId'] ?? '');
                $local = $locals[$tcpId] ?? null;

                if ($local === null) {
                    $unlinked++;
                }

                $row[] = $local?->id ?? '';
                $row[] = $local === null ? '' : trim($local->first_name . ' ' . $local->last_name);
                $row[] = $local?->humanity_employee_id ?? '';
                $row[] = $local === null ? 'NOT IN OPERATIONS' : 'linked';
            }

            fputcsv($handle, $row, $delimiter);
        }

        fclose($handle);

        $this->newLine();
        $this->info('Wrote ' . $path);
        $this->line(sprintf('%d row(s), %d column(s).', count($remote), count($header)));

        if ($withLocal && $unlinked > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d TCP employee(s) have no matching row in OperationsPizza.',
                $unlinked
            ));
            $this->line('That is expected for anyone TCP knows about who was never hired through');
            $this->line('HiringPizza. Look at local_link_status to pick them out.');
        }

        $this->newLine();
        $this->line(sprintf('TCP quota now: %d request(s) left today.', $limiter->remainingToday(interactive: true)));

        return self::SUCCESS;
    }

    /**
     * Every top-level scalar key seen anywhere in the roster, identity fields
     * first. Scanning all records rather than just the first one matters --
     * TCP omits empty fields per-record, so the first employee is not a
     * reliable description of the shape.
     *
     * @param  array<int, array>  $remote
     * @return array<int, string>
     */
    private function resolveColumns(array $remote): array
    {
        $requested = $this->option('columns');

        if (is_string($requested) && trim($requested) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $requested))));
        }

        $keys = [];

        foreach ($remote as $employee) {
            if (!is_array($employee)) {
                continue;
            }

            foreach (array_keys($employee) as $key) {
                $keys[(string) $key] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        $pinned = array_values(array_filter(self::PINNED, fn ($k) => in_array($k, $keys, true)));
        $rest = array_values(array_filter($keys, fn ($k) => !in_array($k, $pinned, true)));

        return array_merge($pinned, $rest);
    }

    /**
     * Our replica rows for the TCP ids on this roster, keyed by TCP id.
     *
     * One query rather than a lookup per employee: the roster can be thousands
     * of records, and this join is the only reason the export is worth more
     * than a raw API dump.
     *
     * @param  array<int, array>  $remote
     * @return array<string, Employee>
     */
    private function localLinks(array $remote): array
    {
        $ids = [];

        foreach ($remote as $employee) {
            $tcpId = (string) ($employee['employeeId'] ?? '');

            if ($tcpId !== '') {
                $ids[] = $tcpId;
            }
        }

        if ($ids === []) {
            return [];
        }

        return Employee::query()
            ->withTrashed()
            ->whereIn('tcp_employee_id', array_unique($ids))
            ->get()
            ->keyBy(fn (Employee $e) => (string) $e->tcp_employee_id)
            ->all();
    }

    private function resolvePath(): string
    {
        $path = $this->option('path');

        if (is_string($path) && trim($path) !== '') {
            return $path;
        }

        return storage_path('app/tcp-employees-' . now()->format('Y-m-d-His') . '.csv');
    }

    private function resolveDelimiter(): string
    {
        $delimiter = (string) $this->option('delimiter');

        // fputcsv takes exactly one character; anything else is a silent
        // truncation waiting to produce an unreadable file.
        return $delimiter === '' ? ',' : substr($delimiter, 0, 1);
    }

    /**
     * TCP nests some fields (addresses, custom field collections). Writing them
     * as JSON keeps the column count fixed and loses nothing, which is better
     * for a diagnostic export than flattening into a variable set of columns.
     */
    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
