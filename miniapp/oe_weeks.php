<?php
/**
 * Given a DateTime $date, determine:
 *  - which semester it belongs to (1 or 2)
 *  - the canonical semester start date
 *  - the study‐week number since that start
 *  - whether that week is odd or even
 *
 * @param DateTime $date
 * @return array [ int $semester, DateTime $semesterStart, int $weekNumber, string $weekType ]
 */
function computeSemesterAndWeek(DateTime $date): array {
    $year  = (int)$date->format('Y');
    $month = (int)$date->format('n');

    // Determine semester and its nominal start
    if ($month >= 9) {
        // Semester 1: Sept 1 of this year
        $semester     = 1;
        $semesterYear = $year;
        $start        = new DateTime("$semesterYear-09-01");
    } elseif ($month < 2) {
        // Still in Semester 1 of the *previous* academic year (Jan)
        $semester     = 1;
        $semesterYear = $year - 1;
        $start        = new DateTime("$semesterYear-09-01");
    } else {
        // Months 2 .. 8 → Semester 2: Feb 1 of this year
        $semester     = 2;
        $start        = new DateTime("$year-02-01");
    }

    // If the start falls on Sat(6) or Sun(7), roll it forward to the next Monday
    $dow = (int)$start->format('N');
    if ($dow >= 6) {
        $start->modify('next monday');
    }

    // Compute full weeks elapsed (0-based) and then 1-based week number
    $daysDiff   = $date->diff($start)->days;
    $weekNumber = (int) floor($daysDiff / 7) + 1;

    // First study week is always odd, so simple parity
    $weekType = ($weekNumber % 2 === 1) ? 'odd' : 'even';

    return [$semester, $start, $weekNumber, $weekType];
}

// ——— Usage example ———
// Suppose $today = new DateTime(); in Chisinau timezone
$today = new DateTime('now', new DateTimeZone('Europe/Chisinau'));
list($sem, $semStart, $weekNum, $weekParity) = computeSemesterAndWeek($today);

// You can now store/use $sem (1 or 2), $semStart, $weekNum, $weekParity internally for stats
// For example:
error_log("Semester: $sem; starts on " . $semStart->format('Y-m-d') .
          "; week #$weekNum ($weekParity)");

          /**
 * Legacy alias so your greeting.php can continue
 * to call getCurrentWeekType() without changing it.
 */
function getCurrentWeekType(): string {
    // you may adjust the timezone if needed
    $today = new DateTime('now', new DateTimeZone('Europe/Chisinau'));
    [, , , $weekType] = computeSemesterAndWeek($today);
    return $weekType;
}