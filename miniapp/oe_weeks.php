<?php
// miniapp/oe_weeks.php

/**
 * Determine whether the current week (since semester start) is odd or even.
 * @param string $semesterStartDate in 'Y-m-d' format
 * @return 'odd'|'even'
 */
function getCurrentWeekType(string $semesterStartDate = '2025-02-01'): string {
    $start    = new DateTime($semesterStartDate);
    $today    = new DateTime(); 
    $diffDays = (int)$start->diff($today)->format('%a');
    $weekNum  = floor($diffDays / 7) + 1;        // week 1 = odd
    return $weekNum % 2 === 1 ? 'odd' : 'even';
}