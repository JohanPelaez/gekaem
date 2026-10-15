<?php

declare(strict_types=1);

namespace App\Support;

class ProductionTimeConverter
{
    /**
     * Convert a value in a specified unit (minutes, hours, days) to total integer minutes.
     */
    public static function toMinutes(float $value, string $unit, int $dailyProductiveMinutes = 480): int
    {
        $daily = $dailyProductiveMinutes > 0 ? $dailyProductiveMinutes : 480;

        return match (strtolower(trim($unit))) {
            'hours', 'hour', 'h' => (int) round($value * 60),
            'days', 'day', 'd' => (int) round($value * $daily),
            default => (int) round($value),
        };
    }

    /**
     * Convert integer minutes back to a specific unit for display in forms.
     */
    public static function fromMinutes(int $minutes, string $unit, int $dailyProductiveMinutes = 480): float
    {
        $daily = $dailyProductiveMinutes > 0 ? $dailyProductiveMinutes : 480;

        return match (strtolower(trim($unit))) {
            'hours', 'hour', 'h' => round($minutes / 60, 2),
            'days', 'day', 'd' => round($minutes / $daily, 2),
            default => (float) $minutes,
        };
    }

    /**
     * Format minutes into a friendly human-readable string for entrepreneurs (e.g. "2 días 3h", "4h 30m", "45 min").
     */
    public static function formatHuman(int $minutes, int $dailyProductiveMinutes = 480): string
    {
        if ($minutes <= 0) {
            return '0 min';
        }

        $daily = $dailyProductiveMinutes > 0 ? $dailyProductiveMinutes : 480;

        if ($minutes >= $daily) {
            $days = intdiv($minutes, $daily);
            $remainder = $minutes % $daily;
            $hours = intdiv($remainder, 60);
            $mins = $remainder % 60;

            $dayLabel = $days === 1 ? '1 día' : "{$days} días";

            if ($hours > 0 && $mins > 0) {
                return "{$dayLabel} {$hours}h {$mins}m";
            }
            if ($hours > 0) {
                return "{$dayLabel} {$hours}h";
            }
            if ($mins > 0) {
                return "{$dayLabel} {$mins} min";
            }

            return $dayLabel;
        }

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $mins = $minutes % 60;

            if ($mins > 0) {
                return "{$hours}h {$mins}m";
            }

            return "{$hours}h";
        }

        return "{$minutes} min";
    }
}
