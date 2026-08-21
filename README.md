# Attendance & HR Management System

A comprehensive web-based attendance and HR management system with face recognition, GPS geofencing, leave/comp-off/OD workflows, and salary slip generation. Built with PHP, MySQL, and Face-api.js.

---

## 🎯 Project Overview

This system provides:
- **Face Recognition Attendance**: Automatic punch in/out via browser camera
- **GPS Geofencing**: Restrict punch in/out to within a configurable radius of the office
- **Location Tracking**: Records punch in/out locations and live route summaries
- **Multiple Punch Records**: Support for multiple punch in/out sessions per day (first punch-in + last punch-out treated as one day's attendance)
- **Employee Management**: Add, edit, delete employees with conditional fields, bulk import, and photo management
- **Leave, Comp-Off & On-Duty (OD)**: Employee leave applications with admin approval, comp-off requests, and OD marking — all feed into payroll as paid days
- **Salary Slips**: Auto-generated payslips with CTC/Basic/Allowance breakdown, PF/ESI deductions, and loss-of-pay proration based on attendance
- **Monthly Reports**: Paginated, exportable attendance data with first/last punch times
- **Role-Based Access**: SuperAdmin, Admin, Face Operator, and Employee roles
- **Dashboard Views**: Multiple dashboards based on user role

---

## ✨ Key Features

### **Employee Management**
- ✅ Add/Edit/Delete employees, bulk import via `admin/import_employees.php`
- ✅ Manage employee photos for face recognition
- ✅ Conditional fields (Date of Exit shows only when Status = "Resign")
- ✅ Password management & reset for employees (`admin/manage_passwords.php`, `admin/reset_password.php`)
- ✅ Search and filter by department

### **Face Attendance System**
- ✅ Real-time face detection using face-api.js
- ✅ Automatic punch in/out on face recognition
- ✅ Manage face operators and face-attendance logins (`admin/manage_face_operators.php`, `admin/manage_face_attendance_logins.php`)
- ✅ Records selfies for audit trail

### **Attendance Recording**
- ✅ Punch in/out with selfie capture and server-authoritative timestamps (client-submitted times are rejected)
- ✅ GPS geofencing: punches outside the configured office radius are blocked when enabled per employee
- ✅ Multiple punch sessions per day, each stored as its own record; punch-out always closes the most recent open session
- ✅ Location tracking with route/distance summary between punch in and punch out

### **Leave, Comp-Off & On-Duty**
- ✅ Employees submit leave applications (`employee/leave_application.php`); admins review/approve (`admin/leave_management.php`)
- ✅ Comp-off requests for working on days off (`admin/comp_off_management.php`)
- ✅ On-Duty (OD) marking for off-site work (`admin/od_management.php`)
- ✅ Approved leave (except Unpaid Leave), OD days, and comp-off adjusted days all count as paid days in salary calculations

### **Salary Slips**
- ✅ Per-employee salary structure: CTC, Basic, Special Allowance, PF, ESI, and custom components (`admin/salary_slip.php`)
- ✅ Paid Days computed per month from actual attendance, week-offs, approved leave, OD, and comp-off — deduplicated by calendar date
- ✅ Loss-of-pay deduction prorated from **gross earnings** (Basic + Allowance + custom components) divided by days in month, not CTC (CTC includes PF/ESI, which are deducted separately)
- ✅ Print and PDF export of generated slips

### **Attendance Display & Export**
- ✅ WebPage View: Shows all punch records with locations visible
- ✅ Excel Export: First Punch In + Last Punch Out summary, paginated by department with collapsible sections
- ✅ Date-range selection and batch-loaded OD/comp-off data for performance
- ✅ Filter by date range, employee, department

### **Dashboards**
- ✅ SuperAdmin Dashboard: Full system overview with rights management
- ✅ Admin Dashboard: Employee, attendance, leave, and payroll management
- ✅ Face Operator Dashboard: Today's face attendance records
- ✅ Employee Dashboard: Personal punch in/out, attendance history, and leave applications

---

## 📁 Project Structure

```
attendance/
├── admin/
│   ├── admin_dashboard.php          # Admin overview dashboard
│   ├── admin.php                    # Admin panel / rights management
│   ├── attendance.php               # View all attendance records
│   ├── dashboard.php                # SuperAdmin main dashboard
│   ├── employees.php                # Manage employees (with modal edit)
│   ├── import_employees.php         # Bulk employee import
│   ├── companies.php                # Manage companies
│   ├── department.php               # Manage departments
│   ├── shifts.php                   # Manage shifts
│   ├── locations.php                # Manage locations
│   ├── geo_restriction.php          # GPS geofencing configuration
│   ├── leave_management.php         # Review/approve employee leave applications
│   ├── comp_off_management.php      # Comp-off requests
│   ├── od_management.php            # On-Duty (OD) marking
│   ├── salary_slip.php              # Salary structure + payslip generation
│   ├── manage_employee_photos.php   # Handle employee photos
│   ├── manage_passwords.php         # Employee password management
│   ├── manage_face_operators.php    # Face operator accounts
│   ├── export_monthly.php           # Export monthly reports
│   └── face_recognition_dashboard.php # Face operator dashboard
│
├── api/
│   ├── record_attendance.php        # Punch in/out recording (with browser_time)
│   ├── get_employees.php            # Employee list API
│   ├── get_attendance_summary.php   # Attendance summary API
│   ├── upload_employee_photo.php    # Photo upload API
│   └── delete_employee_photo.php    # Photo delete API
│
├── auth/
│   ├── login.php                    # Login page
│   ├── login_process.php            # Process login
│   ├── password_reset.php           # Self-service password reset
│   └── logout.php                   # Logout handler
│
├── config/
│   ├── db.php                       # Database connection
│   ├── AttendanceProcessor.php      # Shared attendance processing logic
│   └── db_migration.php             # Database schema migrations
│
├── employee/
│   ├── dashboard.php                # Employee dashboard
│   ├── punch_in.php                 # Punch in with selfie + GPS
│   ├── punch_out.php                # Punch out with selfie + GPS
│   ├── leave_application.php        # Submit leave requests
│   └── my_attendance.php            # Personal attendance view
│
├── uploads/
│   ├── employee_photos/             # Employee face photos
│   ├── employee_faces/              # Face descriptors
│   └── selfies/YYYY/MM/DD/          # Punch in/out selfies (date-partitioned)
│
├── assets/
│   ├── css/                         # Stylesheets
│   └── js/                          # JavaScript functionality
│
├── auto_face_attendance.php         # Main face detection page
├── face_dashboard.php               # Face attendance dashboard
├── user_dashboard.php               # User dashboard
├── index.php                        # Home page / redirects
└── README.md                        # This file
```

---

## 🗄️ Database Schema (key tables)

### **Users**
```sql
id, name, email, password, role (employee/admin/suparadmin/face_operator),
department, employee_id, company, phone, shift_time, location,
date_of_joining, date_of_exit, status (Working/Resign),
sex (Male/Female/Other), week_off, geo_restricted, password_set, created_at
```

### **Attendance**
```sql
id, user_id, date (DATE), punch_in (TIME), punch_out (TIME),
punch_in_location, punch_out_location, status,
selfie_punchin, selfie_punchout, created_at
```

### **Leave Applications**
```sql
id, user_id, leave_type (Casual/Sick/Earned/Maternity/Paternity/Unpaid),
start_date, end_date, days_count, reason, status (Pending/Approved/Rejected),
admin_notes, reviewed_by, reviewed_at, created_at
```

### **Salary Structures**
```sql
id, user_id, template, statutory_component, effective_cycle, salary_ctc,
basic_monthly, special_allowance_monthly, pf_monthly, esi_monthly,
pf_calc, esi_calc, custom_components (JSON)
```

### **Other Tables**
- `od_records` — On-Duty dates per employee
- `comp_off_requests` — Comp-off adjusted dates
- `office_settings` — Office GPS coordinates + allowed radius
- `location_tracking`, `route_summary` — Punch session GPS trail
- `departments`, `companies`, `shifts`, `locations`

---

## 🚀 Installation & Setup

1. **Configure**: Edit `config/db.php` with your database credentials
2. **Initialize**: Run `admin/initialize_database.php` to create required tables
3. **Create Admin**: Run `create_superadmin.php`
4. **Access**: `http://localhost/attendence/`
5. **Login**: Use the SuperAdmin credentials created above

---

## 👥 User Roles & Permissions

- **SuperAdmin**: Full system control, including salary slips and rights management
- **Admin**: Employee, attendance, leave, comp-off, and OD management
- **Face Operator**: Today's face attendance only
- **Employee**: Personal punch in/out, attendance history, and leave applications

---

## 💻 Usage Guide

### **Employee Management** (Admin)
1. Go to Admin → Employees
2. Click **➕ Add New Employee** or import a batch via **Import Employees**
3. Fill form (mandatory: Name, ID, Phone, Department, Company, Shift, Location, Date of Joining, Status)
4. If Status = "Resign": Date of Exit field appears (mandatory)
5. To Edit: Click **Edit** → Modal opens → Edit → **✓ Update** → Auto-closes

### **Manual Punch In/Out** (Employee)
1. Go to **Employee Dashboard**
2. Click **Punch In** → capture a selfie and allow GPS (if geo-restriction is enabled for you)
3. Later, click **Punch Out** → this closes your most recent open punch-in session

### **Leave / Comp-Off / OD**
1. Employee submits a leave request from **Leave Application**
2. Admin reviews and approves/rejects from **Leave Management**
3. Comp-off and OD are recorded by Admin and automatically count as paid days

### **Salary Slip Generation** (SuperAdmin)
1. Go to **Salary Slip**, search for the employee, pick a month
2. Click **Generate Slip** — Paid Days are computed from attendance + week-offs + approved leave/OD/comp-off for that month, and loss-of-pay is prorated from gross earnings
3. **Print** or **Download PDF**

### **Export Reports**
- Click **Export Excel** on the monthly export page
- Format: First Punch In + Last Punch Out, grouped by department

---

## 🔧 Key Technical Details

### **Server-Authoritative Timestamps**
- Punch in/out always use server time; any client-submitted time parameter is rejected to prevent device-clock tampering.

### **Multiple Punch Sessions Per Day**
- Each punch-in creates a new attendance row for that date; punch-out updates the most recent row for that date with `punch_out IS NULL` (`ORDER BY id DESC LIMIT 1`).
- For payroll purposes, all sessions on the same date are deduplicated to a single paid day.

### **GPS Geofencing**
- When `geo_restricted` is enabled for a user, punch in/out is only allowed within the configured radius (`office_settings.radius_meters`) of the office coordinates, using the Haversine formula.

### **Salary Slip Proration**
- Paid Days = week-off days + present/late days + OD days + comp-off adjusted days + approved paid leave days, deduplicated by calendar date.
- Loss-of-pay per day = (Basic + Allowance + custom components) ÷ days in month — **not** CTC, since CTC already includes PF/ESI which are deducted as separate line items.

---

## 🔒 Security

- ✅ Prepared statements (SQL injection prevention)
- ✅ Bcrypt password hashing
- ✅ Session-based authentication
- ✅ Role-based access control
- ✅ Server-side time validation (rejects client-submitted punch times)
- ✅ Input sanitization

---

## 📝 Recent Updates (August 2026)

- ✅ Added Leave Application & Leave Management modules
- ✅ Added Salary Slip generation with CTC/Basic/Allowance/PF/ESI breakdown
- ✅ Fixed loss-of-pay proration to use gross earnings instead of CTC
- ✅ Added GPS attendance geofencing
- ✅ Added collapsible department sections and employee avatars to monthly export
- ✅ Added pagination and batch loading for monthly export performance
- ✅ Enhanced admin dashboard with rights management and password management

---

## 📞 Requirements

- PHP 7.4+
- MySQL 5.7+
- Apache/XAMPP
- Modern browser (Chrome, Firefox, Edge, Safari)
- Camera and location access for face attendance and geofencing

---

**Last Updated**: August 2026 | **Status**: ✅ Fully Operational
