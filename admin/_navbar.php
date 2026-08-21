<?php
// Shared navbar + sidebar for all admin pages
// Usage: include('_navbar.php');
// Requires: $conn, $_SESSION to be set already

$_admin_name = '';
if (isset($_SESSION['user_id'])) {
    $__uid = (int)$_SESSION['user_id'];
    $__s = $conn->prepare("SELECT name FROM users WHERE id=?");
    $__s->bind_param("i", $__uid); $__s->execute();
    $__r = $__s->get_result()->fetch_assoc(); $__s->close();
    $_admin_name = $__r['name'] ?? 'Admin';
}
$_current_page = basename($_SERVER['PHP_SELF']);

$_nav_links = [
    ['href'=>'dashboard.php',           'icon'=>'🏠', 'label'=>'Dashboard'],
    ['href'=>'admin.php?from=suparadmin','icon'=>'👤', 'label'=>'Manage Admins'],
    ['href'=>'employees.php?from=suparadmin','icon'=>'👥','label'=>'Employees'],
    ['href'=>'manage_passwords.php?from=suparadmin','icon'=>'🔑','label'=>'Passwords'],
    ['href'=>'department.php?from=suparadmin','icon'=>'🏢','label'=>'Departments'],
    ['href'=>'companies.php?from=suparadmin','icon'=>'🏪','label'=>'Companies'],
    ['href'=>'shifts.php?from=suparadmin','icon'=>'⏰','label'=>'Shifts'],
    ['href'=>'locations.php?from=suparadmin','icon'=>'📍','label'=>'Locations'],
    ['href'=>'attendance.php?from=suparadmin','icon'=>'📊','label'=>'Attendance'],
    ['href'=>'manual_attendance.php',   'icon'=>'⌨️','label'=>'Manual Attendance'],
    ['href'=>'export_monthly.php?from=suparadmin','icon'=>'📥','label'=>'Export Reports'],
    ['href'=>'comp_off_management.php', 'icon'=>'📅','label'=>'Comp Off'],
    ['href'=>'od_management.php?from=suparadmin','icon'=>'📝','label'=>'OD Management'],
    ['href'=>'salary_slip.php',         'icon'=>'💰','label'=>'Salary Slip'],
    ['href'=>'leave_management.php',    'icon'=>'🗓️','label'=>'Leave Management'],
    ['href'=>'geo_restriction.php?from=suparadmin','icon'=>'📡','label'=>'GPS Restriction'],
];
?>
<style>
/* ── Navbar ── */
.admin-navbar {
    background: linear-gradient(90deg, #1a237e 0%, #283593 100%);
    min-height: 60px;
    position: sticky;
    top: 0;
    z-index: 1050;
    box-shadow: 0 4px 20px rgba(26,35,126,0.14);
    border-radius: 0 0 20px 20px;
}
.admin-navbar .brand-logo {
    height: 42px; width: auto; max-width: 160px;
}
.admin-navbar .welcome-text { color: rgba(255,255,255,0.85); font-size: 13px; }
.admin-navbar .welcome-text strong { color: #fff; }

/* ── Sidebar ── */
#adminSidebar {
    position: fixed;
    top: 60px;
    left: -260px;
    width: 260px;
    height: calc(100vh - 60px);
    background: #1a237e;
    z-index: 1040;
    overflow-y: auto;
    transition: left 0.3s ease;
    box-shadow: 4px 0 20px rgba(0,0,0,0.3);
}
#adminSidebar.open { left: 0; }
#adminSidebar .sidebar-header {
    padding: 16px 20px 10px;
    border-bottom: 1px solid rgba(255,255,255,0.12);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.5);
    font-family: 'Inter', 'Segoe UI', sans-serif;
}
.admin-navbar, #adminSidebar .nav-link, .admin-navbar .welcome-text {
    font-family: 'Inter', 'Segoe UI', sans-serif;
}
#adminSidebar .nav-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    color: rgba(255,255,255,0.78);
    font-size: 13.5px;
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
    border-left: 3px solid transparent;
}
#adminSidebar .nav-link:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
}
#adminSidebar .nav-link.active {
    background: rgba(255,255,255,0.15);
    color: #fff;
    border-left-color: #ffd740;
    font-weight: 600;
}
#adminSidebar .nav-link .icon { font-size: 16px; width: 22px; text-align: center; }

/* Overlay */
#sidebarOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 1039;
}
#sidebarOverlay.show { display: block; }

/* Sidebar toggle button */
.sidebar-toggle-btn {
    background: none;
    border: none;
    color: #fff;
    font-size: 22px;
    padding: 4px 10px;
    cursor: pointer;
    line-height: 1;
    border-radius: 6px;
    transition: background 0.2s;
}
.sidebar-toggle-btn:hover { background: rgba(255,255,255,0.15); }

/* Body offset when sidebar open on large screens - optional */
@media (min-width: 1200px) {
    body.sidebar-open { padding-left: 260px; }
    #adminSidebar.open { position: fixed; }
}
</style>

<!-- Navbar -->
<nav class="navbar admin-navbar px-3">
    <div class="d-flex align-items-center gap-3 flex-grow-1">
        <button class="sidebar-toggle-btn" id="sidebarToggle" title="Menu">☰</button>
        <a href="dashboard.php">
            <img src="../assets/images/logo.png" alt="Logo" class="brand-logo" onerror="this.style.display='none'">
        </a>
        <span class="welcome-text d-none d-md-block">Welcome, <strong><?= htmlspecialchars($_admin_name) ?></strong></span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <!-- <a href="dashboard.php" class="btn btn-sm btn-outline-light">🏠 Dashboard</a> -->
        <a href="../auth/logout.php" class="btn btn-sm btn-danger">Logout</a>
    </div>
</nav>

<!-- Sidebar -->
<div id="sidebarOverlay"></div>
<div id="adminSidebar">
    <div class="sidebar-header">Navigation</div>
    <?php foreach ($_nav_links as $link):
        $isActive = (basename($link['href']) === $_current_page || strpos($link['href'], $_current_page) !== false);
    ?>
    <a href="<?= htmlspecialchars($link['href']) ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
        <span class="icon"><?= $link['icon'] ?></span>
        <?= htmlspecialchars($link['label']) ?>
    </a>
    <?php endforeach; ?>
    <div style="height:20px;"></div>
</div>

<script>
(function(){
    const toggle   = document.getElementById('sidebarToggle');
    const sidebar  = document.getElementById('adminSidebar');
    const overlay  = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('show');
        document.body.classList.add('sidebar-open');
        localStorage.setItem('sidebarOpen','1');
    }
    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        document.body.classList.remove('sidebar-open');
        localStorage.setItem('sidebarOpen','0');
    }

    toggle.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    overlay.addEventListener('click', closeSidebar);

    // Restore state
    if (localStorage.getItem('sidebarOpen') === '1') openSidebar();
})();
</script>
