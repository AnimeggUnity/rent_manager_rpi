<?php
function buildMonthlyDates(string $startDate, int $months): array {
    $months = max(1, $months);
    $start = new DateTime($startDate);
    $day = (int)$start->format('d');
    $dates = [];

    for ($i = 0; $i < $months; $i++) {
        $current = (clone $start)->modify("+{$i} month");
        $lastDay = (int)$current->format('t');
        $useDay = min($day, $lastDay);
        $current->setDate(
            (int)$current->format('Y'),
            (int)$current->format('m'),
            $useDay
        );
        $dates[] = $current->format('Y-m-d');
    }

    return $dates;
}
