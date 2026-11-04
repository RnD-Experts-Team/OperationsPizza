<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\ActualShiftsDataset;
use App\Reports\Datasets\AvailabilityDataset;
use App\Reports\Datasets\ClockSegmentsDataset;
use App\Reports\Datasets\EmployeeSkillsDataset;
use App\Reports\Datasets\ScheduledShiftsDataset;
use App\Reports\Datasets\TimeOffDataset;

/**
 * The datasets this service offers the report builder. routes/api.php gives
 * each one a literal query route; each needs its own pizzasys auth rule.
 */
final class ReportDatasets
{
    /** @var list<class-string<ReportDataset>> */
    public const DATASETS = [
        ActualShiftsDataset::class,
        ScheduledShiftsDataset::class,
        TimeOffDataset::class,
        EmployeeSkillsDataset::class,
        ClockSegmentsDataset::class,
        AvailabilityDataset::class,
    ];

    public static function make(string $key): ReportDataset
    {
        foreach (self::DATASETS as $dataset) {
            if ($dataset::KEY === $key) {
                return new $dataset();
            }
        }

        abort(404, 'Unknown dataset.');
    }
}
