<?php

namespace App\Services\OperationsEvents;

class ModelChangeSet
{
    /**
     * Build a changed_fields array from two arrays (old/new) for a specific allowlist.
     * Returns:
     * [
     *   'field' => ['from' => <old>, 'to' => <new>],
     * ]
     */
    public static function fromArrays(array $old, array $new, array $allowFields): array
    {
        $changes = [];

        foreach ($allowFields as $field) {
            $from = $old[$field] ?? null;
            $to = $new[$field] ?? null;

            if ($from !== $to) {
                $changes[$field] = [
                    'from' => $from,
                    'to' => $to,
                ];
            }
        }

        return $changes;
    }

    /**
     * NOTE:
     * Using wasChanged()+getOriginal() AFTER save can produce incorrect "from/to"
     * because Eloquent syncs originals after successful persistence.
     *
     * Keep this method only if you call it in the same request lifecycle
     * where the model still has its pre-save originals available.
     *
     * For event publishing, prefer snapshot compare:
     *  - $old = $model->replicate()->toArray()
     *  - persist changes
     *  - $new = $model->fresh()->toArray()
     *  - fromArrays($old, $new, ...)
     */
    public static function fields($model, array $allowFields): array
    {
        $changes = [];

        foreach ($allowFields as $field) {
            if ($model->wasChanged($field)) {
                $changes[$field] = [
                    'from' => $model->getOriginal($field),
                    'to' => $model->getAttribute($field),
                ];
            }
        }

        return $changes;
    }
}
