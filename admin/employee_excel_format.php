<?php
/**
 * Single source of truth for the Employee Excel layout.
 *
 * Both admin/export_employees.php (writer) and admin/import_employees.php (reader)
 * use this list, so an exported file can be re-imported unchanged and every
 * column maps straight onto a `users` table column.
 *
 * @return array<string,string> Excel header label => users table column
 */
function employee_excel_columns(): array
{
    return [
        'Employee ID'           => 'employee_id',
        'Full Name'             => 'name',
        'Email'                 => 'email',
        'Phone Number'          => 'phone',
        'Family Member Name'    => 'family_member_name',
        'Family Contact Number' => 'alternate_number',
        'Gender'                => 'sex',
        'Project'               => 'department',
        'Company'               => 'company',
        'Aadhar Number'         => 'aadhar_number',
        'PAN Number'            => 'pan_number',
        'Shift Time'            => 'shift_time',
        'Location'              => 'location',
        'Date of Joining'       => 'date_of_joining',
        'Status'                => 'status',
        'Date of Exit'          => 'date_of_exit',
        'Week Off'              => 'week_off',
        'Address'               => 'address',
        'Bank Account Number'   => 'bank_account_number',
        'Bank IFSC Code'        => 'bank_ifsc_code',
    ];
}
