<?php
/**
 * The bank and salary columns bolted onto the right-hand side of every
 * attendance export, so all three sheets show the same four fields in the same
 * order and read the same figures.
 *
 * They sit after the last date column, which leaves the daily attendance grid
 * untouched. The salary amount is whatever config/salary_summary.php works out
 * for the pay month - gross, minus a per-day cut for each absence (a Sunday the
 * sandwich rule turned absent included), plus extra duty for a week off or
 * holiday actually worked, minus PF and ESI.
 */

require_once __DIR__ . '/salary_summary.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/** Column headings, in order, starting at the first free column. */
const EXPORT_SALARY_HEADINGS = ['BANK NAME', 'IFSC CODE', 'ACCOUNT NUMBER', 'SALARY AMOUNT'];

/**
 * Makes sure the bank columns exist before an export SELECTs them.
 *
 * admin/employees.php creates them, but an export can easily be the first page
 * opened after a deploy. Without this the SELECT fails with "Unknown column"
 * and the whole download 500s, so every export calls this first.
 */
function export_salary_ensure_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $needed = [
        'bank_name'           => 'VARCHAR(100)',
        'bank_ifsc_code'      => 'VARCHAR(15)',
        'bank_account_number' => 'VARCHAR(30)',
    ];
    foreach ($needed as $col => $type) {
        $res = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $col $type NULL");
        }
    }
}

/**
 * The 1-based column index the extra block starts at.
 *
 * @param int $num_dates how many date columns the sheet has
 */
function export_salary_first_col(int $num_dates): int
{
    // Column 1 is the row label, columns 2..(num_dates+1) are the dates
    return $num_dates + 2;
}

/**
 * Writes the four headings onto the given row.
 */
function export_salary_write_headers($sheet, int $row, int $num_dates): void
{
    $col = export_salary_first_col($num_dates);
    foreach (EXPORT_SALARY_HEADINGS as $heading) {
        $letter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($letter . $row, $heading);
        $sheet->getStyle($letter . $row)->applyFromArray(['font' => ['bold' => true]]);
        $sheet->getStyle($letter . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($letter . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getColumnDimension($letter)->setWidth($heading === 'BANK NAME' ? 22 : 18);
        $col++;
    }
}

/**
 * Writes one employee's bank details and salary amount onto their name row.
 *
 * @param array  $emp   users row - needs id, and the bank columns if present
 * @param string $month pay month as YYYY-MM
 */
function export_salary_write_row($sheet, int $row, int $num_dates, mysqli $conn, array $emp, string $month): void
{
    $summary = salary_month_summary($conn, (int)$emp['id'], $month);

    $values = [
        $emp['bank_name'] ?? '',
        $emp['bank_ifsc_code'] ?? '',
        // Long account numbers must not be shown as 1.23E+10, so keep them text
        (string)($emp['bank_account_number'] ?? ''),
        $summary['has_structure'] ? $summary['net_pay'] : '',
    ];

    $col = export_salary_first_col($num_dates);
    foreach ($values as $i => $value) {
        $letter = Coordinate::stringFromColumnIndex($col);
        if ($i === 2 && $value !== '') {
            $sheet->setCellValueExplicit(
                $letter . $row,
                $value,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
        } else {
            $sheet->setCellValue($letter . $row, $value);
        }
        $sheet->getStyle($letter . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        if ($i === 3) {
            $sheet->getStyle($letter . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ($value !== '') {
                $sheet->getStyle($letter . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            }
        }
        $col++;
    }
}

/**
 * The pay month an export covers, taken from the first date on the sheet.
 *
 * The attendance grid follows the dates the admin picked, while pay always
 * follows the 26th-to-25th cycle in config/pay_period.php, so the salary column
 * is labelled with its own period wherever the two can differ.
 */
function export_salary_month(array $date_range): string
{
    return $date_range ? date('Y-m', strtotime($date_range[0])) : date('Y-m');
}
