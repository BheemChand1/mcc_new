# MCC Portal — Project and Database Reference

This file is the working reference for future MCC Portal changes. The authoritative database schema is:

`database/mccbeatleanalyti_db.sql`

The SQL dump should be checked before adding or changing queries, reports, APIs, or dashboard calculations.

## Project overview

MCC is a PHP 8.3 / MySQL application for mechanized cleaning operations in railway coaching depots. It manages stations, users, trains, coaches, employees, attendance, manpower, chemicals, equipment, inspections, scorecards, penalties, billing, approvals, and dashboards.

Main application areas:

- `admin/` — administration and master-data management
- `editor/` — report and scorecard entry
- `auditor/` — audit/review workflows
- `cdo-dashboard/` — summaries, targets, approvals, penalties, billing, and management views
- `api/` — JSON APIs for mobile and application workflows
- `attendance-api/` — biometric attendance receiver endpoints
- `database/` — schema and setup/migration scripts
- `connection.php` — shared MySQLi and PDO connection bootstrap

## Database snapshot

- Database dump: `database/mccbeatleanalyti_db.sql`
- Tables: 100
- Foreign-key constraints: 91
- Database family: MySQL/MariaDB
- Most operational data is scoped by `station_id`.

## Core relationship model

```text
mcc_zones
  └── mcc_divisions
        └── mcc_stations
              ├── mcc_users
              ├── mcc_trains ─── mcc_coaches
              ├── employees / attendance
              ├── parameters / targets
              ├── daily reports
              └── dashboard summaries / billing / penalties
```

Common report flow:

```text
master/configuration → target → daily report → audit/approval → summary/dashboard
```

## Important shared columns

These columns recur throughout the schema:

| Column | Meaning |
|---|---|
| `id` | Table-local numeric primary key in most tables |
| `station_id` | Depot/station scope; filter this in station-specific queries |
| `report_date` | Date on which a report was performed |
| `target_date` | Date associated with a manpower target |
| `effective_from`, `effective_to` | Validity period for targets/configuration |
| `token_id` | Groups one report submission across parameter rows |
| `parameter_id` | Links a report row to a configured parameter |
| `shift_id` | Links a report row to a configured shift |
| `audit_by` | User ID of the auditor/reviewer where supported |
| `auditor_name` | Legacy/display auditor name stored on some reports |
| `isApproved` | Approval flag; commonly `0` pending and `1` approved |
| `created_at`, `updated_at` | Record timestamps |

Do not assume every table uses a real foreign-key constraint. Some legacy relationships are represented by indexed integer columns only.

## Table catalog

### Organization and master data

| Table | Main columns / purpose |
|---|---|
| `mcc_zones` | `zone_id`, zone name, status |
| `mcc_divisions` | `division_id`, `division_name`, `zone_id`, status |
| `mcc_stations` | `station_id`, `station_name`, `division_id`, contractor details |
| `mcc_trains` | `train_id`, `train_no`, `station_id`, status |
| `mcc_coaches` | `coach_id`, `train_id`, `coach_no`, display order, status |
| `mcc_parameters` | General report parameters |
| `mcc_sub_parameters` | Child parameters linked by `parameter_id` |
| `mcc_reports` | Report/master report definitions |
| `mcc_subreports` | Child report definitions |
| `mcc_station_reports` | Station-to-report configuration |
| `mcc_surprise_parameters` | Surprise inspection parameters |
| `mcc_surprise_ratings` | Surprise inspection ratings |
| `app_version` | Application version metadata |

### Users, employees, and access

| Table | Main columns / purpose |
|---|---|
| `mcc_users` | `user_id`, `username`, `user_name`, `email`, `role`, `station_id`, status, password hash |
| `mcc_employee` | `employee_id`, employee code/name, `station_id`, employment details |
| `mcc_employee_custom_fields` | Custom employee field definitions/values |
| `mcc_designation` | Station-specific designations |
| `mcc_manpower_types` | Manpower roles/types |
| `mcc_manpower_categories` | Manpower categories by station |

### Attendance

| Table | Main columns / purpose |
|---|---|
| `attendance_logs` | Employee/device punches: `employee_code`, `punch_time`, punch-in/out, device data |
| `attendance_logs_test` | Test copy of biometric attendance structure |
| `mcc_photo_attendance` | Photo attendance records |
| `mcc_photo_report` | Photo-attendance report records |
| `photo_attendace_proccess` | Photo-attendance processing state/data |

### Manpower

| Table | Main columns / purpose |
|---|---|
| `mcc_manpower_log` | Daily manpower by station/date/shift/type |
| `mcc_manpower_targets` | Manpower targets by station/month/type |
| `mcc_manpower_shifts` | Manpower shift master |
| `mcc_manpower_shift_type_map` | Shift-to-manpower-type mapping |
| `mcc_manpower_penalties` | Manpower penalty rules/results |
| `biometric_manpower_target` | Biometric manpower targets with category/designation/type |
| `mcc_biometric_manpower_types` | Biometric manpower role master |

### Chemical inventory and usage

| Table | Main columns / purpose |
|---|---|
| `mcc_chemical_types` | Chemical categories: type name/code/status |
| `mcc_chemical_units` | Volume/weight/count units and conversion values |
| `mcc_chemical_param` | Station chemical parameters and base unit |
| `mcc_chemical_param_type_map` | Maps parameters to chemical types |
| `mcc_chemical_stock` | Current stock by `station_id` and `parameter_id` |
| `mcc_chemical_stock_log` | ADD/SET stock movements and remarks |
| `mcc_chemical_target` | Chemical quantity and penalty targets |
| `mcc_chemical_report` | Chemical usage by train/coach/shift/date |

Important chemical columns include `qty_used`, `stock_quantity`, `quantity`, `qty(ml)`, `penalty`, and `penalty_qty(ml)`. Preserve the existing column names when writing compatibility queries.

### Equipment and machine reports

Equipment master/log tables:

- `mcc_equipment_master`
- `mcc_equipment_daily_log`

Each machine family generally has four tables: parameters, shifts, targets, and reports.

| Family | Tables |
|---|---|
| Normal | `mcc_normal_machine_param`, `mcc_normal_machine_shifts`, `mcc_normal_machine_target`, `mcc_normal_machine_report` |
| Intensive | `mcc_intensive_machine_param`, `mcc_intensive_machine_shifts`, `mcc_intensive_machine_target`, `mcc_intensive_machine_report` |
| Intensive pantry | `mcc_intensive_pantry_machine_param`, `mcc_intensive_pantry_machine_shifts`, `mcc_intensive_pantry_machine_target`, `mcc_intensive_pantry_machine_report` |
| Platform return | `mcc_prt_machine_param`, `mcc_prt_machine_shifts`, `mcc_prt_machine_target`, `mcc_prt_machine_report` |
| Vande Bharat | `mcc_vb_machine_param`, `mcc_vb_machine_shifts`, `mcc_vb_machine_target`, `mcc_vb_machine_report` |
| DC | `dc_mcc_machine_param`, `dc_mcc_machine_shifts`, `dc_mcc_machine_target`, `dc_mcc_machine_report` |

Machine report rows commonly use `parameter_id`, `shift_id`, `station_id`, `report_date`, `token_id`, `used_status`, `isApproved`, and `audit_by`.

### Scorecards and inspections

| Family | Parameter/rating/report tables |
|---|---|
| Normal | `mcc_normal_scorecard_param`, `mcc_normal_scorecard_sub_param`, `mcc_normal_rating`, `mcc_normal_scorecard_report` |
| Intensive | `mcc_intensive_scorecard_param`, `mcc_intensive_scorecard_sub_param`, `mcc_intensive_rating`, `mcc_intensive_scorecard_report` |
| Intensive scorecard 2 | `mcc_intensive_scorecard_2_param`, `mcc_intensive_scorecard_2_sub_param`, `mcc_intensive_scorecard_2_rating`, `mcc_intensive_scorecard_2_report` |
| Intensive pantry | `mcc_intensive_pantry_param`, `mcc_intensive_pantry_sub_param`, `mcc_intensive_pantry_rating`, `mcc_intensive_pantry_report` |
| Platform return | `mcc_prt_scorecard_param`, `mcc_prt_scorecard_sub_param`, `mcc_prt_rating`, `mcc_prt_scorecard_report` |
| Vande Bharat | `mcc_vb_scorecard_param`, `mcc_vb_scorecard_sub_param`, `mcc_vb_rating`, `mcc_vb_scorecard_report` |
| DC | `dc_mcc_param`, `dc_mcc_rating`, `dc_mcc_report` |
| Surprise | `mcc_surprise_parameters`, `mcc_surprise_ratings`, `mcc_surprise_reports` |
| Cleanliness | `mcc_cleanliness_report` |

Scorecard report tables generally store a parameter/sub-parameter result, `station_id`, `report_date`, `token_id`, approval state, and audit information.

### Billing and penalties

| Table | Main columns / purpose |
|---|---|
| `mcc_billing_setup` | Station contract amount, GST, agreement and contract dates |
| `mcc_billing_conditions` | Score ranges and deduction percentages |
| `mcc_billing_earnings` | Billing earning components and weightage |
| `mcc_imposed_penalties` | Penalties imposed against operational results |

## Development rules for future changes

1. Read `database/mccbeatleanalyti_db.sql` before changing a query or adding a table.
2. Scope station-specific reads and writes with `station_id`.
3. Use prepared statements through PDO for new database operations.
4. Preserve existing report grouping through `token_id`.
5. Preserve approval fields (`isApproved`, `audit_by`) in report workflows.
6. Use `parameter_id`, `sub_parameter_id`, `shift_id`, and `train_id` according to the table family; do not substitute similarly named IDs casually.
7. Check whether a table is legacy/test data before using it in a new feature.
8. Add indexes for new filters involving station, date, token, parameter, and status.
9. Avoid exposing database errors or credentials in web responses.
10. Update this README when tables, columns, relationships, or report families change.

## Known schema inconsistencies

- Naming varies between `isApproved`, snake_case, and camelCase.
- `qty(ml)` and `penalty_qty(ml)` contain parentheses in the column name and require backticks in SQL.
- Some tables store both IDs and display names, such as `audit_by` and `auditor_name`.
- `attendance_logs_test` is a test table and should not be used for production reporting without confirmation.
- Several module families duplicate the same conceptual structure with different prefixes.

## Security note

Database credentials must not be committed to source control or exposed in web-accessible files. The current connection configuration should be moved to protected environment/server configuration and the existing credentials should be rotated.

