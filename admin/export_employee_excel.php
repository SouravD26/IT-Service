<?php
session_start();
include('../config/db.php');

require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Bank + salary columns shared by every attendance export
// Report a crash as a readable message rather than a blank 500
require_once __DIR__ . '/../config/export_errors.php';
export_report_errors('export_employee_excel.php');

require_once __DIR__ . '/../config/export_salary_columns.php';
// Create them if this export is the first page opened after a deploy
export_salary_ensure_columns($conn);

// Authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

// Get employee ID and date range or month/year
if (!isset($_POST['employee_id'])) {
    header("Location: export_monthly.php");
    exit();
}

$employee_id = intval($_POST['employee_id']);
$from_date = isset($_POST['from_date']) ? trim($_POST['from_date']) : '';
$to_date = isset($_POST['to_date']) ? trim($_POST['to_date']) : '';
$month = null;
$year = null;
$month_name = '';

// Handle date range format
if (!empty($from_date) && !empty($to_date)) {
    $from_date_obj = new DateTime($from_date);
    $to_date_obj = new DateTime($to_date);
    $month_name = $from_date_obj->format('d M Y') . ' to ' . $to_date_obj->format('d M Y');
} else {
    // Fallback to old month/year format
    $month = isset($_POST['month']) ? intval($_POST['month']) : date('m');
    $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
    $from_date = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
    $to_date = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
    $month_name = date('F Y', mktime(0, 0, 0, $month, 1, $year));
}

// Fetch employee details including date_of_exit for resignation check
$stmt = $conn->prepare("SELECT id, employee_id, name, department, week_off, status, date_of_exit, bank_name, bank_ifsc_code, bank_account_number FROM users WHERE id = ? AND role = 'employee' AND (status = 'Working' OR status = 'Resign')");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: export_monthly.php");
    exit();
}

$employee = $result->fetch_assoc();
$stmt->close();

// Check if employee has resigned and if export date range is after resignation
$employee_resigned = ($employee['status'] === 'Resign' && !empty($employee['date_of_exit']));
$resign_date = $employee_resigned ? new DateTime($employee['date_of_exit']) : null;
$resign_month_end = null;
if ($employee_resigned) {
    // Get the last day of the resignation month
    $resign_month_end = new DateTime($employee['date_of_exit']);
    $resign_month_end->modify('last day of this month');
}

// Get the date range in a proper format
$from_date_obj = new DateTime($from_date);
$to_date_obj = new DateTime($to_date);

// If employee resigned and the from_date is after their resignation month, don't export
if ($employee_resigned && $from_date_obj > $resign_month_end) {
    // Redirect back with message about resigned employee
    session_start();
    $_SESSION['message'] = "This employee resigned on " . $resign_date->format('Y-m-d') . ". Cannot export data after resignation month.";
    header("Location: export_monthly.php");
    exit();
}

// Helper function to check status for a specific date (new version using date string).
// Codes follow the company attendance policy (config/attendance_policy.php):
//   P = full day, HD = half day, A = absent, WO = week off, H = project holiday, OD = on duty, ADJ = comp off adjusted
function getStatusForDateByString($conn, $user_id, $date_str, $week_off) {
    require_once __DIR__ . '/../config/attendance_policy.php';

    $result = attendance_day_result($conn, (int)$user_id, $date_str);

    switch ($result['status']) {
        case 'Week Off': return 'WO';

        case 'Holiday':  return 'H';
        case 'OD':       return 'OD';
        case 'Comp Off': return 'ADJ';
        case 'Leave':    return 'L';
        case 'Present':  return 'P';
        case 'Half Day': return 'HD';
        default:         return 'A';
    }
}

// Helper function to check status for a date (old version for backward compatibility)
function getStatusForDate($conn, $user_id, $date, $week_off, $month, $year) {
    $date_obj = date('Y-m-d', mktime(0, 0, 0, $month, $date, $year));
    return getStatusForDateByString($conn, $user_id, $date_obj, $week_off);
}

function extractTimeFromTimestamp($timestamp) {
    if (!$timestamp) {
        return '-';
    }
    if (strlen($timestamp) === 8 && strpos($timestamp, ':') === 2) {
        return substr($timestamp, 0, 5);
    }
    if (strpos($timestamp, ' ') !== false) {
        return substr($timestamp, 11, 5);
    }
    try {
        $time_obj = DateTime::createFromFormat('Y-m-d H:i:s', $timestamp);
        if ($time_obj) {
            return $time_obj->format('H:i');
        }
    } catch (Exception $e) {
        // Continue
    }
    return (strlen($timestamp) >= 5) ? substr($timestamp, 0, 5) : '-';
}

// Get punch times by date string
function getPunchTimesByDateString($conn, $user_id, $date_str) {
    $stmt = $conn->prepare("
        SELECT 
            MIN(punch_in) as first_punch_in, 
            MAX(punch_out) as last_punch_out,
            MAX(CASE WHEN punch_in IS NOT NULL THEN punch_in_location ELSE NULL END) as punch_in_location,
            MAX(CASE WHEN punch_out IS NOT NULL THEN punch_out_location ELSE NULL END) as punch_out_location
        FROM attendance 
        WHERE user_id = ? AND DATE(date) = ?
    ");
    $stmt->bind_param("is", $user_id, $date_str);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row;
    }
    $stmt->close();
    return null;
}

// Old version for backward compatibility
function getPunchTimes($conn, $user_id, $date, $month, $year) {
    $date_obj = date('Y-m-d', mktime(0, 0, 0, $month, $date, $year));
    return getPunchTimesByDateString($conn, $user_id, $date_obj);
}

function calculateHours($punch_in, $punch_out) {
    if (!$punch_in || !$punch_out) {
        return '-';
    }
    $in = strtotime($punch_in);
    $out = strtotime($punch_out);
    $diff = $out - $in;
    
    // Handle overnight shifts (shift crosses midnight)
    // If punch_out is earlier than punch_in on the same day, add 24 hours
    if ($diff < 0) {
        $diff += 86400; // 24 hours in seconds
    }
    
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    $seconds = $diff % 60;
    return sprintf("%02d:%02d", $hours, $minutes);
}

function getCompOffDate($conn, $employee_id, $day, $month, $year) {
    $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
    return getCompOffDateByString($conn, $employee_id, $date);
}

function getCompOffDateByString($conn, $employee_id, $date_str) {
    $stmt = $conn->prepare("SELECT earned_date FROM comp_off_requests WHERE user_id = ? AND comp_off_date = ?");
    
    if ($stmt === false) {
        return false;
    }
    
    $stmt->bind_param("is", $employee_id, $date_str);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['earned_date'];
    }
    $stmt->close();
    return false;
}

function getFullLocationName($location_text, $conn) {
    if (empty($location_text) || $location_text === '-') {
        return '-';
    }
    
    if (is_numeric($location_text)) {
        $location_id = intval($location_text);
        $stmt = $conn->prepare("SELECT name FROM locations WHERE id = ?");
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['name'];
        }
        $stmt->close();
    }
    
    return $location_text;
}

// Create Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Attendance");

// Define styles
$titleFont = new Font(['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']]);

$headerFont = new Font(['bold' => true, 'color' => ['rgb' => 'FFFFFF']]);

$deptFont = new Font(['bold' => true, 'size' => 12, 'color' => ['rgb' => '000000']]);

$empFont = new Font(['bold' => true, 'size' => 11, 'color' => ['rgb' => '000000']]);

$labelFont = new Font(['bold' => true]);

$row = 1;

// Title
$sheet->setCellValue('A' . $row, "Attendance Report - " . $employee['name'] . " - " . $month_name);
$sheet->getStyle('A' . $row)->setFont($titleFont);

$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getStyle('A' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

// Generate array of all dates in the range
$date_range = array();
$current_date = clone $from_date_obj;
while ($current_date <= $to_date_obj) {
    $date_range[] = $current_date->format('Y-m-d');
    $current_date->modify('+1 day');
}
$num_dates = count($date_range);

$sheet->mergeCells('A' . $row . ':' . Coordinate::stringFromColumnIndex($num_dates + 1) . $row);
$sheet->getRowDimension($row)->setRowHeight(25);
$row += 2;

// Department header
$sheet->setCellValue('A' . $row, "DEPARTMENT: " . $employee['department']);
$sheet->getStyle('A' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 12]
]);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getStyle('A' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$sheet->getRowDimension($row)->setRowHeight(20);
$row++;

// Employee information
$sheet->setCellValue('A' . $row, $employee['employee_id'] . " - " . $employee['name']);
$sheet->getStyle('A' . $row)->applyFromArray([
    'font' => ['bold' => true, 'size' => 11]
]);

$sheet->getRowDimension($row)->setRowHeight(18);
$row += 2;

// Days header row (shown once, before all departments) - ONLY dates, no label
for ($day_idx = 0; $day_idx < $num_dates; $day_idx++) {
    $date_str = $date_range[$day_idx];
    $date_obj = new DateTime($date_str);
    // Format: "01-Sun"
    $formatted_date = $date_obj->format('d-D');
    if ($day_idx === 0) {
        $sheet->setCellValue('A' . $row, "");
        $sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '000000']]]);
    }
    $col = Coordinate::stringFromColumnIndex($day_idx + 2);
    $sheet->setCellValue($col . $row, $formatted_date);
    $sheet->getStyle($col . $row)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '000000']]]);
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}
export_salary_write_headers($sheet, $row, $num_dates);
$sheet->getRowDimension($row)->setRowHeight(20);
$row++;

// Status row, carrying this employee's bank details and salary on the right
$sheet->setCellValue('A' . $row, "Status");
$sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);
export_salary_write_row($sheet, $row, $num_dates, $conn, $employee, export_salary_month($date_range));

for ($day_idx = 0; $day_idx < $num_dates; $day_idx++) {
    $date_str = $date_range[$day_idx];
    
    // Check if this date is after resignation month
    if ($employee_resigned && new DateTime($date_str) > $resign_month_end) {
        $status = '-'; // Don't show data after resignation month
    } else {
        $status = getStatusForDateByString($conn, $employee['id'], $date_str, $employee['week_off']);
    }
    
    $col = Coordinate::stringFromColumnIndex($day_idx + 2);
    $sheet->setCellValue($col . $row, $status);
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}
$row++;

// Punch In row
$sheet->setCellValue('A' . $row, "In");
$sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);

for ($day_idx = 0; $day_idx < $num_dates; $day_idx++) {
    $date_str = $date_range[$day_idx];
    
    // Check if this date is after resignation month
    if ($employee_resigned && new DateTime($date_str) > $resign_month_end) {
        $punch_in = '-';
    } else {
        $times = getPunchTimesByDateString($conn, $employee['id'], $date_str);
        $punch_in = $times && !empty($times['first_punch_in']) ? extractTimeFromTimestamp($times['first_punch_in']) : '-';
    }
    
    $col = Coordinate::stringFromColumnIndex($day_idx + 2);
    $sheet->setCellValue($col . $row, $punch_in);
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}
$row++;

// Punch Out row
$sheet->setCellValue('A' . $row, "Out");
$sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);

for ($day_idx = 0; $day_idx < $num_dates; $day_idx++) {
    $date_str = $date_range[$day_idx];
    
    // Check if this date is after resignation month
    if ($employee_resigned && new DateTime($date_str) > $resign_month_end) {
        $punch_out = '-';
    } else {
        $times = getPunchTimesByDateString($conn, $employee['id'], $date_str);
        $punch_out = $times && !empty($times['last_punch_out']) ? extractTimeFromTimestamp($times['last_punch_out']) : '-';
    }
    
    $col = Coordinate::stringFromColumnIndex($day_idx + 2);
    $sheet->setCellValue($col . $row, $punch_out);
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}
$row++;

// Total Hours row
$sheet->setCellValue('A' . $row, "Total");
$sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);

for ($day_idx = 0; $day_idx < $num_dates; $day_idx++) {
    $date_str = $date_range[$day_idx];
    
    // Check if this date is after resignation month
    if ($employee_resigned && new DateTime($date_str) > $resign_month_end) {
        $display = '-';
    } else {
        // Check if comp off exists for this date
        $compOffDate = getCompOffDateByString($conn, $employee['id'], $date_str);
        if ($compOffDate) {
            $day_taken = date('d', strtotime($compOffDate));
            $display = 'CO-' . $day_taken;
        } else {
            $times = getPunchTimesByDateString($conn, $employee['id'], $date_str);
            $display = calculateHours($times['first_punch_in'] ?? null, $times['last_punch_out'] ?? null);
        }
    }
    
    $col = Coordinate::stringFromColumnIndex($day_idx + 2);
    $sheet->setCellValue($col . $row, $display);
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}
$row += 2;

// Punch In Location row
$sheet->setCellValue('A' . $row, "In Location");
$sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);

for ($day_idx = 0; $day_idx < $num_dates; $day_idx++) {
    $date_str = $date_range[$day_idx];
    
    // Check if this date is after resignation month
    if ($employee_resigned && new DateTime($date_str) > $resign_month_end) {
        $location = '-';
    } else {
        $times = getPunchTimesByDateString($conn, $employee['id'], $date_str);
        $location = ($times && !empty($times['punch_in_location'])) ? getFullLocationName($times['punch_in_location'], $conn) : '-';
    }
    
    $col = Coordinate::stringFromColumnIndex($day_idx + 2);
    $sheet->setCellValue($col . $row, $location);
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}
$row++;

// Punch Out Location row
$sheet->setCellValue('A' . $row, "Out Location");
$sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);

for ($day_idx = 0; $day_idx < $num_dates; $day_idx++) {
    $date_str = $date_range[$day_idx];
    
    // Check if this date is after resignation month
    if ($employee_resigned && new DateTime($date_str) > $resign_month_end) {
        $location = '-';
    } else {
        $times = getPunchTimesByDateString($conn, $employee['id'], $date_str);
        $location = ($times && !empty($times['punch_out_location'])) ? getFullLocationName($times['punch_out_location'], $conn) : '-';
    }
    
    $col = Coordinate::stringFromColumnIndex($day_idx + 2);
    $sheet->setCellValue($col . $row, $location);
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}

// Set column widths
$sheet->getColumnDimension('A')->setWidth(20);
for ($day_idx = 0; $day_idx < $num_dates; $day_idx++) {
    $col = Coordinate::stringFromColumnIndex($day_idx + 2);
    $sheet->getColumnDimension($col)->setWidth(7);
}

// Apply black borders to entire used range
$usedRange = 'A1:' . Coordinate::stringFromColumnIndex($num_dates + 1) . $row;
$borders = $sheet->getStyle($usedRange)->getBorders();
$borders->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Set filename
$filename = 'Attendance_' . str_replace(' ', '_', $employee['name']) . '_' . str_replace(' ', '_', $month_name) . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, must-revalidate');
header('Pragma: public');

// Write to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();



