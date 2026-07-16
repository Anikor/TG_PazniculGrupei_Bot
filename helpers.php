<?php
declare(strict_types=1);

/**
 * helpers.php — shared utilities.
 *
 * These used to be copy-pasted into greeting.php, index.php and
 * edit_attendance.php (with drifting signatures: one copy returned ?int,
 * two returned ?string). Single definition, one behavior.
 */

/**
 * A tg_id belonging to the given group, students first. Used by greeting.php
 * to render another group's schedule through the tg_id-based queries.
 * Returned as string: tg_ids exceed 32-bit int range on 32-bit builds.
 */
function proxyTgIdForGroup(PDO $pdo, int $group_id): ?string
{
    try {
        $q = $pdo->prepare(
            "SELECT tg_id FROM users WHERE group_id = ?
              ORDER BY (role='student') DESC, id ASC LIMIT 1"
        );
        $q->execute([$group_id]);
        $tg = $q->fetchColumn();
        return $tg ? (string)$tg : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Short subject abbreviation for narrow screens: "Analiza matematică" → "AM".
 * Absorbed greeting.php's near-identical initials(): stopword lists merged,
 * plus its first-letter fallback for strings that are all stopwords.
 */
function subject_abbr(string $s): string
{
    $stop = ['si','și','şi','pe','de','la','cu','in','în','din','a','al','ale','un','o','lui','ai','of','and','the'];
    $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s));
    $o = '';
    foreach ($w as $x) {
        if ($x === '' || in_array($x, $stop, true)) continue;
        $o .= mb_strtoupper(mb_substr($x, 0, 1));
    }
    $o = mb_substr($o, 0, 6);
    return $o !== '' ? $o : mb_strtoupper(mb_substr(trim($s), 0, 1));
}

/**
 * Cache-busted asset URL: style.css → style.css?v=<mtime>. Telegram's webview
 * then re-downloads a file only when it actually changed, instead of relying
 * on hand-bumped ?v=1 / ?v=nav-global-5 strings that are easy to forget.
 */
function asset(string $file): string
{
    $p = __DIR__ . '/' . $file;
    return $file . '?v=' . (is_file($p) ? filemtime($p) : time());
}

/** "(sg 1)" tag for schedule headers when a lesson targets one subgroup. */
function subgroup_tag($subgroup): string
{
    return ($subgroup === null || $subgroup === '') ? '' : ' (sg ' . (int)$subgroup . ')';
}

/**
 * May a student be marked for this lesson? Blocks only a definite mismatch:
 * lesson pinned to subgroup A, student assigned to subgroup B. Lessons without
 * a subgroup apply to everyone; students without one can attend anything.
 */
function lesson_applies_to_student(array $lesson, array $student): bool
{
    $ls = $lesson['subgroup'] ?? null;
    $us = $student['subgroup'] ?? null;
    if ($ls === null || $ls === '' || $us === null || $us === '') {
        return true;
    }
    return (int)$ls === (int)$us;
}
