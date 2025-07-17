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
        "SELECT id, tg_id, name, role, group_id
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
 * Get schedule rows for a specific date
 *
 * @param  int    $tg_id
 * @param  string $date    'YYYY-MM-DD'
 * @return array
 */
function getScheduleForDate(int $tg_id, string $date): array {
    global $pdo;
    $dayName = date('l', strtotime($date)); // e.g. "Tuesday"

    $stmt = $pdo->prepare(
        "SELECT 
            s.day_of_week,
            s.id,
            s.group_id,
            s.time_slot,
            s.type,
            s.subject,
            s.location
         FROM schedule s
         JOIN users u ON u.group_id = s.group_id
         WHERE u.tg_id = ? 
           AND s.day_of_week = ?
         ORDER BY s.time_slot"
    );
    $stmt->execute([$tg_id, $dayName]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get this week's schedule (Monday–Friday)
 *
 * @param  int  $tg_id
 * @return array
 */
function getWeekSchedule(int $tg_id): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT 
            s.day_of_week,
            s.id,
            s.group_id,
            s.time_slot,
            s.type,
            s.subject,
            s.location
         FROM schedule s
         JOIN users u ON u.group_id = s.group_id
         WHERE u.tg_id = ?
           AND s.day_of_week IN ('Monday','Tuesday','Wednesday','Thursday','Friday')
         ORDER BY 
           FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday'),
           s.time_slot"
    );
    $stmt->execute([$tg_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
