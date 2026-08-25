<?php
ob_start();
session_start();
include('../config/db.php');
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

/**
 * Canonical employee Excel layout.
 * The SAME column list is used by import_employees.php, so a file exported
 * here can be re-imported without any manual editing.
 * Header label => users table column
 */
require_once __DIR__ . '/employee_excel_format.php';
$columns = employee_excel_columns();

// Safety net: make sure every mapped column exists before selecting it
foreach ($columns as $db_col) {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE '$db_col'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN $db_col VARCHAR(255) DEFAULT NULL");
    }
}

// ── Optional filters coming from employees.php ──
$department  = trim($_GET['department']  ?? '');
$location    = trim($_GET['location']    ?? '');
$employee_id = trim($_GET['employee_id'] ?? ''); // 'asc' | 'desc' | '' (sort direction)

$where  = ["role = 'employee'"];
$params = [];
$types  = '';

if ($department !== '') { $where[] = "department = ?"; $params[] = $department; $types .= 's'; }
if ($location   !== '') { $where[] = "location = ?";   $params[] = $location;   $types .= 's'; }

$order = "location, department, name";
if (strtolower($employee_id) === 'asc')  $order = "employee_id ASC";
if (strtolower($employee_id) === 'desc') $order = "employee_id DESC";

$sql = "SELECT " . implode(', ', array_values($columns)) . "
        FROM users
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $order";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Employees');

// ── Header row ──
$col = 1;
foreach (array_keys($columns) as $header) {
    $sheet->setCellValueByColumnAndRow($col, 1, $header);
    $col++;
}
$lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
$sheet->getStyle("A1:{$lastColLetter}1")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => '212529']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFC107']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]],
]);
$sheet->getRowDimension(1)->setRowHeight(22);
$sheet->freezePane('A2');

$rowNum = 2;
while ($emp = $result->fetch_assoc()) {
    $col = 1;
    foreach ($columns as $header => $db_col) {
        $value = (string)($emp[$db_col] ?? '');
        // Values were stored with htmlspecialchars() — decode for a clean sheet
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        // MySQL zero-dates would come back as 0000-00-00 - export them as blank
        if ($value === '0000-00-00') { $value = ''; }
        // Everything is written as text so phone/Aadhar/account digits keep their exact form
        $sheet->getCellByColumnAndRow($col, $rowNum)->setValueExplicit($value, DataType::TYPE_STRING);
        $col++;
    }
    $rowNum++;
}

// Auto-size every column
for ($i = 1; $i <= count($columns); $i++) {
    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    $sheet->getColumnDimension($letter)->setAutoSize(true);
}
if ($rowNum > 2) {
    $sheet->getStyle("A2:{$lastColLetter}" . ($rowNum - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DEE2E6']]],
    ]);
}
$sheet->setAutoFilter("A1:{$lastColLetter}" . max(1, $rowNum - 1));

$filename = 'employees_' . date('Y-m-d_His') . '.xlsx';

ob_clean();
ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$stmt->close();
exit();
