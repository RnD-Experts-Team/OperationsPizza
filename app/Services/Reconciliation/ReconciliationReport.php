<?php

namespace App\Services\Reconciliation;

class ReconciliationReport
{
    public int $remoteSeen = 0;
    public int $unchanged = 0;
    public int $imported = 0;
    public int $updated = 0;
    public int $deleted = 0;
    public int $skipped = 0;
    public array $errors = [];

    /**
     * Per-shift change detail — what a dry run exists to show. Each entry:
     * {action: imported|updated|deleted, humanity_shift_id, shift_id?,
     *  date?, diff?}. The diff is the same field-level local-vs-remote map
     * recorded to humanity_sync_log on a real run.
     */
    public array $changes = [];

    public function toArray(): array
    {
        return [
            'remote_seen' => $this->remoteSeen,
            'unchanged' => $this->unchanged,
            'imported' => $this->imported,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }

    public function changed(): bool
    {
        return $this->imported > 0 || $this->updated > 0 || $this->deleted > 0;
    }
}
