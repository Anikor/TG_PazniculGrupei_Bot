<?php

require_once __DIR__ . '/config.php';


function getUserByTgId($tg_id): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT id, tg_id, name, role, group_id, subgroup
           FROM users
          WHERE tg_id = ?"
    );
    $stmt->execute([(string)$tg_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}


function getGroupStudents(int $group_id): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT id, name, role, subgroup
           FROM users
          WHERE group_id = ?
          ORDER BY name"
    );
    $stmt->execute([$group_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Existing attendance for a day's lessons plus the names of whoever marked
 * or edited each row. Was copy-pasted between index.php and
 * edit_attendance.php. Returns [$existing[schedule_id][user_id] => row,
 * $names[user_id] => name].
 */
function getAttendanceWithNames(string $date, array $scheduleIds): array {
    global $pdo;
    $existing = []; $names = [];
    if (!$scheduleIds) return [$existing, $names];
    $in = implode(',', array_fill(0, count($scheduleIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT schedule_id,user_id,present,motivated,motivation,marked_by,updated_at,updated_by
           FROM attendance WHERE date=? AND schedule_id IN ($in)"
    );
    $stmt->execute(array_merge([$date], $scheduleIds));
    $ids = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[$r['schedule_id']][$r['user_id']] = $r;
        if (!empty($r['marked_by']))  $ids[] = (int)$r['marked_by'];
        if (!empty($r['updated_by'])) $ids[] = (int)$r['updated_by'];
    }
    if ($ids) {
        $ids = array_values(array_unique($ids));
        $in2 = implode(',', array_fill(0, count($ids), '?'));
        $q2 = $pdo->prepare("SELECT id,name FROM users WHERE id IN ($in2)");
        $q2->execute($ids);
        while ($u = $q2->fetch(PDO::FETCH_ASSOC)) $names[(int)$u['id']] = $u['name'];
    }
    return [$existing, $names];
}


/**
 * Day schedule for a GROUP, subgroup lessons included.
 *
 * index/edit used to reach this data through getScheduleForDate(), which
 * (a) needed a "proxy tg_id" hack to look at another group, and (b) with a
 * null $subgroup silently dropped every subgroup-specific lesson — the SQL
 * `s.subgroup = NULL` never matches. The logging pages must see ALL of the
 * day's lessons; per-student subgroup filtering happens at render/validation.
 */
function getGroupScheduleForDate(int $group_id, string $date, ?string $weekType = null): array {
    global $pdo;
    $dayName = date('l', strtotime($date));
    $stmt = $pdo->prepare(
        "SELECT s.id, s.group_id, s.day_of_week, s.time_slot, s.type,
                s.subject, s.location, s.week_type, s.subgroup
           FROM schedule s
          WHERE s.group_id    = :gid
            AND s.day_of_week = :dayName
            AND (s.week_type IS NULL OR s.week_type = :weekType)
          ORDER BY STR_TO_DATE(SUBSTRING_INDEX(s.time_slot,'-',1), '%H:%i'), s.subgroup"
    );
    $stmt->execute([':gid' => $group_id, ':dayName' => $dayName, ':weekType' => $weekType]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function getScheduleForDate($tg_id, string $date, ?string $weekType = null, ?int $subgroup = null): array {
    global $pdo;
    $dayName = date('l', strtotime($date)); 

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
        ':tg_id'     => (string)$tg_id,
        ':dayName'   => $dayName,
        ':weekType'  => $weekType,
        ':subgroup'  => $subgroup
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
