<?php
require_once __DIR__.'/config.php';require_once __DIR__.'/db.php';require_once __DIR__.'/helpers.php';
require_once __DIR__.'/tg_auth.php';$me=tg_require_auth();$target=$me;if(!empty($_GET['tg_id'])){$t=getUserByTgId(preg_replace('/\D/','',(string)$_GET['tg_id']));if($t)$target=$t;}if(!tg_can_view_user($me,$target))tg_deny('Not allowed to view this student');$user=$target;$user_id=(int)$target['id'];$tg_id=(string)$target['tg_id'];
$backUrl='greeting.php?tg_id='.rawurlencode($tg_id);
if(isset($_GET['return'],$_GET['group_id'])&&$_GET['return']==='group'){
  $backUrl='view_group_attendance.php?group_id='.(int)($_GET['group_id']??0);
}
$today=date('Y-m-d');$weekStart=date('Y-m-d',strtotime('monday this week'));$monthStart=date('Y-m-01');
function countClassDays($pdo,$uid,$from,$to){$q=$pdo->prepare("SELECT COUNT(DISTINCT a.date) FROM attendance a WHERE a.user_id=:uid AND a.date BETWEEN :from AND :to AND a.present=1");$q->execute([':uid'=>$uid,':from'=>$from,':to'=>$to]);return(int)$q->fetchColumn();}
function inclusiveDays($from,$to){$a=new DateTime($from);$b=new DateTime($to);return $a->diff($b)->days+1;}
function term_bounds(string $today):array{$dt=new DateTime($today);$y=(int)$dt->format('Y');$m=(int)$dt->format('n');if($m>=9||$m===1){if($m===1){$start=new DateTime(($y-1).'-09-01');$end=new DateTime($y.'-01-31');$label='Autumn '.($y-1).'/'.$y;}else{$start=new DateTime($y.'-09-01');$end=new DateTime(($y+1).'-01-31');$label='Autumn '.$y.'/'.($y+1);}}else{$start=new DateTime($y.'-02-01');$end=new DateTime($y.'-08-31');$label='Spring '.$y;}return[$start->format('Y-m-d'),$end->format('Y-m-d'),$label];}
[$termStart,$termEnd,$termLabel]=term_bounds($today);
$todayDT=new DateTime($today);$termEndDT=new DateTime($termEnd);
$toDateEnd=($todayDT<$termEndDT)?$todayDT->format('Y-m-d'):$termEndDT->format('Y-m-d');
// One aggregate for all four cards + the term check. This used to be
// fetchStats() x 4 periods x 4 queries, plus a fetchTotals() for the term —
// 17 round trips for the same 14 numbers. Every period ends "today", so one
// upper bound + conditional lower bounds reproduce each window exactly.
// Named placeholders can't repeat with real prepares, hence numbered names.
$agg=$pdo->prepare("SELECT COUNT(*) AS all_t, SUM(present=0) AS all_a, SUM(present=0 AND motivated=1) AS all_m,
 SUM(date=:d1) AS td_t, SUM(date=:d2 AND present=0) AS td_a, SUM(date=:d3 AND present=0 AND motivated=1) AS td_m,
 SUM(date>=:w1) AS wk_t, SUM(date>=:w2 AND present=0) AS wk_a, SUM(date>=:w3 AND present=0 AND motivated=1) AS wk_m,
 SUM(date>=:m1) AS mo_t, SUM(date>=:m2 AND present=0) AS mo_a, SUM(date>=:m3 AND present=0 AND motivated=1) AS mo_m,
 SUM(date BETWEEN :t1 AND :t2) AS term_t, SUM(date BETWEEN :t3 AND :t4 AND present=0) AS term_a
 FROM attendance WHERE user_id=:uid AND date<=:today");
$agg->execute([':uid'=>$user_id,':today'=>$today,':d1'=>$today,':d2'=>$today,':d3'=>$today,':w1'=>$weekStart,':w2'=>$weekStart,':w3'=>$weekStart,':m1'=>$monthStart,':m2'=>$monthStart,':m3'=>$monthStart,':t1'=>$termStart,':t2'=>$toDateEnd,':t3'=>$termStart,':t4'=>$toDateEnd]);
$g=$agg->fetch(PDO::FETCH_ASSOC)?:[];
// All absence rows once, newest first. Today/Week/Month are subsets of All
// Time, so the per-period tables filter in PHP instead of re-querying.
$lst=$pdo->prepare("SELECT a.date,s.time_slot,s.subject,s.type,a.motivation FROM attendance a JOIN schedule s ON s.id=a.schedule_id WHERE a.user_id=:uid AND a.date<=:today AND a.present=0 ORDER BY a.date DESC,s.time_slot");
$lst->execute([':uid'=>$user_id,':today'=>$today]);
$absRows=$lst->fetchAll(PDO::FETCH_ASSOC);
$rowsFor=function(string $from)use($absRows){return array_values(array_filter($absRows,fn($r)=>$r['date']>=$from));};
$mk=function($t,$a,$m,array $rows){$t=(int)$t;$a=(int)$a;$m=(int)$m;return['total'=>$t,'absent'=>$a,'motivated'=>$m,'unmotiv'=>$a-$m,'rows'=>$rows];};
$stats=['Today'=>$mk($g['td_t']??0,$g['td_a']??0,$g['td_m']??0,$rowsFor($today)),'This Week'=>$mk($g['wk_t']??0,$g['wk_a']??0,$g['wk_m']??0,$rowsFor($weekStart)),'This Month'=>$mk($g['mo_t']??0,$g['mo_a']??0,$g['mo_m']??0,$rowsFor($monthStart)),'All Time'=>$mk($g['all_t']??0,$g['all_a']??0,$g['all_m']??0,$absRows)];
$termStats=['total'=>(int)($g['term_t']??0),'absent'=>(int)($g['term_a']??0)];
$subjRows=$pdo->prepare("SELECT s.subject,s.type,COUNT(*) AS total,SUM(a.present=0) AS absent FROM attendance a JOIN schedule s ON s.id=a.schedule_id WHERE a.user_id=:uid GROUP BY s.subject,s.type");$subjRows->execute([':uid'=>$user_id]);$subjStats=[];while($r=$subjRows->fetch(PDO::FETCH_ASSOC)){$sub=$r['subject'];$typ=$r['type']??'';$subjStats[$sub]['labels'][$typ]=['total'=>(int)$r['total'],'absent'=>(int)$r['absent']];if(!isset($subjStats[$sub]['overall'])){$subjStats[$sub]['overall']=['total'=>0,'absent'=>0];}$subjStats[$sub]['overall']['total']+=(int)$r['total'];$subjStats[$sub]['overall']['absent']+=(int)$r['absent'];}
// Lab misses fall out of the per-subject aggregation for free (same join,
// same no-date-bound window the dedicated COUNT(*) query used to scan).
$labMissCount=0;foreach($subjStats as $d){$labMissCount+=(int)($d['labels']['lab']['absent']??0);}
$labFee=$labMissCount*LAB_FEE_LEI;
$prevMonthEndDT=(clone $todayDT)->modify('last day of previous month');$limitDT=($prevMonthEndDT<$termEndDT)?$prevMonthEndDT:$termEndDT;$streakMonths=0;$evaluatedMonth=false;$streakStartStr=null;$streakEndStr=null;
if($limitDT>=new DateTime($termStart)){
// Per-month totals in one GROUP BY instead of one fetchTotals() query per
// month of the term (~5 more round trips). The BETWEEN bounds clip the first
// and last month to the term window exactly like the old per-call windows.
$msQ=$pdo->prepare("SELECT DATE_FORMAT(date,'%Y-%m') AS ym, COUNT(*) AS t, SUM(present=0) AS a FROM attendance WHERE user_id=:uid AND date BETWEEN :from AND :to GROUP BY ym");
$msQ->execute([':uid'=>$user_id,':from'=>$termStart,':to'=>$limitDT->format('Y-m-d')]);
$byMonth=[];while($r=$msQ->fetch(PDO::FETCH_ASSOC)){$byMonth[$r['ym']]=['total'=>(int)$r['t'],'absent'=>(int)($r['a']??0)];}
$cur=new DateTime($termStart);$cur->modify('first day of this month');
while($cur<=$limitDT){$start=$cur->format('Y-m-01');if($start<$termStart)$start=$termStart;$endDT=(clone $cur)->modify('last day of this month');if($endDT>$limitDT)$endDT=$limitDT;$end=$endDT->format('Y-m-d');$monthStats=$byMonth[$cur->format('Y-m')]??['total'=>0,'absent'=>0];if($monthStats['total']>0){$evaluatedMonth=true;if($monthStats['absent']===0){if($streakMonths===0)$streakStartStr=$start;$streakEndStr=$end;$streakMonths++;}else{break;}}$cur->modify('first day of next month');}
}
$perfectTermToDate=($termStats['total']>0&&$termStats['absent']===0&&$todayDT>=$termEndDT);
$eggMsg1=["First month flawless—nice start! 🏁💯","One down, many to go. Perfect start! 🌟","First month with zero absences. Chef’s kiss. 👌"];
$eggMsgN=["Streak on fire: %d perfect months! 🔥","Keeping it clean for %d months—respect. 🙌","%d-month perfection streak unlocked. 🏆"];
$eggMsgTerm=["You just aced the entire term with 100% attendance. Legendary. 🏅","Perfect term achieved—model student mode: ON. 📚✨","Zero absences this term. That’s elite. 💪"];
$eggMessages=["Flawless month! 100% attendance unlocked. 🏆","No absences detected. The attendance gods are pleased. 😇","Perfect streak achieved—keep it rolling! 🔥","Achievement: „Never Miss a Beat”. 💯","Legendary consistency—respect! 🙌","You made the ‘Absent’ column feel lonely. 😅","Model student vibes detected. 📚✨","Attendance on point. Coffee well earned. ☕💪"];$eggMsgExtra=$eggMessages[array_rand($eggMessages)];
$showEgg=false;$eggTitle='';$eggBody='';if($perfectTermToDate){$showEgg=true;$eggTitle='🎉 Perfect Term: '.htmlspecialchars($termLabel,ENT_QUOTES).'!';$eggBody=$eggMsgTerm[array_rand($eggMsgTerm)];}elseif($streakMonths>=2){$showEgg=true;$eggTitle='🔥 Perfect Attendance Streak';$fmt=$eggMsgN[array_rand($eggMsgN)];$eggBody=sprintf($fmt,$streakMonths);}elseif($streakMonths===1&&$evaluatedMonth){$afterWeek=false;if($streakEndStr){$firstMonthEnd=new DateTime($streakEndStr);$afterWeek=($todayDT>=(clone $firstMonthEnd)->modify('+7 days'));}$showEgg=true;if($afterWeek){$eggTitle='✅ Perfect Attendance';$eggBody=$eggMsgExtra;}else{$eggTitle='✅ Perfect First Month';$eggBody=$eggMsg1[array_rand($eggMsg1)];}}
$termSpanStart=$termStart;$termSpanEnd=min($toDateEnd,$termEnd);$daysCalendar=inclusiveDays($termSpanStart,$termSpanEnd);$daysClass=countClassDays($pdo,$user_id,$termSpanStart,$termSpanEnd);
$theme=(($_COOKIE['theme']??'light')==='dark')?'dark':'light';
?>
<!doctype html><html class="<?= $theme==='dark'?'dark-theme':'' ?>" lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My Attendance</title><link rel="stylesheet" href="<?= asset('style.css') ?>"></head><body><br><div id="theme-switch"><label class="switch"><input id="theme-toggle" type="checkbox" <?= $theme==='dark'?'checked':'' ?>><span class="slider"></span></label><span id="theme-label"><?= $theme==='dark'?'Dark':'Light' ?></span></div><br><br><button class="btn-nav" onclick='location.href="<?= htmlspecialchars($backUrl,ENT_QUOTES) ?>"'>← Back</button><h1>My Attendance</h1><?php if($showEgg):?><div class="card card--perfect" style="margin:12px 0;"><h3><?= $eggTitle ?></h3><p><?= htmlspecialchars($eggBody,ENT_QUOTES) ?></p><p style="opacity:.8"><small>Term window: <?= htmlspecialchars($termStart,ENT_QUOTES) ?> — <?= htmlspecialchars($termSpanEnd,ENT_QUOTES) ?></small></p><p style="opacity:.9"><small>Days this term to date: <?= $daysCalendar ?> (class days: <?= $daysClass ?>)</small></p><?php if(!$perfectTermToDate&&$streakMonths>0):?><p style="opacity:.9"><small>Current streak: <?= $streakMonths ?> month<?= $streakMonths>1?'s':'' ?></small></p><?php endif;?></div><?php endif;?><div class="cards"><?php foreach($stats as $label=>$st):$rate=$st['total']?round(100*$st['absent']/$st['total'],1):0;?><div class="card"><h3><?= $label ?></h3><p><strong>Sessions:</strong> <?= $st['total'] ?></p><p><strong>Absent:</strong> <?= $st['absent'] ?></p><p style="margin-left:12px">– unmotivated: <?= $st['unmotiv'] ?></p><p style="margin-left:12px">– motivated: <?= $st['motivated'] ?></p><p><strong>Absence Rate:</strong> <?= $rate ?>%</p><?php if($label==='All Time'):?><hr><p><strong>Lab Misses:</strong> <?= $labMissCount ?></p><p><strong>Est. Fee:</strong> <?= $labFee ?> Lei</p><?php endif;?></div><?php endforeach;?></div><h2>By Subject Absence Rates</h2><table class="subj-table"><thead><tr><th>Subject</th><th>Curs Rate</th><th>Sem Rate</th><th>Lab Rate</th><th>Overall Rate</th></tr></thead><tbody><?php foreach($subjStats as $sub=>$data):$oTotal=$data['overall']['total'];$oAbsent=$data['overall']['absent'];$oRate=$oTotal?100*$oAbsent/$oTotal:0;?><tr><td><?= htmlspecialchars($sub,ENT_QUOTES) ?></td><?php foreach(['curs','sem','lab'] as $t):$dTotal=$data['labels'][$t]['total']??0;$dAbsent=$data['labels'][$t]['absent']??0;$dRate=$dTotal?(100*$dAbsent/$dTotal):0;?><td><?= $dAbsent ?>/<?= $dTotal ?> (<?= number_format($dRate,2) ?>%)</td><?php endforeach;?><td><?= $oAbsent ?>/<?= $oTotal ?> (<?= number_format($oRate,2) ?>%)</td></tr><?php endforeach;?></tbody></table><?php foreach($stats as $label=>$st):if(empty($st['rows']))continue;?><h2><?= $label ?> Absences</h2><table><thead><tr><th>Date</th><th>Time</th><th>Subject</th><th>Type</th><th>Reason</th></tr></thead><tbody><?php foreach($st['rows'] as $r):?><tr><td><?= htmlspecialchars($r['date'],ENT_QUOTES) ?></td><td><?= htmlspecialchars($r['time_slot'],ENT_QUOTES) ?></td><td><?= htmlspecialchars($r['subject'],ENT_QUOTES) ?></td><td><?= htmlspecialchars($r['type'],ENT_QUOTES) ?></td><td><?= $r['motivation']?htmlspecialchars($r['motivation'],ENT_QUOTES):'<em>none</em>' ?></td></tr><?php endforeach;?></tbody></table><?php endforeach;?><script src="<?= asset('script.js') ?>"></script></body></html>
