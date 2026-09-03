<?php
session_start();

// Increase timeout and memory for large exports (400+ employees)
set_time_limit(600); // 10 minutes
ini_set('memory_limit', '512M');

include('../config/db.php');

require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Bank + salary columns shared by every attendance export
require_once __DIR__ . '/../config/export_salary_columns.php';

// Authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

// Get month and year from POST, or from_date/to_date for date range
$month = null;
$year = null;
$from_date = null;
$to_date = null;
$date_range = array();
$month_name = '';

// Check if using new date range format
if (!empty($_POST['from_date']) && !empty($_POST['to_date'])) {
    $from_date = $_POST['from_date'];
    $to_date = $_POST['to_date'];
    $month_name = date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date));
    
    // Generate array of dates in range
    $current = new DateTime($from_date);
    $end = new DateTime($to_date);
    $end->modify('+1 day');
    while ($current < $end) {
        $date_range[] = $current->format('Y-m-d');
        $current->modify('+1 day');
    }
} elseif (!empty($_POST['month']) && !empty($_POST['year'])) {
    // Fallback to old format
    $month = intval($_POST['month']);
    $year = intval($_POST['year']);
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $month_name = date('F Y', mktime(0, 0, 0, $month, 1, $year));
    
    // Generate date range for entire month
    for ($day = 1; $day <= $days_in_month; $day++) {
        $date_range[] = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
    }
} else {
    header("Location: export_monthly.php");
    exit();
}

// Set days_in_month for column calculations
$days_in_month = count($date_range);

// Get selected company filter (if any)
$selected_companies = isset($_POST['companies']) && is_array($_POST['companies']) ? $_POST['companies'] : array();

// Build SQL query to fetch employees, optionally filtering by company
$query = "
    SELECT id, employee_id, name, department, location, week_off, shift_time, status, date_of_exit,
           bank_name, bank_ifsc_code, bank_account_number
    FROM users
    WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign')
";

if (count($selected_companies) > 0) {
    $placeholders = implode(',', array_fill(0, count($selected_companies), '?'));
    $query .= " AND company IN ($placeholders)";
}

$query .= " ORDER BY location, department, name";

$stmt = $conn->prepare($query);

// Bind parameters if companies were selected
if (count($selected_companies) > 0) {
    $types = str_repeat('s', count($selected_companies));
    $stmt->bind_param($types, ...$selected_companies);
}

$stmt->execute();
$result = $stmt->get_result();

// Convert date_range to DateTime objects for comparison
$from_date_obj = new DateTime($date_range[0]);
$to_date_obj = new DateTime($date_range[count($date_range) - 1]);

$employees_by_location = array();
while ($row = $result->fetch_assoc()) {
    $location = !empty($row['location']) ? $row['location'] : 'Unassigned';
    $dept = !empty($row['department']) ? $row['department'] : 'Unassigned';
    
    if (!isset($employees_by_location[$location])) {
        $employees_by_location[$location] = array();
    }
    if (!isset($employees_by_location[$location][$dept])) {
        $employees_by_location[$location][$dept] = array();
    }
    $employees_by_location[$location][$dept][] = $row;
}
$stmt->close();

// Batch load all data ONCE before processing any sheets
$all_employee_ids = [];
foreach ($employees_by_location as $location_employees) {
    foreach ($location_employees as $dept_employees) {
        foreach ($dept_employees as $emp) {
            $all_employee_ids[] = $emp['id'];
        }
    }
}

// Remove duplicates
$all_employee_ids = array_unique($all_employee_ids);

// Batch load attendance data
$attendance_cache = [];
if (!empty($all_employee_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_employee_ids), '?'));
    $date_start = $date_range[0];
    $date_end = $date_range[count($date_range) - 1];
    
    $query = "SELECT user_id, DATE(date) as att_date, status, punch_in, punch_out 
              FROM attendance 
              WHERE user_id IN ($placeholders) AND DATE(date) BETWEEN ? AND ?";
    $stmt = $conn->prepare($query);
    
    $types = str_repeat('i', count($all_employee_ids)) . 'ss';
    $params = array_merge($all_employee_ids, [$date_start, $date_end]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row_att = $result->fetch_assoc()) {
        $key = $row_att['user_id'] . '_' . $row_att['att_date'];
        if (!isset($attendance_cache[$key])) {
            $attendance_cache[$key] = ['status' => $row_att['status'], 'punch_in' => $row_att['punch_in'], 'punch_out' => $row_att['punch_out'], 'times' => []];
        }
        $attendance_cache[$key]['times'][] = ['punch_in' => $row_att['punch_in'], 'punch_out' => $row_att['punch_out']];
    }
    $stmt->close();
}

// Batch load OD records
$od_cache = [];
if (!empty($all_employee_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_employee_ids), '?'));
    $date_start = $date_range[0];
    $date_end = $date_range[count($date_range) - 1];
    
    $query = "SELECT user_id, od_date FROM od_records 
              WHERE user_id IN ($placeholders) AND od_date BETWEEN ? AND ?";
    $stmt = $conn->prepare($query);
    
    $types = str_repeat('i', count($all_employee_ids)) . 'ss';
    $params = array_merge($all_employee_ids, [$date_start, $date_end]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row_od = $result->fetch_assoc()) {
        $key = $row_od['user_id'] . '_' . $row_od['od_date'];
        $od_cache[$key] = true;
    }
    $stmt->close();
}

// Batch load comp off requests
$comp_off_cache = [];
if (!empty($all_employee_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_employee_ids), '?'));
    $date_start = $date_range[0];
    $date_end = $date_range[count($date_range) - 1];
    
    $query = "SELECT user_id, comp_off_date, earned_date FROM comp_off_requests 
              WHERE user_id IN ($placeholders) AND comp_off_date BETWEEN ? AND ?";
    $stmt = $conn->prepare($query);
    
    $types = str_repeat('i', count($all_employee_ids)) . 'ss';
    $params = array_merge($all_employee_ids, [$date_start, $date_end]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row_co = $result->fetch_assoc()) {
        $key = $row_co['user_id'] . '_' . $row_co['comp_off_date'];
        $comp_off_cache[$key] = $row_co['earned_date'];
    }
    $stmt->close();
}

// Helper function to check status for a date (using cached data)
// Status codes follow the company attendance policy (config/attendance_policy.php):
//   P = full day, HD = half day, A = absent, WO = week off, H = project holiday, OD = on duty, ADJ = comp off adjusted
// The punch times are already cached, so the policy is applied without extra queries.
function getStatusForDate($user_id, $date_str, $week_off, $attendance_cache, $od_cache, $comp_off_cache, $conn = null, $shift_time = null, $holiday_dates = []) {
    require_once __DIR__ . '/../config/attendance_policy.php';

    $day_name = date('l', strtotime($date_str));
    if ($week_off === $day_name) {
        return 'WO';
    }
    // A day the superadmin declared off for this whole project
    if (isset($holiday_dates[$date_str])) {
        return 'H';
    }

    $key = $user_id . '_' . $date_str;
    if (isset($od_cache[$key])) {
        return 'OD';
    }
    $has_comp_off = isset($comp_off_cache[$key]);

    if (isset($attendance_cache[$key])) {
        $policy       = attendance_policy($conn);
        $fullDayHours = attendance_full_day_hours($policy, $shift_time);
        $result       = attendance_evaluate_day($attendance_cache[$key]['times'], $policy, $fullDayHours);

        if ($result['status'] === 'Present')  return 'P';
        if ($result['status'] === 'Half Day') return 'HD';
        return $has_comp_off ? 'ADJ' : 'A';
    }

    return $has_comp_off ? 'ADJ' : 'A';
}

/**
 * Applies the sandwich rule to a row of status codes, keyed by date in order.
 *
 * This export builds its statuses from a prefetched cache one date at a time,
 * so it cannot see the neighbouring days the way attendance_day_results() does.
 * Re-checking the finished row keeps this sheet agreeing with the attendance
 * screens and the payslip: a WO flanked by A on both sides becomes A.
 *
 * A week off on the first or last day of the export range keeps its WO, since
 * the day outside the range was never fetched.
 */
function applySandwichToStatusRow(array $statuses, $conn) {
    require_once __DIR__ . '/../config/attendance_policy.php';
    $policy = attendance_policy($conn);
    if (!$policy['sandwich_absent']) {
        return $statuses;
    }

    $dates = array_keys($statuses);
    $n = count($dates);

    for ($i = 0; $i < $n; $i++) {
        if ($statuses[$dates[$i]] !== 'WO') {
            continue;
        }
        $j = $i;
        while ($j + 1 < $n && $statuses[$dates[$j + 1]] === 'WO') {
            $j++;
        }
        if ($i - 1 >= 0 && $j + 1 < $n
            && $statuses[$dates[$i - 1]] === 'A'
            && $statuses[$dates[$j + 1]] === 'A') {
            for ($k = $i; $k <= $j; $k++) {
                $statuses[$dates[$k]] = 'A';
            }
        }
        $i = $j;
    }
    return $statuses;
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

function calculateHours($punch_in, $punch_out) {
    if (!$punch_in || !$punch_out) {
        return '-';
    }
    
    try {
        $punch_in_time = DateTime::createFromFormat('Y-m-d H:i:s', $punch_in);
        if (!$punch_in_time && strpos($punch_in, ':') !== false) {
            $punch_in_time = DateTime::createFromFormat('H:i:s', $punch_in);
        }
        
        $punch_out_time = DateTime::createFromFormat('Y-m-d H:i:s', $punch_out);
        if (!$punch_out_time && strpos($punch_out, ':') !== false) {
            $punch_out_time = DateTime::createFromFormat('H:i:s', $punch_out);
        }
        
        if ($punch_in_time && $punch_out_time) {
            $diff = $punch_out_time->getTimestamp() - $punch_in_time->getTimestamp();
            if ($diff < 0) $diff += 86400; // overnight shift
            $hours = floor($diff / 3600);
            $minutes = floor(($diff % 3600) / 60);
            return sprintf("%02d:%02d", $hours, $minutes);
        }
    } catch (Exception $e) {
        return '-';
    }
    
    return '-';
}

// Create Spreadsheet
$spreadsheet = new Spreadsheet();

// Remove default sheet
$spreadsheet->removeSheetByIndex(0);

// Define styles (reusable)
$titleFont = new Font(['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']]);
$titleAlignment = new Alignment(['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true]);

$headerFont = new Font(['bold' => true, 'color' => ['rgb' => 'FFFFFF']]);
$headerAlignment = new Alignment(['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true]);

$deptFont = new Font(['bold' => true, 'size' => 12, 'color' => ['rgb' => '000000']]);
$deptAlignment = new Alignment(['horizontal' => 'left', 'vertical' => 'center']);

$labelFont = new Font(['bold' => true]);
$labelAlignment = new Alignment(['horizontal' => 'center', 'vertical' => 'center']);

$centerAlignment = new Alignment(['horizontal' => 'center', 'vertical' => 'center']);

// Function to create sheet for a location
function createLocationSheet($spreadsheet, $location, $employees_by_dept, $date_range, $days_in_month, $month_name, $titleFont, $titleAlignment, $centerAlignment, $conn, $attendance_cache, $od_cache, $comp_off_cache) {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle(substr($location, 0, 31)); // Excel sheet name max 31 chars

    // One sheet is one project, so its declared holidays are fetched once here
    require_once __DIR__ . '/../config/project_holiday.php';
    $holiday_dates = $date_range
        ? project_holidays_between($conn, (string)$location, reset($date_range), end($date_range))
        : [];

    // Get date range objects for comparison
    $from_date_obj = new DateTime($date_range[0]);
    $to_date_obj = new DateTime($date_range[count($date_range) - 1]);
    
    $row = 1;
    
    // Title
    $sheet->setCellValue('A' . $row, "Attendance Report - " . $location . " (" . $month_name . ")");
    $sheet->getStyle('A' . $row)->setFont($titleFont);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->mergeCells('A' . $row . ':' . Coordinate::stringFromColumnIndex($days_in_month + 1) . $row);
    $sheet->getRowDimension($row)->setRowHeight(25);
    $row += 2;
    
    // Days header row
    foreach ($date_range as $day_index => $date_str) {
        $formatted_date = date('d-D', strtotime($date_str));
        if ($day_index === 0) {
            $sheet->setCellValue('A' . $row, "");
            $sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '000000']]]);
        }
        $col = Coordinate::stringFromColumnIndex($day_index + 2);
        $sheet->setCellValue($col . $row, $formatted_date);
        $sheet->getStyle($col . $row)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '000000']]]);
        $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }
    export_salary_write_headers($sheet, $row, $days_in_month);
    $sheet->getRowDimension($row)->setRowHeight(20);
    $salary_month = export_salary_month($date_range);
    $row++;
    
    // Process each department in this location
    foreach ($employees_by_dept as $dept => $employees) {
        $sheet->setCellValue('A' . $row, "DEPARTMENT: " . $dept);
        $sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true, 'size' => 12]]);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('A' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;
        
        // Process each employee
        foreach ($employees as $emp) {
            // Check if employee has resigned and if report period is after resignation
            $emp_resigned = ($emp['status'] === 'Resign' && !empty($emp['date_of_exit']));
            $resign_month_end = null;
            if ($emp_resigned) {
                // Get the last day of the resignation month
                $resign_date = new DateTime($emp['date_of_exit']);
                $resign_month_end = clone $resign_date;
                $resign_month_end->modify('last day of this month');
                // If employee resigned before the report period, skip them
                if ($resign_month_end < $from_date_obj) {
                    continue;
                }
            }
            
            $emp_start_row = $row;
            
            // Employee name row, with their bank details and salary on the right
            $sheet->setCellValue('A' . $row, $emp['employee_id'] . " - " . $emp['name']);
            $sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true, 'size' => 11]]);
            export_salary_write_row($sheet, $row, $days_in_month, $conn, $emp, $salary_month);
            $row++;
            
            // Status row
            $sheet->setCellValue('A' . $row, "Status");
            $sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);
            
            // Build the whole row first, so the sandwich rule can see each day's neighbours
            $emp_statuses = [];
            foreach ($date_range as $date_str) {
                // Check if this date is after resignation month
                if ($emp_resigned && new DateTime($date_str) > $resign_month_end) {
                    $emp_statuses[$date_str] = '-';
                } else {
                    $emp_statuses[$date_str] = getStatusForDate($emp['id'], $date_str, $emp['week_off'], $attendance_cache, $od_cache, $comp_off_cache, $conn, $emp['shift_time'] ?? null, $holiday_dates);
                }
            }
            $emp_statuses = applySandwichToStatusRow($emp_statuses, $conn);

            foreach ($date_range as $day_index => $date_str) {
                $status = $emp_statuses[$date_str];
                $col = Coordinate::stringFromColumnIndex($day_index + 2);
                $sheet->setCellValue($col . $row, $status);
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            }
            $row++;
            
            // Punch In row
            $sheet->setCellValue('A' . $row, "In");
            $sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);
            
            foreach ($date_range as $day_index => $date_str) {
                // Check if this date is after resignation month
                if ($emp_resigned && new DateTime($date_str) > $resign_month_end) {
                    $punch_in = '-';
                } else {
                    $key = $emp['id'] . '_' . $date_str;
                    if (isset($attendance_cache[$key]) && !empty($attendance_cache[$key]['times'])) {
                        $punch_in = extractTimeFromTimestamp($attendance_cache[$key]['times'][0]['punch_in']);
                    } else {
                        $punch_in = '-';
                    }
                }
                $col = Coordinate::stringFromColumnIndex($day_index + 2);
                $sheet->setCellValue($col . $row, $punch_in);
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            }
            $row++;
            
            // Punch Out row
            $sheet->setCellValue('A' . $row, "Out");
            $sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);
            
            foreach ($date_range as $day_index => $date_str) {
                // Check if this date is after resignation month
                if ($emp_resigned && new DateTime($date_str) > $resign_month_end) {
                    $punch_out = '-';
                } else {
                    $key = $emp['id'] . '_' . $date_str;
                    if (isset($attendance_cache[$key]) && !empty($attendance_cache[$key]['times'])) {
                        $num_punches = count($attendance_cache[$key]['times']);
                        $punch_out = extractTimeFromTimestamp($attendance_cache[$key]['times'][$num_punches - 1]['punch_out']);
                    } else {
                        $punch_out = '-';
                    }
                }
                $col = Coordinate::stringFromColumnIndex($day_index + 2);
                $sheet->setCellValue($col . $row, $punch_out);
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            }
            $row++;
            
            // Total Hours row
            $sheet->setCellValue('A' . $row, "Total");
            $sheet->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);
            
            foreach ($date_range as $day_index => $date_str) {
                // Check if this date is after resignation month
                if ($emp_resigned && new DateTime($date_str) > $resign_month_end) {
                    $display = '-';
                } else {
                    $key = $emp['id'] . '_' . $date_str;
                    if (isset($comp_off_cache[$key])) {
                        $day_taken = date('d', strtotime($comp_off_cache[$key]));
                        $display = 'CO-' . $day_taken;
                    } elseif (isset($attendance_cache[$key]) && !empty($attendance_cache[$key]['times'])) {
                        $first_punch = $attendance_cache[$key]['times'][0]['punch_in'];
                        $last_punch = $attendance_cache[$key]['times'][count($attendance_cache[$key]['times']) - 1]['punch_out'];
                        $display = calculateHours($first_punch, $last_punch);
                    } else {
                        $display = '-';
                    }
                }
                $col = Coordinate::stringFromColumnIndex($day_index + 2);
                $sheet->setCellValue($col . $row, $display);
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle($col . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            }
            $row += 2; // Blank row between employees
        }
        
        $row++; // Blank row between departments
    }
    
    // Set column widths
    $sheet->getColumnDimension('A')->setWidth(25);
    foreach ($date_range as $day_index => $date_str) {
        $col = Coordinate::stringFromColumnIndex($day_index + 2);
        $sheet->getColumnDimension($col)->setWidth(7);
    }
    
    // Apply borders
    $usedRange = 'A1:' . Coordinate::stringFromColumnIndex($days_in_month + 1) . ($row - 1);
    $borders = $sheet->getStyle($usedRange)->getBorders();
    $borders->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

// Create a sheet for each location
foreach ($employees_by_location as $location => $employees_by_dept) {
    createLocationSheet($spreadsheet, $location, $employees_by_dept, $date_range, $days_in_month, $month_name, $titleFont, $titleAlignment, $centerAlignment, $conn, $attendance_cache, $od_cache, $comp_off_cache);
}

// Set filename with date range
$filename = 'Attendance_AllLocation_' . str_replace(' ', '_', str_replace(' to ', '-', $month_name)) . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, must-revalidate');
header('Pragma: public');

// Write to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();

