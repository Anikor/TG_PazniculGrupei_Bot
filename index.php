<?php
require_once __DIR__.'/config.php';require_once __DIR__.'/db.php';require_once __DIR__.'/helpers.php';require_once __DIR__.'/oe_weeks.php';require_once __DIR__.'/time_restrict.php';
require_once __DIR__.'/tg_auth.php';$me=tg_require_auth();tg_require_role($me,['admin','monitor','moderator']);
if($_SERVER['REQUEST_METHOD']==='POST'&&str_contains($_SERVER['CONTENT_TYPE']??'','application/json')){
    header('Content-Type:application/json; charset=UTF-8');
    $raw=file_get_contents('php://input');
    $data=json_decode($raw,true);
    if(!$data||!isset($data['attendance'])||!is_array($data['attendance'])){http_response_code(400);echo json_encode(['success'=>false,'error'=>'Invalid request']);exit;}
    tg_require_same_origin();
    $actorId=(int)$me['id'];$actorName=(string)$me['name'];
    $offset=(int)($_GET['offset']??0);
    $date=date('Y-m-d',strtotime(($offset>=0?'+':'').$offset.' days'));
    $effectiveGroupId=tg_resolve_group_id($me,$_GET['group_id']??0);
    $tz=new DateTimeZone(APP_TZ);
    [, , ,$weekType]=computeSemesterAndWeek(new DateTime($date,$tz));
    $schedule=getGroupScheduleForDate($effectiveGroupId,$date,$weekType);
    [$canEdit,$lockReason]=can_user_edit_for_date((string)($me['role']??''),new DateTimeImmutable($date,$tz),$tz,$schedule);
    if(!$canEdit){http_response_code(403);echo json_encode(['success'=>false,'error'=>$lockReason?:'Editing window closed.']);exit;}

    // Authorization: every submitted row must point at a lesson from THIS
    // group's schedule for THIS day and a student of THIS group. Previously
    // user_id/schedule_id were trusted from the client, letting any
    // monitor/moderator write attendance into any group.
    $lessonById=[];foreach($schedule as $s){$lessonById[(int)$s['id']]=$s;}
    $studentById=[];foreach(getGroupStudents($effectiveGroupId) as $stu){$studentById[(int)$stu['id']]=$stu;}
    $rows=[];
    foreach($data['attendance'] as $r){
        $sid=(int)($r['schedule_id']??0);$uid=(int)($r['user_id']??0);
        if(!isset($lessonById[$sid],$studentById[$uid])){http_response_code(403);echo json_encode(['success'=>false,'error'=>'Rejected: lesson or student is outside this group/day.']);exit;}
        if(!lesson_applies_to_student($lessonById[$sid],$studentById[$uid])){http_response_code(400);echo json_encode(['success'=>false,'error'=>'Rejected: subgroup mismatch for '.$studentById[$uid]['name'].'.']);exit;}
        $rows[]=[':uid'=>$uid,':sid'=>$sid,':dt'=>$date,':pres'=>!empty($r['present'])?1:0,':mot'=>!empty($r['motivated'])?1:0,':reason'=>trim((string)($r['motivation']??''))?:null,':mb'=>$actorId];
    }
    if(!$rows){http_response_code(400);echo json_encode(['success'=>false,'error'=>'Nothing to save.']);exit;}

    // This endpoint only creates first-time records; changes go through
    // edit_attendance.php (which writes the audit log). A resubmit used to
    // die on uq_attendance with an opaque 500 — answer 409 with a clear
    // message instead. The unique key still backstops the race.
    $sids=array_keys($lessonById);
    $in=implode(',',array_fill(0,count($sids),'?'));
    $chk=$pdo->prepare("SELECT user_id,schedule_id FROM attendance WHERE date=? AND schedule_id IN ($in)");
    $chk->execute(array_merge([$date],$sids));
    $already=[];while($e=$chk->fetch(PDO::FETCH_ASSOC)){$already[$e['schedule_id'].':'.$e['user_id']]=true;}
    foreach($rows as $r){if(isset($already[$r[':sid'].':'.$r[':uid']])){http_response_code(409);echo json_encode(['success'=>false,'error'=>'Attendance for this day is already recorded — use Edit Attendance.']);exit;}}

    $stmt=$pdo->prepare("INSERT INTO attendance (user_id, schedule_id, date, present, motivated, motivation, marked_by) VALUES (:uid,:sid,:dt,:pres,:mot,:reason,:mb)");
    try{
        $pdo->beginTransaction();
        foreach($rows as $r){$stmt->execute($r);}
        $pdo->commit();
        echo json_encode(['success'=>true,'marked_by_name'=>$actorName,'date'=>$date]);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException&&(string)$e->getCode()==='23000'){http_response_code(409);echo json_encode(['success'=>false,'error'=>'Attendance for this day is already recorded — use Edit Attendance.']);exit;}
        http_response_code(500);echo json_encode(['success'=>false,'error'=>'DB error']);
    }
    exit;
}
$offset=(int)($_GET['offset']??0);$date=date('Y-m-d',strtotime(($offset>=0?'+':'').$offset.' days'));$dayLabel=match(true){$offset===0=>'Today',$offset===-1=>'Yesterday',default=>($offset<0?abs($offset).' days ago':'+'.$offset.' days')};$prev1=$offset-1;$prev2=$offset-2;$next1=$offset+1;$user=$me;$actor=$me;$effective_group_id=tg_resolve_group_id($me,$_GET['group_id']??0);$tz=new DateTimeZone(APP_TZ);$dt=new DateTime($date,$tz);[, , ,$weekType]=computeSemesterAndWeek($dt);$schedule=getGroupScheduleForDate($effective_group_id,$date,$weekType);$students=getGroupStudents($effective_group_id);$stmG=$pdo->prepare("SELECT name FROM `groups` WHERE id=?");$stmG->execute([$effective_group_id]);$grp=$stmG->fetch(PDO::FETCH_ASSOC);$groupName=$grp['name']??('Group '.$effective_group_id);[$existing,$markers]=getAttendanceWithNames($date,array_column($schedule,'id'));[$canEdit,$lockReason]=can_user_edit_for_date($actor['role']??$user['role']??'',new DateTimeImmutable($date,$tz),$tz,$schedule);$editingLocked=!$canEdit;$theme=(($_COOKIE['theme']??'light')==='dark')?'dark':'light';$themeLabel=$theme==='dark'?'Dark':'Light';
?>
<!DOCTYPE html><html lang="en" class="<?= $theme==='dark'?'dark-theme':'' ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Log Attendance</title><link rel="stylesheet" href="<?= asset('style.css') ?>"><style>@media(max-width:610px){.subject-full{display:none}.subject-short{display:inline}}@media(min-width:611px){.subject-short{display:none}}</style><script src="<?= asset('script.js') ?>" defer></script></head><body data-day-label="<?= htmlspecialchars($dayLabel,ENT_QUOTES) ?>" data-date-dmy="<?= htmlspecialchars(date('d.m.Y',strtotime($date)),ENT_QUOTES) ?>" data-current-user-name="<?= htmlspecialchars(($actor['name']??$user['name']??'Unknown'),ENT_QUOTES) ?>" data-edit-locked="<?= $editingLocked?'1':'0' ?>" data-lock-reason="<?= htmlspecialchars($lockReason??'',ENT_QUOTES) ?>"><br><div id="theme-switch"><label class="switch"><input type="checkbox" id="theme-toggle" <?= $theme==='dark'?'checked':'' ?>><span class="slider"></span></label><span id="theme-label"><?= $themeLabel ?></span></div><br><br><div style="margin-top:6px;"><button class="btn-nav" onclick="nav(<?= $prev2 ?>)">« <?= abs($prev2) ?>d</button><button class="btn-nav" onclick="nav(<?= $prev1 ?>)">← <?= abs($prev1) ?>d</button><?php if($offset!==0):?><button class="btn-nav" onclick="nav(0)">Today</button><?php endif;?><?php if($offset<0):?><button class="btn-nav" onclick="nav(<?= $next1 ?>)">→ 1d</button><?php endif;?><button class="btn-nav" onclick="location.href='greeting.php?when=today'">Back to Schedule</button></div><h2>Group: <?= htmlspecialchars($groupName,ENT_QUOTES) ?></h2><p>This is an <span class="week-type <?= htmlspecialchars($weekType) ?>"><?= ucfirst($weekType) ?></span> week.</p><?php if($editingLocked):?><div class="edit-info" style="margin:10px 0;color:#c00"><?= htmlspecialchars($lockReason) ?></div><?php endif;?><?php if(empty($schedule)):?><p style="color:red;font-weight:bold;">No lessons for <?= $dayLabel ?> (<?= date('d.m.Y',strtotime($date)) ?>).</p><?php else:?><table><thead><tr><th></th><th>Student</th><?php foreach($schedule as $s):$full=(string)$s['subject'];$short=subject_abbr($full);?><th><?= htmlspecialchars($s['time_slot'].subgroup_tag($s['subgroup']??null),ENT_QUOTES) ?><br><span class="subject-full"><?= htmlspecialchars($full,ENT_QUOTES) ?></span><abbr class="subject-short no-underline" title="<?= htmlspecialchars($full,ENT_QUOTES) ?>"><?= htmlspecialchars($short,ENT_QUOTES) ?></abbr></th><?php endforeach;?></tr></thead><tbody><?php foreach($students as $i=>$stu):?><tr><td><?= $i+1 ?></td><td><?= htmlspecialchars($stu['name'],ENT_QUOTES) ?></td><?php foreach($schedule as $s):?><?php if(!lesson_applies_to_student($s,$stu)):?><td class="sg-na" title="Other subgroup">—</td><?php continue;endif;?><td><?php if(isset($existing[$s['id']][$stu['id']])):$a=$existing[$s['id']][$stu['id']];$by=$markers[$a['marked_by']]??("ID".$a['marked_by']);$updName=!empty($a['updated_by'])?($markers[$a['updated_by']]??("ID".$a['updated_by'])):null;?><label class="switch"><input type="checkbox" disabled <?= $a['present']?'checked':'' ?>><span class="slider"></span></label><div class="mot-reason"><?php if(!empty($a['motivated'])&&!empty($a['motivation'])):?>Reason: <?= htmlspecialchars($a['motivation'],ENT_QUOTES) ?><br><?php endif;?><em>By <?= htmlspecialchars($by,ENT_QUOTES) ?></em><?php if(!empty($a['updated_at'])):?><div class="mot-edited"><small>Last edited by <?= htmlspecialchars($updName??'',ENT_QUOTES) ?> at <?= htmlspecialchars($a['updated_at'],ENT_QUOTES) ?></small></div><?php endif;?></div><?php else:?><?php $disabled=$editingLocked?' disabled':'';?><label class="switch"><input type="checkbox" class="att-toggle" id="att_<?= $s['id'] ?>_<?= $stu['id'] ?>"<?= $disabled ?>><span class="slider"></span></label><div class="mot-container" id="mot_cont_<?= $s['id'] ?>_<?= $stu['id'] ?>"><label><input type="checkbox" class="mot-toggle" id="mot_<?= $s['id'] ?>_<?= $stu['id'] ?>"<?= $disabled ?>>Motivated</label><input type="text" id="mot_text_<?= $s['id'] ?>_<?= $stu['id'] ?>" class="motiv-text" placeholder="Reason…"<?= $disabled ?>></div><?php endif;?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><div id="save-confirm">✅ Attendance saved for <?= date('d.m.Y',strtotime($date)) ?>!</div><?php if(empty($existing)&&!$editingLocked):?><button class="btn-submit">Submit Attendance</button><?php elseif(!empty($existing)&&!$editingLocked):?><button class="btn-edit" onclick="location.href='edit_attendance.php?offset=<?= (int)$offset ?>&group_id=<?= (int)$effective_group_id ?>'">Edit Attendance</button><?php endif;?><?php endif;?></body></html>
