<?php
session_start();
include('../config/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'suparadmin') {
    header("Location: ../auth/login.php"); exit();
}

// Ensure all required tables exist
$conn->query("CREATE TABLE IF NOT EXISTS leave_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL, end_date DATE NOT NULL,
    days_count DECIMAL(5,1) NOT NULL DEFAULT 1,
    reason TEXT, status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    admin_notes TEXT, reviewed_by INT, reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS leave_policies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    leave_type VARCHAR(100) NOT NULL, days_allowed INT NOT NULL DEFAULT 0, year INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_type_year (leave_type, year)
)");

$conn->query("CREATE TABLE IF NOT EXISTS employee_leave_balances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL, leave_type VARCHAR(100) NOT NULL, year INT NOT NULL,
    days_allowed DECIMAL(5,1) NOT NULL DEFAULT 0, days_used DECIMAL(5,1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_emp_type_year (user_id, leave_type, year),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$msg = ''; $msg_type = '';
$cur_year = (int)date('Y');
$leave_types = ['Casual Leave','Sick Leave','Earned Leave','Maternity Leave','Paternity Leave','Unpaid Leave'];
$leave_colors = ['Casual Leave'=>'#17a2b8','Sick Leave'=>'#dc3545','Earned Leave'=>'#28a745','Maternity Leave'=>'#e83e8c','Paternity Leave'=>'#6610f2','Unpaid Leave'=>'#6c757d'];

// Handle Approve/Reject
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'],$_POST['leave_id'])) {
    $lid=(int)$_POST['leave_id']; $action=$_POST['action']==='Approved'?'Approved':'Rejected';
    $notes=htmlspecialchars($_POST['admin_notes']??''); $rev=(int)$_SESSION['user_id'];
    $lq=$conn->prepare("SELECT user_id,leave_type,days_count,status FROM leave_applications WHERE id=?");
    $lq->bind_param("i",$lid); $lq->execute(); $ld=$lq->get_result()->fetch_assoc(); $lq->close();
    if ($ld) {
        $su=$conn->prepare("UPDATE leave_applications SET status=?,admin_notes=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?");
        $su->bind_param("ssii",$action,$notes,$rev,$lid); $su->execute(); $su->close();
        $uid=$ld['user_id']; $lt=addslashes($ld['leave_type']); $days=(float)$ld['days_count'];
        if ($action==='Approved' && $ld['status']!=='Approved') {
            $conn->query("INSERT INTO employee_leave_balances (user_id,leave_type,year,days_allowed,days_used) VALUES ($uid,'$lt',$cur_year,0,$days) ON DUPLICATE KEY UPDATE days_used=days_used+$days");
        } elseif ($action==='Rejected' && $ld['status']==='Approved') {
            $conn->query("UPDATE employee_leave_balances SET days_used=GREATEST(0,days_used-$days) WHERE user_id=$uid AND leave_type='$lt' AND year=$cur_year");
        }
        $msg="Leave $action."; $msg_type=$action==='Approved'?'success':'warning';
    }
}

// Handle Save Policy
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_policy'])) {
    $year=(int)($_POST['policy_year']??$cur_year);
    foreach ($leave_types as $lt) {
        $days=max(0,(int)($_POST['policy_'.str_replace(' ','_',$lt)]??0));
        $ltq=addslashes($lt);
        $conn->query("INSERT INTO leave_policies (leave_type,days_allowed,year) VALUES ('$ltq',$days,$year) ON DUPLICATE KEY UPDATE days_allowed=$days,updated_at=NOW()");
    }
    $emps=$conn->query("SELECT id FROM users WHERE role='employee'")->fetch_all(MYSQLI_ASSOC);
    foreach ($emps as $e) {
        foreach ($leave_types as $lt) {
            $days=max(0,(int)($_POST['policy_'.str_replace(' ','_',$lt)]??0));
            $ltq=addslashes($lt); $uid=(int)$e['id'];
            $conn->query("INSERT INTO employee_leave_balances (user_id,leave_type,year,days_allowed,days_used) VALUES ($uid,'$ltq',$year,$days,0) ON DUPLICATE KEY UPDATE days_allowed=$days,updated_at=NOW()");
        }
    }
    $msg="Policy saved & applied to all employees!"; $msg_type='success';
}

// Handle Custom Allocation
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_custom'])) {
    $uid=(int)$_POST['custom_uid']; $lt=addslashes(htmlspecialchars($_POST['custom_leave_type']));
    $days=max(0,(float)$_POST['custom_days']); $year=(int)($_POST['custom_year']??$cur_year);
    if ($uid && $lt) {
        $conn->query("INSERT INTO employee_leave_balances (user_id,leave_type,year,days_allowed,days_used) VALUES ($uid,'$lt',$year,$days,0) ON DUPLICATE KEY UPDATE days_allowed=$days,updated_at=NOW()");
        $msg="Custom allocation saved!"; $msg_type='success';
    }
}

// Handle Admin Add Leave
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['admin_add_leave'])) {
    $uid=(int)$_POST['al_uid']; $lt=htmlspecialchars($_POST['al_leave_type']);
    $sd=$_POST['al_start_date']; $ed=$_POST['al_end_date'];
    $rs=htmlspecialchars($_POST['al_reason']??''); $sts=$_POST['al_status']??'Pending';
    $days=$sd<=$ed?(int)((strtotime($ed)-strtotime($sd))/86400)+1:1; $rev=(int)$_SESSION['user_id'];
    if ($uid && $lt && $sd && $ed) {
        $s=$conn->prepare("INSERT INTO leave_applications (user_id,leave_type,start_date,end_date,days_count,reason,status,reviewed_by,reviewed_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
        $s->bind_param("isssdsis",$uid,$lt,$sd,$ed,$days,$rs,$sts,$rev); $s->execute(); $s->close();
        if ($sts==='Approved') { $ltq=addslashes($lt); $conn->query("INSERT INTO employee_leave_balances (user_id,leave_type,year,days_allowed,days_used) VALUES ($uid,'$ltq',$cur_year,0,$days) ON DUPLICATE KEY UPDATE days_used=days_used+$days"); }
        $msg="Leave added!"; $msg_type='success';
    }
}

// Fetch data
$filter_status=$_GET['status']??'all'; $filter_emp=trim($_GET['emp']??'');
$cal_month=(int)($_GET['cal_month']??date('n')); $cal_year=(int)($_GET['cal_year']??$cur_year);
$active_tab=$_GET['tab']??'applications';

$where="WHERE 1=1"; $params=[]; $types='';
if ($filter_status!=='all') { $where.=" AND la.status=?"; $params[]=$filter_status; $types.='s'; }
if ($filter_emp) { $where.=" AND (u.name LIKE ? OR u.employee_id LIKE ?)"; $v="%$filter_emp%"; $params[]=$v; $params[]=$v; $types.='ss'; }
$st=$conn->prepare("SELECT la.*,u.name,u.employee_id,u.department,u.company FROM leave_applications la JOIN users u ON la.user_id=u.id $where ORDER BY la.created_at DESC");
if ($params) $st->bind_param($types,...$params);
$st->execute(); $leaves=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$counts=$conn->query("SELECT status,COUNT(*) c FROM leave_applications GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$summary=['Pending'=>0,'Approved'=>0,'Rejected'=>0,'Total'=>0];
foreach ($counts as $r) { $summary[$r['status']]=$r['c']; $summary['Total']+=$r['c']; }

$policies=[]; $pr=$conn->query("SELECT leave_type,days_allowed FROM leave_policies WHERE year=$cur_year");
while ($p=$pr->fetch_assoc()) $policies[$p['leave_type']]=$p['days_allowed'];
foreach ($leave_types as $lt) if (!isset($policies[$lt])) $policies[$lt]=0;

$all_emps=$conn->query("SELECT u.id,u.name,u.employee_id,u.department,u.company,GROUP_CONCAT(CONCAT(elb.leave_type,'|',elb.days_allowed,'|',ROUND(elb.days_used,1)) ORDER BY elb.leave_type SEPARATOR ';;') as balances FROM users u LEFT JOIN employee_leave_balances elb ON elb.user_id=u.id AND elb.year=$cur_year WHERE u.role='employee' GROUP BY u.id ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);

$cal_leaves=$conn->query("SELECT la.start_date,la.end_date,la.leave_type,la.days_count,u.name FROM leave_applications la JOIN users u ON la.user_id=u.id WHERE la.status='Approved' AND MONTH(la.start_date)=$cal_month AND YEAR(la.start_date)=$cal_year ORDER BY la.start_date")->fetch_all(MYSQLI_ASSOC);
$cal_map=[];
foreach ($cal_leaves as $cl) {
    $s=strtotime($cl['start_date']); $e=strtotime($cl['end_date']);
    for ($d=$s;$d<=$e;$d+=86400) { $k=date('Y-m-d',$d); if (!isset($cal_map[$k])) $cal_map[$k]=[]; $cal_map[$k][]=$cl; }
}

$emp_list=$conn->query("SELECT id,name,employee_id FROM users WHERE role='employee' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Leave Management</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<style>
body{background:#f4f6f9;}
.nav-tabs .nav-link.active{background:#1a237e;color:#fff;border-color:#1a237e;}
.nav-tabs .nav-link{color:#1a237e;}
.badge-Pending{background:#ffc107;color:#000;} .badge-Approved{background:#198754;color:#fff;} .badge-Rejected{background:#dc3545;color:#fff;}
.cal-table th{background:#1a237e;color:#fff;text-align:center;padding:6px;font-size:13px;}
.cal-table td{width:14.28%;min-height:80px;vertical-align:top;padding:4px;border:1px solid #dee2e6;font-size:11px;}
.cal-table td.today{background:#e8eaf6;} .cal-table td.other-month{background:#f8f9fa;color:#aaa;}
.cal-day-num{font-weight:600;font-size:13px;margin-bottom:2px;}
.cal-badge{border-radius:3px;padding:1px 4px;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;display:block;margin-bottom:1px;color:#fff;}
.bar-bg{height:7px;border-radius:4px;background:#e9ecef;margin-top:2px;} .bar-fill{height:7px;border-radius:4px;}
.stat-card{border-radius:10px;}
</style>
</head>
<body>
<?php include('_navbar.php'); ?>
<div class="container-fluid mt-4 mb-5 px-4">

<?php if ($msg): ?><div class="alert alert-<?=$msg_type?> alert-dismissible fade show"><?=htmlspecialchars($msg)?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="row g-3 mb-4">
<?php foreach([['Pending','warning','⏳'],['Approved','success','✓'],['Rejected','danger','✗'],['Total','primary','#']] as [$s,$c,$ico]): ?>
  <div class="col-6 col-md-3"><div class="card stat-card shadow-sm" style="border-left:5px solid var(--bs-<?=$c?>);"><div class="card-body py-3 text-center"><div class="h2 fw-bold text-<?=$c?> mb-0"><?=$summary[$s]?></div><small class="text-muted"><?=$ico?> <?=$s?></small></div></div></div>
<?php endforeach; ?>
</div>

<ul class="nav nav-tabs mb-4">
  <li class="nav-item"><a class="nav-link <?=$active_tab==='applications'?'active':''?>" href="?tab=applications">📋 Applications</a></li>
  <li class="nav-item"><a class="nav-link <?=$active_tab==='calendar'?'active':''?>" href="?tab=calendar&cal_month=<?=$cal_month?>&cal_year=<?=$cal_year?>">📅 Calendar</a></li>
  <li class="nav-item"><a class="nav-link <?=$active_tab==='employees'?'active':''?>" href="?tab=employees">👥 All Employees</a></li>
  <li class="nav-item"><a class="nav-link <?=$active_tab==='policy'?'active':''?>" href="?tab=policy">⚙️ Leave Policy</a></li>
</ul>

<?php if ($active_tab==='applications'): ?>
<div class="d-flex gap-2 mb-3 flex-wrap">
  <form method="GET" class="d-flex gap-2 flex-grow-1">
    <input type="hidden" name="tab" value="applications">
    <input type="text" name="emp" class="form-control form-control-sm" placeholder="Search name / ID" value="<?=htmlspecialchars($filter_emp)?>" style="max-width:200px;">
    <select name="status" class="form-select form-select-sm" style="max-width:130px;">
      <option value="all" <?=$filter_status==='all'?'selected':''?>>All Status</option>
      <?php foreach(['Pending','Approved','Rejected'] as $s): ?><option value="<?=$s?>" <?=$filter_status===$s?'selected':''?>><?=$s?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm">Go</button>
  </form>
  <button class="btn btn-success btn-sm" onclick="document.getElementById('addLeavePanel').style.display=document.getElementById('addLeavePanel').style.display==='none'?'block':'none'">➕ Add Leave</button>
</div>
<div id="addLeavePanel" style="display:none;" class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-success text-white"><h6 class="mb-0">➕ Add Leave for Employee (Admin)</h6></div>
  <div class="card-body"><form method="POST" class="row g-2">
    <input type="hidden" name="admin_add_leave" value="1">
    <div class="col-md-3"><label class="form-label fw-semibold small">Employee</label><select name="al_uid" class="form-select form-select-sm" required><option value="">Select</option><?php foreach($emp_list as $e): ?><option value="<?=$e['id']?>"><?=htmlspecialchars($e['name'])?> (<?=htmlspecialchars($e['employee_id']??'-')?>)</option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label fw-semibold small">Leave Type</label><select name="al_leave_type" class="form-select form-select-sm" required><?php foreach($leave_types as $lt): ?><option><?=$lt?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label fw-semibold small">Start Date</label><input type="date" name="al_start_date" class="form-control form-control-sm" required></div>
    <div class="col-md-2"><label class="form-label fw-semibold small">End Date</label><input type="date" name="al_end_date" class="form-control form-control-sm" required></div>
    <div class="col-md-1"><label class="form-label fw-semibold small">Status</label><select name="al_status" class="form-select form-select-sm"><option>Pending</option><option>Approved</option><option>Rejected</option></select></div>
    <div class="col-md-2"><label class="form-label fw-semibold small">Reason</label><input type="text" name="al_reason" class="form-control form-control-sm" placeholder="Optional"></div>
    <div class="col-12"><button class="btn btn-success btn-sm px-4">Save</button></div>
  </form></div>
</div>
<?php if (empty($leaves)): ?>
<div class="alert alert-info">No leave applications found.</div>
<?php else: ?>
<div class="d-flex flex-column gap-2">
  <?php foreach ($leaves as $l): $badge="badge-{$l['status']}"; $days=(float)$l['days_count']; $sD=date('d M Y',strtotime($l['start_date'])); $eD=date('d M Y',strtotime($l['end_date'])); ?>
  <div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="row align-items-center">
    <div class="col-md-2"><div class="fw-bold"><?=htmlspecialchars($l['name'])?></div><small class="text-muted"><?=htmlspecialchars($l['employee_id']??'-')?> | <?=htmlspecialchars($l['department']??'-')?></small></div>
    <div class="col-md-2"><span class="badge bg-secondary"><?=htmlspecialchars($l['leave_type'])?></span><br><small>📅 <?=$sD?> → <?=$eD?></small><br><small class="text-muted"><?=$days?> day<?=$days>1?'s':''?></small></div>
    <div class="col-md-3"><small class="text-muted">Reason: </small><small><?=htmlspecialchars(mb_substr($l['reason']??'-',0,80))?></small></div>
    <div class="col-md-2 text-center"><span class="badge px-3 py-2 <?=$badge?> rounded-pill"><?=$l['status']?></span><?php if($l['admin_notes']): ?><br><small class="text-muted"><?=htmlspecialchars(mb_substr($l['admin_notes'],0,30))?></small><?php endif; ?></div>
    <div class="col-md-3 text-end">
      <?php if ($l['status']==='Pending'): ?><button class="btn btn-success btn-sm me-1" onclick="reviewLeave(<?=$l['id']?>,'Approved')">✓ Approve</button><button class="btn btn-danger btn-sm" onclick="reviewLeave(<?=$l['id']?>,'Rejected')">✗ Reject</button>
      <?php elseif ($l['status']==='Approved'): ?><button class="btn btn-warning btn-sm" onclick="reviewLeave(<?=$l['id']?>,'Rejected')">↩ Revoke</button>
      <?php else: ?><button class="btn btn-success btn-sm" onclick="reviewLeave(<?=$l['id']?>,'Approved')">↩ Re-approve</button><?php endif; ?>
      <br><small class="text-muted"><?=date('d M Y',strtotime($l['created_at']))?></small>
    </div>
  </div></div></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($active_tab==='calendar'): ?>
<?php
$prevM=$cal_month-1; $prevY=$cal_year; if($prevM<1){$prevM=12;$prevY--;}
$nextM=$cal_month+1; $nextY=$cal_year; if($nextM>12){$nextM=1;$nextY++;}
$monthName=date('F Y',mktime(0,0,0,$cal_month,1,$cal_year));
$daysInM=(int)date('t',mktime(0,0,0,$cal_month,1,$cal_year));
$firstDow=(int)date('N',mktime(0,0,0,$cal_month,1,$cal_year));
$todayStr=date('Y-m-d');
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:#1a237e;color:#fff;">
    <a href="?tab=calendar&cal_month=<?=$prevM?>&cal_year=<?=$prevY?>" class="btn btn-sm btn-light">‹ Prev</a>
    <h5 class="mb-0"><?=$monthName?></h5>
    <a href="?tab=calendar&cal_month=<?=$nextM?>&cal_year=<?=$nextY?>" class="btn btn-sm btn-light">Next ›</a>
  </div>
  <div class="card-body p-2">
    <div class="d-flex flex-wrap gap-2 mb-2"><?php foreach($leave_colors as $lt=>$col): ?><span class="badge" style="background:<?=$col?>;font-size:11px;"><?=$lt?></span><?php endforeach; ?></div>
    <div class="table-responsive"><table class="cal-table w-100">
      <thead><tr><?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?><th><?=$d?></th><?php endforeach; ?></tr></thead>
      <tbody><?php
        $rows=ceil(($firstDow-1+$daysInM)/7);
        for($row=0;$row<$rows;$row++): ?><tr><?php for($col=1;$col<=7;$col++): $day=$row*7+$col-($firstDow-1);
          if($day<1||$day>$daysInM): ?><td class="other-month"></td><?php else:
          $ds=sprintf('%04d-%02d-%02d',$cal_year,$cal_month,$day); $isT=($ds===$todayStr); $dls=$cal_map[$ds]??[]; ?>
          <td class="<?=$isT?'today':''?>"><div class="cal-day-num <?=$isT?'text-primary':''?>"><?=$day?></div>
            <?php foreach(array_slice($dls,0,3) as $cl): $lc=$leave_colors[$cl['leave_type']]??'#666'; ?>
            <span class="cal-badge" style="background:<?=$lc?>;" title="<?=htmlspecialchars($cl['name'].' - '.$cl['leave_type'])?>"><?=htmlspecialchars(explode(' ',$cl['name'])[0])?></span>
            <?php endforeach; ?>
            <?php if(count($dls)>3): ?><span style="font-size:10px;color:#888;">+<?=count($dls)-3?> more</span><?php endif; ?>
          </td><?php endif; endfor; ?></tr><?php endfor; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php if(!empty($cal_leaves)): ?>
<div class="card border-0 shadow-sm"><div class="card-header bg-light"><h6 class="mb-0">Approved Leaves – <?=$monthName?></h6></div><div class="card-body p-0">
<table class="table table-sm mb-0"><thead class="table-dark"><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th></tr></thead><tbody>
<?php foreach($cal_leaves as $cl): ?><tr><td><?=htmlspecialchars($cl['name'])?></td><td><span class="badge" style="background:<?=$leave_colors[$cl['leave_type']]??'#666'?>"><?=$cl['leave_type']?></span></td><td><?=date('d M',strtotime($cl['start_date']))?></td><td><?=date('d M',strtotime($cl['end_date']))?></td><td><?=$cl['days_count']?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>

<?php elseif ($active_tab==='employees'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h6 class="fw-bold mb-0">All Employees – Leave Balance (<?=$cur_year?>)</h6>
  <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#customModal">✏️ Custom Allocation</button>
</div>
<div class="d-flex flex-column gap-2">
<?php foreach ($all_emps as $emp):
  $bals=[];
  if($emp['balances']) foreach(explode(';;',$emp['balances']) as $b) { [$lt,$da,$du]=explode('|',$b); $bals[$lt]=['a'=>(float)$da,'u'=>(float)$du]; }
  foreach($leave_types as $lt) if(!isset($bals[$lt])) $bals[$lt]=['a'=>$policies[$lt]??0,'u'=>0];
?>
<div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="row align-items-center">
  <div class="col-md-2"><div class="fw-bold"><?=htmlspecialchars($emp['name'])?></div><small class="text-muted"><?=htmlspecialchars($emp['employee_id']??'-')?> | <?=htmlspecialchars($emp['department']??'-')?></small></div>
  <div class="col-md-10"><div class="row g-2">
  <?php foreach($leave_types as $lt): $b=$bals[$lt]??['a'=>0,'u'=>0]; $pct=$b['a']>0?min(100,($b['u']/$b['a'])*100):0; $fc=$pct>=100?'#dc3545':($pct>=75?'#ffc107':'#198754'); $lc=$leave_colors[$lt]??'#666'; ?>
    <div class="col-4 col-md-2">
      <div style="font-size:11px;font-weight:600;color:<?=$lc?>;"><?=$lt?></div>
      <div style="font-size:12px;"><?=$b['u']?> / <?=$b['a']?> d</div>
      <div class="bar-bg"><div class="bar-fill" style="width:<?=$pct?>%;background:<?=$fc?>;"></div></div>
      <div style="font-size:10px;color:#666;"><?=max(0,$b['a']-$b['u'])?> left</div>
    </div>
  <?php endforeach; ?>
  </div></div>
</div></div></div>
<?php endforeach; ?>
</div>

<?php elseif ($active_tab==='policy'): ?>
<div class="row g-4">
  <div class="col-md-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header text-white" style="background:#1a237e;"><h6 class="mb-0">⚙️ Company-Wide Annual Leave Quota</h6></div>
      <div class="card-body"><form method="POST">
        <input type="hidden" name="save_policy" value="1">
        <div class="mb-3"><label class="form-label fw-semibold">Year</label><select name="policy_year" class="form-select form-select-sm" style="max-width:110px;"><?php for($y=$cur_year-1;$y<=$cur_year+1;$y++): ?><option value="<?=$y?>" <?=$y===$cur_year?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
        <table class="table table-sm"><thead class="table-light"><tr><th>Leave Type</th><th>Days/Year</th></tr></thead><tbody>
        <?php foreach($leave_types as $lt): $fn='policy_'.str_replace(' ','_',$lt); $val=$policies[$lt]??0; ?>
        <tr><td><span class="badge" style="background:<?=$leave_colors[$lt]?>"><?=$lt?></span></td><td><input type="number" name="<?=$fn?>" class="form-control form-control-sm" style="max-width:80px;" value="<?=$val?>" min="0" max="365"></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <button class="btn btn-primary w-100" type="submit">💾 Save & Apply to All Employees</button>
        <small class="text-muted d-block mt-2">⚠️ Applies quota to ALL employees. Individual overrides can be set separately.</small>
      </form></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card border-0 shadow-sm mt-3"><div class="card-header bg-light"><h6 class="mb-0">Current Policy (<?=$cur_year?>)</h6></div><div class="card-body p-0">
    <table class="table table-sm mb-0"><thead class="table-dark"><tr><th>Type</th><th>Days/Year</th></tr></thead><tbody>
    <?php foreach($policies as $lt=>$d): ?><tr><td><span class="badge" style="background:<?=$leave_colors[$lt]??'#666'?>"><?=$lt?></span></td><td><?=$d?> days</td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  </div>
</div>
<?php endif; ?>

</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
  <form method="POST">
    <div class="modal-header" id="reviewHeader"><h5 class="modal-title" id="reviewTitle">Review Leave</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><input type="hidden" name="leave_id" id="reviewLeaveId"><input type="hidden" name="action" id="reviewAction"><label class="form-label fw-semibold">Admin Notes</label><textarea name="admin_notes" class="form-control" rows="3" placeholder="Optional note..."></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" id="reviewBtn">Confirm</button></div>
  </form>
</div></div></div>

<!-- Custom Modal (from employees tab) -->
<div class="modal fade" id="customModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><input type="hidden" name="save_custom" value="1">
    <div class="modal-header bg-warning text-dark"><h5 class="modal-title">✏️ Custom Leave Allocation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-3">
      <div class="col-12"><label class="form-label fw-semibold">Employee</label><select name="custom_uid" class="form-select" required><option value="">Select</option><?php foreach($emp_list as $e): ?><option value="<?=$e['id']?>"><?=htmlspecialchars($e['name'])?> (<?=htmlspecialchars($e['employee_id']??'-')?>)</option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label fw-semibold">Leave Type</label><select name="custom_leave_type" class="form-select" required><?php foreach($leave_types as $lt): ?><option><?=$lt?></option><?php endforeach; ?><option value="Custom Leave">Custom Leave</option></select></div>
      <div class="col-3"><label class="form-label fw-semibold">Days</label><input type="number" name="custom_days" class="form-control" min="0" value="0" required></div>
      <div class="col-3"><label class="form-label fw-semibold">Year</label><select name="custom_year" class="form-select"><?php for($y=$cur_year-1;$y<=$cur_year+1;$y++): ?><option value="<?=$y?>" <?=$y===$cur_year?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Save</button></div>
  </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function reviewLeave(id,action) {
  document.getElementById('reviewLeaveId').value=id; document.getElementById('reviewAction').value=action;
  const ok=action==='Approved';
  document.getElementById('reviewTitle').textContent=ok?'✓ Approve Leave':'✗ Reject / Revoke Leave';
  document.getElementById('reviewHeader').className='modal-header '+(ok?'bg-success text-white':'bg-danger text-white');
  document.getElementById('reviewBtn').className='btn '+(ok?'btn-success':'btn-danger');
  document.getElementById('reviewBtn').textContent=ok?'✓ Approve':'✗ Confirm';
  new bootstrap.Modal(document.getElementById('reviewModal')).show();
}
<?php if ($msg): ?>
Swal.fire({icon:'<?=$msg_type==="success"?"success":"info"?>',title:'Done',text:'<?=addslashes($msg)?>',confirmButtonColor:'#1a237e',timer:2500,timerProgressBar:true});
<?php endif; ?>
</script>
</body>
</html>
