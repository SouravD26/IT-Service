<?php
session_start();
include('../config/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'suparadmin') {
    header("Location: ../auth/login.php"); exit();
}

// ── Ensure leave_applications table exists ──
$conn->query("CREATE TABLE IF NOT EXISTS leave_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type ENUM('Casual Leave','Sick Leave','Earned Leave','Maternity Leave','Paternity Leave','Unpaid Leave') NOT NULL,
    start_date DATE NOT NULL, end_date DATE NOT NULL, days_count INT NOT NULL DEFAULT 1,
    reason TEXT, status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    admin_notes TEXT, reviewed_by INT, reviewed_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// ── AJAX: compute Paid Days for a month from actual attendance (not just rejected leave) ──
// Must run before any HTML is emitted, otherwise the JSON response gets glued to the full page markup.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'leaves') {
    header('Content-Type: application/json');
    $uid   = (int)($_GET['user_id'] ?? 0);
    $month = $_GET['month'] ?? date('Y-m'); // "2026-08"
    [$y,$m] = explode('-', $month);
    $start = "$y-$m-01";
    $end   = date('Y-m-t', strtotime($start)); // last day of month
    $daysInMonth = (int)date('t', strtotime($start));

    $weekOff = '';
    $wst = $conn->prepare("SELECT week_off FROM users WHERE id = ?");
    $wst->bind_param("i", $uid);
    $wst->execute();
    $weekOff = $wst->get_result()->fetch_assoc()['week_off'] ?? '';
    $wst->close();

    // Dates the employee actually attended (Present/Late)
    $presentDates = [];
    $ast = $conn->prepare("SELECT DISTINCT date FROM attendance WHERE user_id=? AND date BETWEEN ? AND ? AND status IN ('Present','Late')");
    $ast->bind_param("iss", $uid, $start, $end);
    $ast->execute();
    foreach ($ast->get_result() as $r) { $presentDates[$r['date']] = true; }
    $ast->close();

    // On-Duty dates (paid)
    $odDates = [];
    $ost = $conn->prepare("SELECT DISTINCT od_date FROM od_records WHERE user_id=? AND od_date BETWEEN ? AND ?");
    $ost->bind_param("iss", $uid, $start, $end);
    $ost->execute();
    foreach ($ost->get_result() as $r) { $odDates[$r['od_date']] = true; }
    $ost->close();

    // Comp-off adjusted dates (paid)
    $adjDates = [];
    $cst = $conn->prepare("SELECT DISTINCT comp_off_date FROM comp_off_requests WHERE user_id=? AND comp_off_date BETWEEN ? AND ?");
    $cst->bind_param("iss", $uid, $start, $end);
    $cst->execute();
    foreach ($cst->get_result() as $r) { $adjDates[$r['comp_off_date']] = true; }
    $cst->close();

    // Approved leave dates, excluding Unpaid Leave (paid)
    $paidLeaveDates = [];
    $lst = $conn->prepare("SELECT leave_type, start_date, end_date FROM leave_applications WHERE user_id=? AND status='Approved' AND start_date <= ? AND end_date >= ?");
    $lst->bind_param("iss", $uid, $end, $start);
    $lst->execute();
    foreach ($lst->get_result() as $r) {
        if ($r['leave_type'] === 'Unpaid Leave') continue;
        $d = max(strtotime($r['start_date']), strtotime($start));
        $lastDay = min(strtotime($r['end_date']), strtotime($end));
        while ($d <= $lastDay) {
            $paidLeaveDates[date('Y-m-d', $d)] = true;
            $d = strtotime('+1 day', $d);
        }
    }
    $lst->close();

    // Walk every day of the month: paid if worked, on week-off, OD, comp-off adjusted, or paid leave
    $paidDays = 0;
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%s-%s-%02d', $y, $m, $d);
        $dayName = date('l', strtotime($dateStr));
        if ($dayName === $weekOff
            || isset($presentDates[$dateStr])
            || isset($odDates[$dateStr])
            || isset($adjDates[$dateStr])
            || isset($paidLeaveDates[$dateStr])) {
            $paidDays++;
        }
    }
    $absentDays = $daysInMonth - $paidDays;

    echo json_encode(['absent_days' => $absentDays, 'paid_days' => $paidDays, 'days_in_month' => $daysInMonth]);
    exit;
}

// ── Pagination & Search ──
$per_page   = 30;
$page       = max(1, (int)($_GET['page'] ?? 1));
$search     = trim($_GET['search'] ?? '');
$offset     = ($page - 1) * $per_page;

$where = "WHERE u.role='employee'";
$params = []; $types = '';
if ($search) {
    $where .= " AND (u.name LIKE ? OR u.employee_id LIKE ? OR u.phone LIKE ?)";
    $v = "%$search%"; $params = [$v,$v,$v]; $types = 'sss';
}

// Count
$cnt = $conn->prepare("SELECT COUNT(*) FROM users u $where");
if ($params) $cnt->bind_param($types, ...$params);
$cnt->execute(); $total = $cnt->get_result()->fetch_row()[0]; $cnt->close();
$total_pages = max(1, ceil($total / $per_page));

// Fetch
$sql = "SELECT u.id, u.name, u.employee_id, u.department, u.company, u.phone, u.date_of_joining,
               COALESCE(s.salary_ctc,0) salary_ctc,
               COALESCE(s.basic_monthly,0) basic_monthly,
               COALESCE(s.special_allowance_monthly,0) sal_allowance,
               COALESCE(s.pf_monthly,0) pf_monthly,
               COALESCE(s.esi_monthly,0) esi_monthly,
               IFNULL(s.pf_calc,'') pf_calc, IFNULL(s.esi_calc,'') esi_calc,
               IFNULL(s.custom_components,'[]') custom_components
        FROM users u
        LEFT JOIN salary_structures s ON s.user_id=u.id
        $where ORDER BY u.name LIMIT ? OFFSET ?";
$params2 = $params; $params2[] = $per_page; $params2[] = $offset;
$types2 = $types . 'ii';
$st = $conn->prepare($sql);
$st->bind_param($types2, ...$params2);
$st->execute();
$employees = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Salary Slip</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<style>
body { background:#f4f6f9; }
.emp-row { background:#fff; border-radius:8px; transition:box-shadow .2s; }
.emp-row:hover { box-shadow:0 4px 14px rgba(0,0,0,.1); }

/* ── Print / PDF styles ── */
@media print {
  body * { visibility:hidden; }
  #slipPrintArea, #slipPrintArea * { visibility:visible; }
  #slipPrintArea { position:absolute; top:0; left:0; width:100%; }
  .no-print { display:none!important; }
}
.slip-wrap { font-family:'Segoe UI',Arial,sans-serif; max-width:780px; margin:auto; }
.slip-header { background:#1a237e; color:#fff; padding:20px 28px; border-radius:8px 8px 0 0; text-align:center; }
.slip-header .slip-logo { height:50px; width:auto; margin-bottom:8px; }
.slip-header .company-name { font-size:22px; font-weight:700; }
.slip-header .slip-title { font-size:13px; opacity:.8; letter-spacing:2px; text-transform:uppercase; margin-top:2px; }
.slip-meta { background:#e8eaf6; padding:16px 28px; }
.slip-meta .meta-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
.slip-meta .meta-item label { font-size:11px; color:#555; text-transform:uppercase; letter-spacing:.5px; display:block; }
.slip-meta .meta-item span { font-weight:600; font-size:13.5px; }
.slip-body { padding:20px 28px; }
.slip-table { width:100%; border-collapse:collapse; margin-bottom:16px; }
.slip-table th { background:#f5f5f5; font-size:11px; text-transform:uppercase; letter-spacing:.5px; padding:8px 12px; border:1px solid #e0e0e0; }
.slip-table td { padding:8px 12px; border:1px solid #e0e0e0; font-size:13px; }
.slip-table .total-row { background:#e8eaf6; font-weight:700; }
.net-box { background:#1a237e; color:#fff; border-radius:6px; padding:16px 24px; margin-top:12px; display:flex; justify-content:space-between; align-items:center; }
.net-box .net-label { font-size:14px; opacity:.85; }
.net-box .net-amount { font-size:28px; font-weight:700; }
.net-words { color:#fff; font-size:12px; margin-top:8px; font-style:italic; opacity:.85; }
.slip-footer { border-top:1px solid #e0e0e0; padding:12px 28px; text-align:center; font-size:11px; color:#888; border-radius:0 0 8px 8px; }
.deduction-note { background:#fff3cd; border:1px solid #ffc107; border-radius:6px; padding:8px 14px; font-size:12px; color:#856404; margin-bottom:12px; }
</style>
</head>
<body>
<?php include('_navbar.php'); ?>

<div class="container-fluid mt-4 mb-5 no-print">

  <!-- Search -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
      <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-8">
          <label class="form-label fw-semibold">🔍 Search Employee</label>
          <input type="text" name="search" class="form-control" placeholder="Search by name, employee ID, or phone number..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Search</button></div>
        <div class="col-md-2"><a href="salary_slip.php" class="btn btn-outline-secondary w-100">Clear</a></div>
      </form>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <small class="text-muted">Showing <?= count($employees) ?> of <?= $total ?> employees</small>
    <small class="text-muted">Page <?= $page ?> of <?= $total_pages ?></small>
  </div>

  <!-- Employee rows -->
  <?php if (empty($employees)): ?>
    <div class="alert alert-info">No employees found.</div>
  <?php else: ?>
  <div class="d-flex flex-column gap-3">
    <?php foreach ($employees as $e): ?>
    <div class="emp-row p-3 shadow-sm">
      <div class="row align-items-center">
        <div class="col-md-2">
          <div class="fw-bold"><?= htmlspecialchars($e['name']) ?></div>
          <small class="text-muted"><?= htmlspecialchars($e['employee_id'] ?? '-') ?></small>
        </div>
        <div class="col-md-2">
          <small class="text-muted">Dept:</small> <?= htmlspecialchars($e['department'] ?? '-') ?><br>
          <small class="text-muted">Co:</small> <?= htmlspecialchars($e['company'] ?? '-') ?>
        </div>
        <div class="col-md-2">
          <small class="text-muted">Phone:</small> <?= htmlspecialchars($e['phone'] ?? '-') ?>
        </div>
        <div class="col-md-2 text-center">
          <span class="fw-bold text-primary">₹<?= number_format((float)$e['salary_ctc'], 2) ?></span><br>
          <small class="text-muted">Monthly CTC</small>
        </div>
        <div class="col-md-4 text-end">
          <!-- Month/Year selector for slip -->
          <div class="d-flex align-items-center justify-content-end gap-2">
            <select class="form-select form-select-sm" style="max-width:140px;" id="slipMonth_<?= $e['id'] ?>">
              <?php
              for ($m = 0; $m < 12; $m++) {
                  $ts  = mktime(0,0,0, date('n') - $m, 1);
                  $val = date('Y-m', $ts);
                  $lbl = date('M Y', $ts);
                  $sel = $m === 0 ? 'selected' : '';
                  echo "<option value=\"$val\" $sel>$lbl</option>";
              }
              ?>
            </select>
            <button class="btn btn-success btn-sm" onclick="generateSlip(<?= $e['id'] ?>, <?= htmlspecialchars(json_encode($e)) ?>)">
              📄 Generate Slip
            </button>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <nav class="mt-4">
    <ul class="pagination justify-content-center">
      <?php for ($p = 1; $p <= $total_pages; $p++): ?>
      <li class="page-item <?= $p==$page?'active':'' ?>">
        <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"><?= $p ?></a>
      </li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>
  <?php endif; ?>

</div>

<!-- ── Salary Slip Modal ── -->
<div class="modal fade" id="slipModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header no-print">
        <h5 class="modal-title">📄 Salary Slip</h5>
        <div class="ms-auto d-flex gap-2">
          <button class="btn btn-primary btn-sm" onclick="printSlip()">🖨️ Print</button>
          <button class="btn btn-danger btn-sm" onclick="downloadPDF()">⬇️ Download PDF</button>
          <button type="button" class="btn-close ms-2" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body p-0" id="slipPrintArea">
        <!-- slip content injected here -->
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
const INR = n => parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2});

function numWords(n) {
    const a=['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    const b=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    if(n===0)return'Zero';
    if(n<0)return'Minus '+numWords(-n);
    let w='';
    if(n>=10000000){w+=numWords(Math.floor(n/10000000))+' Crore ';n%=10000000;}
    if(n>=100000){w+=numWords(Math.floor(n/100000))+' Lakh ';n%=100000;}
    if(n>=1000){w+=numWords(Math.floor(n/1000))+' Thousand ';n%=1000;}
    if(n>=100){w+=a[Math.floor(n/100)]+' Hundred ';n%=100;}
    if(n>19){w+=b[Math.floor(n/10)]+' ';n%=10;}
    if(n>0)w+=a[n]+' ';
    return w.trim();
}

async function generateSlip(empId, emp) {
    // Show loading
    Swal.fire({title:'Loading slip...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});

    const monthVal = document.getElementById('slipMonth_' + empId).value; // "2026-08"
    const [yr, mo] = monthVal.split('-').map(Number);
    const monthName = new Date(yr, mo-1, 1).toLocaleString('en-IN',{month:'long', year:'numeric'});
    const daysInMonth = new Date(yr, mo, 0).getDate();

    // Fetch rejected leaves for that month
    let absentDays = 0, deductionAmt = 0;
    try {
        const resp = await fetch(`salary_slip.php?ajax=leaves&user_id=${empId}&month=${monthVal}`);
        const data = await resp.json();
        absentDays = data.absent_days || 0;
    } catch(e){}

    // Parse earnings
    const basic  = parseFloat(emp.basic_monthly || 0);
    const allow  = parseFloat(emp.sal_allowance  || 0);
    const pf     = parseFloat(emp.pf_monthly     || 0);
    const esi    = parseFloat(emp.esi_monthly     || 0);
    let customComps = [];
    try { customComps = JSON.parse(emp.custom_components || '[]'); } catch(e){}

    let earningsRows = '';
    let grossEarnings = basic + allow;
    earningsRows += `<tr><td>Basic Salary</td><td class="text-end">₹ ${INR(basic)}</td></tr>`;
    earningsRows += `<tr><td>Special Allowance</td><td class="text-end">₹ ${INR(allow)}</td></tr>`;
    customComps.forEach(c => {
        earningsRows += `<tr><td>${c.name}</td><td class="text-end">₹ ${INR(c.monthly)}</td></tr>`;
        grossEarnings += parseFloat(c.monthly || 0);
    });

    // Per-day rate must come from gross earnings, not CTC — CTC already bakes in
    // PF/ESI (employer + employee contributions), which are also deducted as
    // separate line items below. Using CTC here double-counts them and can push
    // net pay to ₹0 or negative for anyone with an absence.
    const perDaySalary = daysInMonth > 0 ? grossEarnings / daysInMonth : 0;
    deductionAmt = perDaySalary * absentDays;

    let deductionRows = '';
    let totalDeductions = pf + esi + deductionAmt;
    if (pf > 0) deductionRows += `<tr><td>Provident Fund (${emp.pf_calc})</td><td class="text-end">₹ ${INR(pf)}</td></tr>`;
    if (esi > 0) deductionRows += `<tr><td>ESI (${emp.esi_calc})</td><td class="text-end">₹ ${INR(esi)}</td></tr>`;
    if (absentDays > 0) {
        deductionRows += `<tr><td>Leave Deduction (${absentDays} day${absentDays>1?'s':''} absent)</td><td class="text-end text-danger">₹ ${INR(deductionAmt)}</td></tr>`;
    }
    if (!deductionRows) deductionRows = '<tr><td colspan="2" class="text-muted text-center">No deductions</td></tr>';

    const netPay = grossEarnings - totalDeductions;
    const paidDays = daysInMonth - absentDays;
    const payDate = new Date(yr, mo-1, 1).toLocaleString('en-IN',{day:'2-digit',month:'short',year:'numeric'});

    const slip = `
    <div class="slip-wrap" style="padding:0;">
      <div class="slip-header">
        <img src="../assets/images/logo.png" alt="Logo" class="slip-logo">
        
        <div class="slip-title">Payslip for ${monthName}</div>
      </div>

      <div class="slip-meta">
        <div class="meta-grid">
          <div class="meta-item"><label>Employee Name</label><span>${escHtml(emp.name)}</span></div>
          <div class="meta-item"><label>Employee ID</label><span>${escHtml(emp.employee_id||'-')}</span></div>
          <div class="meta-item"><label>Department</label><span>${escHtml(emp.department||'-')}</span></div>
          <div class="meta-item"><label>Pay Period</label><span>${monthName}</span></div>
          <div class="meta-item"><label>Paid Days</label><span>${paidDays} / ${daysInMonth}</span></div>
          <div class="meta-item"><label>Loss of Pay Days</label><span>${absentDays}</span></div>
          <div class="meta-item"><label>Date of Joining</label><span>${emp.date_of_joining || '-'}</span></div>
          <div class="meta-item"><label>Pay Date</label><span>${payDate}</span></div>
        </div>
      </div>

      <div class="slip-body">
        ${absentDays > 0 ? `<div class="deduction-note">⚠️ <strong>${absentDays} day${absentDays>1?'s':''} unpaid</strong> (absent / unapproved leave). Deduction: ₹ ${INR(deductionAmt)}</div>` : ''}

        <div class="row g-0">
          <div class="col-6 pe-2">
            <table class="slip-table">
              <thead><tr><th>Earnings</th><th class="text-end">Amount</th></tr></thead>
              <tbody>
                ${earningsRows}
                <tr class="total-row"><td>Gross Earnings</td><td class="text-end">₹ ${INR(grossEarnings)}</td></tr>
              </tbody>
            </table>
          </div>
          <div class="col-6 ps-2">
            <table class="slip-table">
              <thead><tr><th>Deductions</th><th class="text-end">Amount</th></tr></thead>
              <tbody>
                ${deductionRows}
                <tr class="total-row"><td>Total Deductions</td><td class="text-end">₹ ${INR(totalDeductions)}</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="net-box">
          <div>
            <div class="net-label">Total Net Payable</div>
            <div class="net-words">Amount in Words: ${numWords(Math.round(Math.max(0, netPay)))} Rupees Only</div>
          </div>
          <div class="net-amount">₹ ${INR(Math.max(0, netPay))}</div>
        </div>
      </div>

      <div class="slip-footer">
        This is a computer-generated salary slip and does not require a signature. &nbsp;|&nbsp; Generated on ${new Date().toLocaleDateString('en-IN')}
      </div>
    </div>`;

    document.getElementById('slipPrintArea').innerHTML = slip;
    Swal.close();
    new bootstrap.Modal(document.getElementById('slipModal')).show();
}

function escHtml(t) {
    return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function printSlip() {
    // Printing directly via the modal fails: Bootstrap's `modal-dialog-scrollable`
    // sets overflow:hidden on .modal-content, which clips the print-area's
    // position:absolute override and produces a blank page. Print from an
    // isolated popup window instead.
    const content = document.getElementById('slipPrintArea').innerHTML;
    const printCss = `
        body { font-family:'Segoe UI',Arial,sans-serif; margin:0; padding:16px; }
        .slip-wrap { font-family:'Segoe UI',Arial,sans-serif; max-width:780px; margin:auto; }
        .slip-header { background:#1a237e; color:#fff; padding:20px 28px; border-radius:8px 8px 0 0; text-align:center; }
        .slip-header .slip-logo { height:50px; width:auto; margin-bottom:8px; }
        .slip-header .company-name { font-size:22px; font-weight:700; }
        .slip-header .slip-title { font-size:13px; opacity:.8; letter-spacing:2px; text-transform:uppercase; margin-top:2px; }
        .slip-meta { background:#e8eaf6; padding:16px 28px; }
        .slip-meta .meta-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
        .slip-meta .meta-item label { font-size:11px; color:#555; text-transform:uppercase; letter-spacing:.5px; display:block; }
        .slip-meta .meta-item span { font-weight:600; font-size:13.5px; }
        .slip-body { padding:20px 28px; }
        .slip-table { width:100%; border-collapse:collapse; margin-bottom:16px; }
        .slip-table th { background:#f5f5f5; font-size:11px; text-transform:uppercase; letter-spacing:.5px; padding:8px 12px; border:1px solid #e0e0e0; }
        .slip-table td { padding:8px 12px; border:1px solid #e0e0e0; font-size:13px; }
        .slip-table .total-row { background:#e8eaf6; font-weight:700; }
        .net-box { background:#1a237e; color:#fff; border-radius:6px; padding:16px 24px; margin-top:12px; display:flex; justify-content:space-between; align-items:center; }
        .net-box .net-label { font-size:14px; opacity:.85; }
        .net-box .net-amount { font-size:28px; font-weight:700; }
        .net-words { color:#fff; font-size:12px; margin-top:8px; font-style:italic; opacity:.85; }
        .slip-footer { border-top:1px solid #e0e0e0; padding:12px 28px; text-align:center; font-size:11px; color:#888; border-radius:0 0 8px 8px; }
        .deduction-note { background:#fff3cd; border:1px solid #ffc107; border-radius:6px; padding:8px 14px; font-size:12px; color:#856404; margin-bottom:12px; }
        .row { display:flex; flex-wrap:wrap; margin:0; }
        .g-0 { gap:0; }
        .col-6 { width:50%; box-sizing:border-box; }
        .pe-2 { padding-right:8px; }
        .ps-2 { padding-left:8px; }
        table { border-collapse:collapse; }
        @page { margin:12mm; }
    `;

    const win = window.open('', '_blank', 'width=850,height=900');
    if (!win) {
        Swal.fire('Error', 'Popup blocked. Please allow popups for this site to print the slip.', 'error');
        return;
    }
    win.document.open();
    win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Salary Slip</title><style>${printCss}</style></head><body>${content}</body></html>`);
    win.document.close();
    win.onload = () => {
        win.focus();
        win.print();
    };
    win.onafterprint = () => win.close();
}

async function downloadPDF() {
    const { jsPDF } = window.jspdf;
    Swal.fire({title:'Generating PDF...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
    try {
        const el = document.getElementById('slipPrintArea');
        const canvas = await html2canvas(el, {scale:2, useCORS:true});
        const imgData = canvas.toDataURL('image/png');
        const pdf = new jsPDF('p','mm','a4');
        const pdfW = pdf.internal.pageSize.getWidth();
        const pdfH = (canvas.height * pdfW) / canvas.width;
        pdf.addImage(imgData,'PNG',0,0,pdfW,pdfH);
        pdf.save('salary_slip.pdf');
        Swal.close();
    } catch(e) {
        Swal.fire('Error', 'Could not generate PDF: '+e.message, 'error');
    }
}
</script>
</body>
</html>

