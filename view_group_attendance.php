<?php
require_once __DIR__.'/config.php';require_once __DIR__.'/db.php';require_once __DIR__.'/helpers.php';
require_once __DIR__.'/tg_auth.php';$me=tg_require_auth();tg_require_role($me,['admin','monitor']);$tg_id=(string)$me['tg_id'];$group_id=tg_resolve_group_id($me,$_GET['group_id']??0);
$today=date('Y-m-d');$weekStart=date('Y-m-d',strtotime('monday this week'));$monthStart=date('Y-m-01');
$periods=['Today'=>[$today,$today],'This Week'=>[$weekStart,$today],'This Month'=>[$monthStart,$today],'All Time'=>['1970-01-01',$today],];
$stuQ=$pdo->prepare("SELECT id, name, tg_id FROM users WHERE group_id = ? ORDER BY name");$stuQ->execute([$group_id]);$students=$stuQ->fetchAll(PDO::FETCH_ASSOC);
// One query for every student x every period instead of one query pair per
// (student, period) -- was up to students x 4 periods x 2 queries (272 for a
// 34-student group), now 1. Every period here ends "today", so a single
// upper bound in WHERE plus per-period lower bounds in the SUM()s reproduce
// the old fetchStats()'s "date >= from [AND date <= to]" exactly.
// Named placeholders can't repeat in a real (non-emulated) prepared
// statement -- PDO throws "Invalid parameter number" if the same :name
// appears twice. Give each occurrence its own name, bound to the same
// value, instead of reusing one.
$statQ=$pdo->prepare("SELECT a.user_id,
    SUM(a.date >= :today_from1)                   AS today_t,
    SUM(a.date >= :today_from2 AND a.present = 0) AS today_a,
    SUM(a.date >= :week_from1)                    AS week_t,
    SUM(a.date >= :week_from2 AND a.present = 0)  AS week_a,
    SUM(a.date >= :month_from1)                   AS month_t,
    SUM(a.date >= :month_from2 AND a.present = 0) AS month_a,
    COUNT(*)                                      AS all_t,
    SUM(a.present = 0)                            AS all_a
  FROM attendance a INNER JOIN users u ON u.id = a.user_id
  WHERE u.group_id = :group_id AND a.date <= :today_to
  GROUP BY a.user_id");
$statQ->execute([
  ':today_from1'=>$today, ':today_from2'=>$today,
  ':week_from1'=>$weekStart, ':week_from2'=>$weekStart,
  ':month_from1'=>$monthStart, ':month_from2'=>$monthStart,
  ':group_id'=>$group_id, ':today_to'=>$today,
]);
$rowsByUser=[];foreach($statQ->fetchAll(PDO::FETCH_ASSOC) as $r){$rowsByUser[(int)$r['user_id']]=$r;}
$periodKeyMap=['Today'=>['today_t','today_a'],'This Week'=>['week_t','week_a'],'This Month'=>['month_t','month_a'],'All Time'=>['all_t','all_a']];
$stats=[];foreach($students as $stu){$sid=(int)$stu['id'];$r=$rowsByUser[$sid]??null;foreach($periodKeyMap as $lbl=>$keys){[$tKey,$aKey]=$keys;$tot=$r?(int)$r[$tKey]:0;$abs=$r?(int)$r[$aKey]:0;$stats[$sid][$lbl]=['total'=>$tot,'absent'=>$abs,'rate'=>$tot?round(100*($tot-$abs)/$tot,2):0,];}}
$sum=[];foreach(array_keys($periods)as $lbl){$sum[$lbl]=['absent'=>0,'total'=>0];}
foreach($students as $stu){$sid=$stu['id'];foreach(array_keys($periods)as $lbl){$sum[$lbl]['absent']+=$stats[$sid][$lbl]['absent'];$sum[$lbl]['total']+=$stats[$sid][$lbl]['total'];}}
$summary=[];foreach($sum as $lbl=>$vals){$present=$vals['total']-$vals['absent'];$pct=$vals['total']?round(100*$present/$vals['total'],2):0;$summary[$lbl]=['present'=>$present,'total'=>$vals['total'],'pct'=>$pct,];}
$theme=(($_COOKIE['theme']?? 'light')==='dark')?'dark':'light';$themeLabel=($theme==='dark')?'Dark':'Light';
$groupName="Group {$group_id}";$gQ=$pdo->prepare("SELECT name FROM groups WHERE id=?");if($gQ->execute([$group_id])&&($n=$gQ->fetchColumn())){$groupName=$n;}
?>
<!doctypehtml><html class="<?=$theme==='dark'?'dark-theme':''?>"lang="en"><head><script src="<?= asset('script.js') ?>"></script><meta charset="utf-8"><meta content="width=device-width,initial-scale=1"name="viewport"><title>Group Attendance</title><link href="<?= asset('style.css') ?>"rel="stylesheet"></head><body>
<div id="theme-switch"><label class="switch"><input id="theme-toggle"type="checkbox"<?=$theme==='dark'?'checked':''?>><span class="slider"></span></label><span id="theme-label"><?=$themeLabel?></span></div><br><br>
<button class="btn-nav"onclick='location.href="greeting.php?tg_id=<?=rawurlencode($tg_id)?>&group_id=<?= (int)$group_id ?>"'>← Back</button>
<h1>Group Attendance:<?=htmlspecialchars($groupName,ENT_QUOTES)?></h1>
<div class="stat-cards"><?php foreach($summary as $lbl=>$d): ?><div class="stat"><h3><?=htmlspecialchars($lbl,ENT_QUOTES)?></h3><p><?="{$d['present']}/{$d['total']} ({$d['pct']}%)"?></p></div><?php endforeach; ?></div>
<table><thead><tr><th></th><th>Student</th><?php foreach(array_keys($periods)as $lbl): ?><th><?=htmlspecialchars($lbl,ENT_QUOTES)?></th><?php endforeach; ?><th>View</th></tr></thead><tbody>
<?php $i=1;foreach($students as $stu):$sid=$stu['id']; ?>
<tr>
<td><?=$i++?></td>
<td class="stu-name"><?=htmlspecialchars($stu['name'],ENT_QUOTES)?></td>
<?php foreach(array_keys($periods)as $lbl):$r=$stats[$sid][$lbl]; ?><td><?="{$r['absent']}/{$r['total']} (".number_format($r['rate'],2)."%)"?></td><?php endforeach; ?>
<td>
<?php if(!empty($stu['tg_id'])): ?>
<?php $qs=http_build_query(['tg_id'=>(string)$stu['tg_id'],'return'=>'group','monitor_id'=>(string)$tg_id,'group_id'=>$group_id]); ?>
<button class="btn-view"onclick='location.href="view_attendance.php?<?=$qs?>"'>View</button>
<?php else: ?>—<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody></table>
</body></html>
