<?php
// db.php
require_once __DIR__ . '/config.php';

/**
 * Find user record by Telegram user ID
 *
 * @param  int       $tg_id
 * @return array|null
 */
function getUserByTgId(int $tg_id): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT id, tg_id, name, role, group_id, subgroup
           FROM users
          WHERE tg_id = ?"
    );
    $stmt->execute([$tg_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get schedule rows for today, based on user's group
 *
 * @param  int   $tg_id
 * @return array
 */
function getTodaySchedule(int $tg_id): array {
    global $pdo;
    $day  = date('l'); // e.g. "Monday"
    $user = getUserByTgId($tg_id);
    if (!$user) return [];

    $stmt = $pdo->prepare(
        "SELECT 
            s.id,
            s.group_id,
            s.day_of_week,
            s.time_slot,
            s.type,
            s.subject,
            s.location
         FROM schedule s
         JOIN users u ON u.group_id = s.group_id
         WHERE u.tg_id = ? AND s.day_of_week = ?
         ORDER BY s.time_slot"
    );
    $stmt->execute([$tg_id, $day]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get list of users in the same group
 *
 * @param  int   $group_id
 * @return array
 */
function getGroupStudents(int $group_id): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT id, name, role
           FROM users
          WHERE group_id = ?
          ORDER BY name"
    );
    $stmt->execute([$group_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Record attendance: who marked (by tg_id), which student, schedule slot, and present flag
 *
 * @param  int   $marker_tg
 * @param  int   $user_id
 * @param  int   $schedule_id
 * @param  bool  $present
 * @return bool
 */
function markAttendance(int $marker_tg, int $user_id, int $schedule_id, bool $present): bool {
    global $pdo;
    $marker = getUserByTgId($marker_tg);
    if (!$marker) return false;
    $marked_by = $marker['id'];

    $stmt = $pdo->prepare(
      "INSERT INTO attendance (user_id, schedule_id, date, present, marked_by)
       VALUES (?, ?, CURDATE(), ?, ?)"
    );
    return $stmt->execute([
      $user_id,
      $schedule_id,
      $present ? 1 : 0,
      $marked_by
    ]);
}

/**
 * Get simple attendance stats for a user
 *
 * @param  int   $tg_id
 * @return array|null
 */
function getUserStats(int $tg_id): ?array {
    global $pdo;
    $user = getUserByTgId($tg_id);
    if (!$user) return null;
    $uid = $user['id'];

    // total attendance records
    $stmt = $pdo->prepare(
      "SELECT COUNT(*) FROM attendance WHERE user_id = ?"
    );
    $stmt->execute([$uid]);
    $total = (int)$stmt->fetchColumn();

    // present count
    $stmt = $pdo->prepare(
      "SELECT COUNT(*) FROM attendance WHERE user_id = ? AND present = TRUE"
    );
    $stmt->execute([$uid]);
    $present = (int)$stmt->fetchColumn();

    return [
      'name'          => $user['name'],
      'total_classes' => $total,
      'present_count' => $present
    ];
}

/**
 * Get schedule rows for a specific date, filtering odd/even weeks and subgroup.
 *
 * @param  int         $tg_id      Telegram user ID
 * @param  string      $date       Date in 'YYYY-MM-DD' format
 * @param  string|null $weekType   'odd' or 'even' (NULL = every week)
 * @param  int|null    $subgroup   1 or 2 (NULL = all students)
 * @return array                   Array of schedule rows
 */
function getScheduleForDate(int $tg_id, string $date, ?string $weekType = null, ?int $subgroup = null): array {
    global $pdo;
    $dayName = date('l', strtotime($date)); // e.g. "Tuesday"

    $sql = "
        SELECT
            s.day_of_week,
            s.id,
            s.group_id,
            s.time_slot,
            s.type,
            s.subject,
            s.location,
            s.week_type, 
            s.subgroup 
        FROM schedule s
        JOIN users u ON u.group_id = s.group_id
        WHERE u.tg_id        = :tg_id
          AND s.day_of_week  = :dayName
          AND (s.week_type IS NULL OR s.week_type = :weekType)
          AND (s.subgroup  IS NULL OR s.subgroup  = :subgroup)
        ORDER BY STR_TO_DATE(SUBSTRING_INDEX(s.time_slot,'-',1), '%H:%i')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tg_id'     => $tg_id,
        ':dayName'   => $dayName,
        ':weekType'  => $weekType,
        ':subgroup'  => $subgroup
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get this week's schedule (Monday–Friday), filtering odd/even weeks and subgroup.
 *
 * @param  int         $tg_id      Telegram user ID
 * @param  string|null $weekType   'odd' or 'even' (NULL = every week)
 * @param  int|null    $subgroup   1 or 2 (NULL = all students)
 * @return array                   Array of schedule rows
 */
function getWeekSchedule(int $tg_id, ?string $weekType = null, ?int $subgroup = null): array {
    global $pdo;

    $sql = "
        SELECT
            s.day_of_week,
            s.id,
            s.group_id,
            s.time_slot,
            s.type,
            s.subject,
            s.location
        FROM schedule s
        JOIN users u ON u.group_id = s.group_id
        WHERE u.tg_id        = :tg_id
          AND s.day_of_week IN ('Monday','Tuesday','Wednesday','Thursday','Friday')
          AND (s.week_type IS NULL OR s.week_type = :weekType)
          AND (s.subgroup  IS NULL OR s.subgroup  = :subgroup)
        ORDER BY
            FIELD(s.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday'),
            STR_TO_DATE(SUBSTRING_INDEX(s.time_slot,'-',1), '%H:%i')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tg_id'     => $tg_id,
        ':weekType'  => $weekType,
        ':subgroup'  => $subgroup
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
