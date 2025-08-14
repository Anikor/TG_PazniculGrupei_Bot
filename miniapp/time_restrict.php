<?php
// miniapp/time_restrict.php
declare(strict_types=1);

/**
 * Parse the *latest end time* from a day's $schedule and return cutoff = lastEnd + 20 minutes.
 * Expected formats in $schedule rows:
 *   - 'time_slot' like "08:00-08:45" (also tolerates en/em dashes)
 *   - (Optional) 'end_time' as "HH:MM"
 *
 * @return DateTimeImmutable|null  The computed cutoff in local TZ, or null if nothing parseable.
 */
function moderator_cutoff_from_schedule(array $schedule, DateTimeInterface $targetDate, ?DateTimeZone $tz = null): ?DateTimeImmutable {
  $tz       = $tz ?: new DateTimeZone('Europe/Chisinau');
  $baseYmd  = $targetDate->format('Y-m-d');
  $maxEndTs = null;

  foreach ($schedule as $row) {
    // Prefer explicit end_time if present
    if (!empty($row['end_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$row['end_time'], $m)) {
      $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $baseYmd.' '.$m[1].':'.$m[2], $tz);
      if ($dt && (!$maxEndTs || $dt > $maxEndTs)) $maxEndTs = $dt;
      continue;
    }

    // Fallback: parse "HH:MM-HH:MM" from time_slot
    $slot = (string)($row['time_slot'] ?? '');
    if ($slot !== '') {
      $slot = str_replace(['–','—','—'], '-', $slot); // normalize dashes
      if (preg_match('/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $slot, $m)) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $baseYmd.' '.$m[3].':'.$m[4], $tz);
        if ($dt && (!$maxEndTs || $dt > $maxEndTs)) $maxEndTs = $dt;
      }
    }
  }

  if (!$maxEndTs) return null; // nothing parseable
  return $maxEndTs->modify('+20 minutes');
}

/**
 * Central edit/logging policy:
 *  - admin:     no restriction
 *  - moderator: ONLY for today, allowed until (today's last lesson end + 20min)
 *               (if schedule unparsable -> fallback cutoff 18:00 local)
 *  - monitor:   today and previous 2 calendar days (rolling 3-day window)
 *
 * @param string              $role
 * @param DateTimeInterface   $targetDate   The date being edited/logged (server determines this from ?offset)
 * @param DateTimeZone|null   $tz           Defaults to Europe/Chisinau
 * @param array<int,array>    $schedule     (Optional) Day’s schedule rows for dynamic moderator cutoff
 *
 * @return array{0: bool, 1: string} [canEdit, reasonIfDenied]
 */
function can_user_edit_for_date(string $role, DateTimeInterface $targetDate, ?DateTimeZone $tz = null, array $schedule = []): array {
  $tz  = $tz ?: new DateTimeZone('Europe/Chisinau');
  $now = new DateTimeImmutable('now', $tz);
  $role = strtolower(trim($role ?? ''));

  // Admins: always allowed
  if ($role === 'admin') return [true, ''];

  // Moderators: same calendar day as *now*, until last lesson end + 20min
  if ($role === 'moderator') {
    $sameDay = $targetDate->format('Y-m-d') === $now->format('Y-m-d');
    if (!$sameDay) return [false, 'Moderators can log/edit only for today.'];

    $cutoff = moderator_cutoff_from_schedule($schedule, $targetDate, $tz);
    if (!$cutoff) {
      // Conservative fallback: 18:00 local
      $cutoff = DateTimeImmutable::createFromFormat('Y-m-d H:i', $now->format('Y-m-d').' 18:00', $tz);
    }
    $ok = $now <= $cutoff;
    return [$ok, $ok ? '' : 'The moderator window is closed (after last lesson + 20 min).'];
  }

  // Monitors: today and previous 2 days (today-2 … today)
  if ($role === 'monitor') {
    $today   = new DateTimeImmutable($now->format('Y-m-d'), $tz);
    $minDate = $today->modify('-2 days');
    $ok = ($targetDate >= $minDate) && ($targetDate <= $today);
    return [$ok, $ok ? '' : 'Monitors can edit only today and the previous 2 days.'];
  }

  return [false, 'Editing not permitted for your role.'];
}
