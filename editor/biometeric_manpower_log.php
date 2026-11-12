<?php
/**
 * Editor Dashboard - Biometric Attendance Logs & Manpower Editor
 * Allows the Editor to view, filter, add, edit, and delete raw punch logs from `attendance_logs`.
 */
require_once 'auth.php';
global $pdo;

$pageTitle = 'Biometric Attendance Logs & Manpower Editor | MCC Editor';
$flashSuccess = $_SESSION['attendance_log_flash']['success'] ?? '';
$flashError = $_SESSION['attendance_log_flash']['error'] ?? '';
unset($_SESSION['attendance_log_flash']);

// The receiver stores paired punches; older installations only have raw logs.
$attendanceColumns = $pdo->query('SHOW COLUMNS FROM attendance_logs')->fetchAll(PDO::FETCH_COLUMN);
$hasPairedPunches = in_array('punchin_time', $attendanceColumns, true)
    && in_array('punchout_time', $attendanceColumns, true);

// Handle POST actions: Add, Edit, Delete attendance logs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_log') {
        $logId = intval($_POST['log_id'] ?? 0);
        $empCode = trim($_POST['employee_code'] ?? '');
        $empName = trim($_POST['employee_name'] ?? '');
        $punchInTime = trim($_POST['punchin_time'] ?? '');
        $punchOutTime = trim($_POST['punchout_time'] ?? '');
        $inLogId = intval($_POST['in_log_id'] ?? 0);
        $outLogId = intval($_POST['out_log_id'] ?? 0);
        $deviceName = trim($_POST['device_name'] ?? 'Coaching');
        $deviceName = in_array(strtolower($deviceName), ['coaching', 'prt', 'pftr'], true)
            ? (in_array(strtolower($deviceName), ['prt', 'pftr'], true) ? 'PFTR' : 'Coaching')
            : 'Coaching';
        // Entries edited from this page are manual entries; do not trust a client-supplied mode.
        $verMode = 'Manual';

        if ($logId > 0 && $empCode !== '' && ($punchInTime !== '' || $punchOutTime !== '')) {
            try {
                $entries = [];
                foreach ([[$inLogId, $punchInTime, '0'], [$outLogId, $punchOutTime, '1']] as [$entryId, $time, $direction]) {
                    if ($time === '') {
                        if ($entryId > 0) {
                            throw new Exception('Existing punch times cannot be cleared. Enter a valid date and time.');
                        }
                        continue;
                    }
                    $parsedTime = DateTime::createFromFormat('!Y-m-d\TH:i:s', $time)
                        ?: DateTime::createFromFormat('!Y-m-d\TH:i', $time);
                    if (!$parsedTime || !in_array($time, [$parsedTime->format('Y-m-d\TH:i:s'), $parsedTime->format('Y-m-d\TH:i')], true)) {
                        throw new Exception('Please enter valid punch dates and times.');
                    }
                    $entries[] = ['id' => $entryId, 'time' => $parsedTime->format('Y-m-d H:i:s'), 'direction' => $direction];
                }
                $pdo->beginTransaction();
                $pairedSelect = $hasPairedPunches ? ', punchin_time, punchout_time' : '';
                $checkStmt = $pdo->prepare('SELECT id, employee_code, punch_time, direction' . $pairedSelect . ' FROM attendance_logs WHERE id = :id FOR UPDATE');
                $checkStmt->execute(['id' => $logId]);
                $baseLog = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if (!$baseLog || !in_array($logId, [$inLogId, $outLogId], true)) {
                    throw new Exception('Invalid attendance log selection. Refresh the page and try again.');
                }
                $isPairedLog = !empty($baseLog['punchin_time']) || !empty($baseLog['punchout_time']);
                $baseDate = substr($baseLog['punchin_time'] ?? $baseLog['punch_time'], 0, 10);
                $pairedUpdates = [];
                foreach ($entries as $entry) {
                    $entryId = $entry['id'];
                    // Fill a pending punch on its receiver record, rather than inserting a raw log.
                    if ($entryId <= 0 && $isPairedLog) $entryId = $logId;
                    $values = [
                        'emp_code' => $empCode, 'emp_name' => $empName,
                        'punch_time' => $entry['time'], 'direction' => $entry['direction'],
                        'device_name' => $deviceName, 'ver_mode' => $verMode,
                    ];
                    if ($entryId <= 0) {
                        $values['device_id'] = 'DEV_' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $deviceName), 0, 8));
                        $insStmt = $pdo->prepare('INSERT INTO attendance_logs (employee_code, employee_name, punch_time, direction, device_name, verification_mode, device_id) VALUES (:emp_code, :emp_name, :punch_time, :direction, :device_name, :ver_mode, :device_id)');
                        $insStmt->execute($values);
                        continue;
                    }
                    $checkStmt->execute(['id' => $entryId]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    $existingPaired = $existing && (!empty($existing['punchin_time']) || !empty($existing['punchout_time']));
                    $existingIsIn = $existing && in_array(strtolower(trim((string)$existing['direction'])), ['0', 'in', 'checkin'], true);
                    $field = $entry['direction'] === '0' ? 'punchin_time' : 'punchout_time';
                    if (!$existing || $existing['employee_code'] !== $baseLog['employee_code']
                        || substr($existing['punchin_time'] ?? $existing['punch_time'], 0, 10) !== $baseDate
                        || (!$existingPaired && $existingIsIn !== ($entry['direction'] === '0'))
                        || ($existingPaired && $entry['id'] > 0 && empty($existing[$field]))) {
                        throw new Exception('The selected punches do not belong to the same attendance row.');
                    }
                    if ($existingPaired) {
                        if (!isset($pairedUpdates[$entryId])) $pairedUpdates[$entryId] = $existing;
                        $pairedUpdates[$entryId][$field] = $entry['time'];
                    } else {
                        $values['id'] = $entryId;
                        $updStmt = $pdo->prepare('UPDATE attendance_logs SET employee_code = :emp_code,
                            employee_name = :emp_name, punch_time = :punch_time, direction = :direction,
                            device_name = :device_name, verification_mode = :ver_mode WHERE id = :id');
                        $updStmt->execute($values);
                    }
                }
                foreach ($pairedUpdates as $entryId => $record) {
                    $updStmt = $pdo->prepare('UPDATE attendance_logs SET employee_code = :emp_code,
                        employee_name = :emp_name, punch_time = :punch_time,
                        punchin_time = :in_time, punchout_time = :out_time,
                        device_name = :device_name, verification_mode = :ver_mode WHERE id = :id');
                    $updStmt->execute([
                        'emp_code' => $empCode, 'emp_name' => $empName,
                        'punch_time' => $record['punchin_time'] ?: $record['punch_time'],
                        'in_time' => $record['punchin_time'], 'out_time' => $record['punchout_time'],
                        'device_name' => $deviceName, 'ver_mode' => $verMode, 'id' => $entryId,
                    ]);
                }
                $pdo->commit();

                $flashSuccess = "Attendance log entry #{$logId} for Employee [{$empCode} - {$empName}] updated successfully!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flashError = "Failed to update attendance log: " . $e->getMessage();
            }
        } else {
            $flashError = "Please provide Employee ID and at least one punch-in or punch-out date and time.";
        }
    } elseif ($action === 'add_log') {
        $empCode = trim($_POST['employee_code'] ?? '');
        $empName = trim($_POST['employee_name'] ?? '');
        $punchTime = trim($_POST['punch_time'] ?? '');
        $punchTimeOut = trim($_POST['punch_time_out'] ?? '');
        $deviceName = trim($_POST['device_name'] ?? 'Coaching');
        $deviceName = in_array(strtolower($deviceName), ['coaching', 'prt', 'pftr'], true)
            ? (in_array(strtolower($deviceName), ['prt', 'pftr'], true) ? 'PFTR' : 'Coaching')
            : 'Coaching';
        // Entries created from this page are manual entries; do not trust a client-supplied mode.
        $verMode = 'Manual';

        if ($empCode !== '' && $punchTime !== '') {
            try {
                $employeeStmt = $pdo->prepare('SELECT employee_id, full_name FROM mcc_employee WHERE LOWER(employee_id) = LOWER(:employee_id) LIMIT 1');
                $employeeStmt->execute(['employee_id' => $empCode]);
                $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
                if ($employee) {
                    $empCode = $employee['employee_id'];
                    $empName = $employee['full_name'];
                }
                $entries = [];
                foreach ([[$punchTime, '0'], [$punchTimeOut, '1']] as [$time, $direction]) {
                    if ($time === '') continue;
                    $parsedTime = DateTime::createFromFormat('!Y-m-d\TH:i:s', $time)
                        ?: DateTime::createFromFormat('!Y-m-d\TH:i', $time);
                    if (!$parsedTime || !in_array($time, [$parsedTime->format('Y-m-d\TH:i:s'), $parsedTime->format('Y-m-d\TH:i')], true)) {
                        throw new Exception('Please enter valid IN and OUT dates and times.');
                    }
                    $entries[] = ['time' => $parsedTime->format('Y-m-d H:i:s'), 'direction' => $direction];
                }
                $pdo->beginTransaction();
                if ($hasPairedPunches) {
                    // One attendance record contains both punches, as in the receiver.
                    $times = array_column($entries, 'time', 'direction');
                    $insStmt = $pdo->prepare('INSERT INTO attendance_logs
                        (employee_code, employee_name, punch_time, punchin_time, punchout_time,
                         direction, device_name, verification_mode, device_id)
                        VALUES (:emp_code, :emp_name, :punch_time, :in_time, :out_time,
                                :direction, :device_name, :ver_mode, :device_id)');
                    $insStmt->execute([
                        'emp_code' => $empCode,
                        'emp_name' => $empName,
                        'punch_time' => $times[0],
                        'in_time' => $times[0],
                        'out_time' => $times[1] ?? null,
                        'direction' => '0',
                        'device_name' => $deviceName,
                        'ver_mode' => $verMode,
                        'device_id' => 'DEV_' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $deviceName), 0, 8)),
                    ]);
                    $newIds = [$pdo->lastInsertId()];
                } else {
                    $insStmt = $pdo->prepare("
                        INSERT INTO attendance_logs (employee_code, employee_name, punch_time, direction, device_name, verification_mode, device_id)
                        VALUES (:emp_code, :emp_name, :punch_time, :direction, :device_name, :ver_mode, :device_id)
                    ");
                    $newIds = [];
                    foreach ($entries as $entry) {
                        $insStmt->execute([
                            'emp_code' => $empCode,
                            'emp_name' => $empName,
                            'punch_time' => $entry['time'],
                            'direction' => $entry['direction'],
                            'device_name' => $deviceName,
                            'ver_mode' => $verMode,
                            'device_id' => 'DEV_' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $deviceName), 0, 8))
                        ]);
                        $newIds[] = $pdo->lastInsertId();
                    }
                }
                $pdo->commit();
                $flashSuccess = "New attendance log entr" . (count($newIds) > 1 ? "ies" : "y") . " #" . implode(', #', $newIds) . " created successfully for [{$empCode} - {$empName}].";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flashError = "Failed to add attendance log: " . $e->getMessage();
            }
        } else {
            $flashError = "Please provide Employee ID and the required punch date/time fields.";
        }
    } elseif ($action === 'delete_log') {
        $logId = intval($_POST['log_id'] ?? 0);
        if ($logId > 0) {
            try {
                $delStmt = $pdo->prepare("DELETE FROM attendance_logs WHERE id = :id");
                $delStmt->execute(['id' => $logId]);
                $flashSuccess = "Attendance log entry #{$logId} was deleted successfully.";
            } catch (Exception $e) {
                $flashError = "Failed to delete log entry: " . $e->getMessage();
            }
        }
    }

    // Refresh must repeat a GET, never the attendance mutation.
    $_SESSION['attendance_log_flash'] = [
        'success' => $flashSuccess,
        'error' => $flashError,
    ];
    $redirectFilters = array_intersect_key($_GET, array_flip([
        'from_date', 'to_date', 'device_name', 'search', 'direction',
    ]));
    $redirectQuery = http_build_query($redirectFilters);
    header('Location: biometeric_manpower_log.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : ''), true, 303);
    exit;
}

// Date & Filter Handling
$fromDate = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-6 days'));
$toDate = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$filterDevice = trim($_GET['device_name'] ?? '');
$filterSearch = trim($_GET['search'] ?? '');
$filterDirection = isset($_GET['direction']) && $_GET['direction'] !== '' ? trim($_GET['direction']) : '';

// Validation
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = date('Y-m-d', strtotime('-6 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = date('Y-m-d');
}
if ($fromDate > $toDate) {
    $temp = $fromDate;
    $fromDate = $toDate;
    $toDate = $temp;
}

$startQuery = $fromDate . ' 00:00:00';
$endQuery = $toDate . ' 23:59:59';

// Build Query for attendance_logs
$pairedFields = $hasPairedPunches
    ? 'a.punchin_time, a.punchout_time,'
    : 'NULL AS punchin_time, NULL AS punchout_time,';
$sql = "
    SELECT 
        a.id,
        a.employee_code,
        a.employee_name,
        COALESCE(e.full_name, a.employee_name) AS full_name_resolved,
        e.designation,
        a.punch_time,
        $pairedFields
        a.direction,
        a.device_id,
        a.device_name,
        a.verification_mode,
        a.created_at
    FROM attendance_logs a
    LEFT JOIN mcc_employee e ON a.employee_code = e.employee_id
    WHERE a.punch_time BETWEEN :start_time AND :end_time
";
$params = [
    'start_time' => $startQuery,
    'end_time' => $endQuery
];

if (in_array(strtolower($filterDevice), ['prt', 'pftr'], true)) {
    $sql .= " AND LOWER(a.device_name) IN ('prt', 'pftr')";
} elseif ($filterDevice !== '') {
    $sql .= " AND a.device_name LIKE :device_name";
    $params['device_name'] = '%' . $filterDevice . '%';
}

if ($filterSearch !== '') {
    $sql .= " AND (a.employee_code LIKE :q1 OR a.employee_name LIKE :q2 OR e.full_name LIKE :q3 OR a.device_name LIKE :q4)";
    $searchWild = '%' . $filterSearch . '%';
    $params['q1'] = $searchWild;
    $params['q2'] = $searchWild;
    $params['q3'] = $searchWild;
    $params['q4'] = $searchWild;
}

$sql .= " ORDER BY a.punch_time DESC, a.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Explicit receiver times take precedence over its original device direction.
// Keep legacy raw punches working when neither paired field has been populated.
$displayLogs = [];
foreach ($logs as $row) {
    $hasStoredTimes = !empty($row['punchin_time']) || !empty($row['punchout_time']);
    $times = $hasStoredTimes
        ? ['0' => $row['punchin_time'], '1' => $row['punchout_time']]
        : [(in_array(strtolower(trim((string)$row['direction'])), ['0', 'in', 'checkin'], true) ? '0' : '1') => $row['punch_time']];
    foreach ($times as $direction => $time) {
        if (empty($time) || ($filterDirection !== '' && (string)$direction !== $filterDirection)) continue;
        $punch = $row;
        $punch['punch_time'] = $time;
        $punch['direction'] = (string)$direction;
        // Keep an overnight OUT on the date of its attendance record.
        $punch['attendance_date'] = substr($hasStoredTimes ? ($row['punchin_time'] ?: $row['punch_time']) : $time, 0, 10);
        $displayLogs[] = $punch;
    }
}
$logs = $displayLogs;

// Display one row per employee per date, with IN and OUT together.
$attendanceRows = [];
foreach ($logs as $row) {
    $key = $row['employee_code'] . '|' . $row['attendance_date'];
    if (!isset($attendanceRows[$key])) {
        $attendanceRows[$key] = ['base' => $row, 'in' => null, 'out' => null];
    }
    $isIn = in_array(strtolower(trim((string)$row['direction'])), ['0', 'in', 'checkin'], true);
    $slot = $isIn ? 'in' : 'out';
    $current = $attendanceRows[$key][$slot];
    // Paired OUT times need not follow the raw query order.
    if ($current === null || ($isIn && $row['punch_time'] < $current['punch_time'])
        || (!$isIn && $row['punch_time'] > $current['punch_time'])) {
        $attendanceRows[$key][$slot] = $row;
    }
}
// The editable base ID must be one of the two punches actually displayed.
foreach ($attendanceRows as &$attendanceRow) {
    $attendanceRow['base'] = $attendanceRow['in'] ?? $attendanceRow['out'];
}
unset($attendanceRow);
$attendanceRows = array_values($attendanceRows);

// Metrics calculation
$totalLogs = count($logs);
$uniqueEmployees = [];
$inCount = 0;
$outCount = 0;

foreach ($logs as $l) {
    $uniqueEmployees[$l['employee_code']] = true;
    $dirStr = strtolower(trim((string)$l['direction']));
    if ($dirStr === '0' || $dirStr === 'in' || $dirStr === 'checkin') {
        $inCount++;
    } else {
        $outCount++;
    }
}
$distinctEmpCount = count($uniqueEmployees);

// Fetch active employees for autocomplete dropdown
$empListStmt = $pdo->query("SELECT employee_id, full_name, designation, department FROM mcc_employee ORDER BY full_name ASC");
$allEmployees = $empListStmt ? $empListStmt->fetchAll(PDO::FETCH_ASSOC) : [];

include 'header.php';
include 'sidebar.php';
?>

<main class="app-main">
  <!-- Content Header -->
  <div class="app-content-header py-3 mb-3 border-bottom bg-white shadow-sm">
    <div class="container-fluid">
      <div class="row align-items-center">
        <div class="col-md-7">
          <h3 class="mb-0 fw-bold text-dark d-flex align-items-center">
            <i class="bi bi-fingerprint text-primary me-2 fs-3"></i>
            Biometric Attendance Logs &amp; Manpower Entry
          </h3>
          <span class="text-muted small">
            Location: <strong><?= htmlspecialchars($stationName) ?></strong> &bull; Edit, modify timestamps, and manage punch records
          </span>
        </div>
        <div class="col-md-5 text-md-end mt-2 mt-md-0">
          <button type="button" class="btn btn-primary px-3 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addLogModal">
            <i class="bi bi-plus-circle me-1"></i> Add Punch Entry
          </button>
          <button type="button" class="btn btn-outline-dark px-3 fw-bold ms-1" onclick="window.print()">
            <i class="bi bi-printer-fill me-1"></i> Print
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Content Body -->
  <div class="app-content">
    <div class="container-fluid">

      <!-- Alert Messages -->
      <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-3 no-print" role="alert">
          <i class="bi bi-check-circle-fill me-2 fs-5"></i>
          <strong>Success:</strong> <?= htmlspecialchars($flashSuccess) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if (!empty($flashError)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm mb-3 no-print" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
          <strong>Error:</strong> <?= htmlspecialchars($flashError) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>



      <!-- Filter Card Toolbar -->
      <div class="card border-0 shadow-sm rounded-3 bg-white mb-4 no-print">
        <div class="card-body p-3">
          <form method="GET" action="biometeric_manpower_log.php" class="row g-2 align-items-end">
            <div class="col-md-2 col-sm-6">
              <label class="form-label small fw-bold text-secondary mb-1">From Date:</label>
              <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>" required>
            </div>

            <div class="col-md-2 col-sm-6">
              <label class="form-label small fw-bold text-secondary mb-1">To Date:</label>
              <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>" required>
            </div>

            <div class="col-md-2 col-sm-6">
              <label class="form-label small fw-bold text-secondary mb-1">Area / Device:</label>
              <select name="device_name" class="form-select form-select-sm">
                <option value="">-- All Areas --</option>
                <option value="coaching" <?= $filterDevice === 'coaching' ? 'selected' : '' ?>>Coaching Depot</option>
                <option value="pftr" <?= in_array(strtolower($filterDevice), ['prt', 'pftr'], true) ? 'selected' : '' ?>>Platform Return (PFTR)</option>
                <option value="vb" <?= $filterDevice === 'vb' ? 'selected' : '' ?>>Vande Bharat</option>
              </select>
            </div>

            <div class="col-md-2 col-sm-6">
              <label class="form-label small fw-bold text-secondary mb-1">Punch Direction:</label>
              <select name="direction" class="form-select form-select-sm">
                <option value="">-- All (IN &amp; OUT) --</option>
                <option value="0" <?= $filterDirection === '0' ? 'selected' : '' ?>>IN (Login)</option>
                <option value="1" <?= $filterDirection === '1' ? 'selected' : '' ?>>OUT (Logout)</option>
              </select>
            </div>

            <div class="col-md-2 col-sm-8">
              <label class="form-label small fw-bold text-secondary mb-1">Search Staff / ID:</label>
              <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name, ID..." value="<?= htmlspecialchars($filterSearch) ?>">
            </div>

            <div class="col-md-2 col-sm-4 d-flex gap-2">
              <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                <i class="bi bi-funnel-fill me-1"></i> Filter
              </button>
              <a href="biometeric_manpower_log.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                <i class="bi bi-arrow-counterclockwise"></i>
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Main Logs Table -->
      <div class="card border-0 shadow-sm rounded-3 bg-white mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <h5 class="mb-0 fw-bold text-dark">
              <i class="bi bi-table text-primary me-1"></i> Attendance Logs Records
            </h5>
            <span class="badge bg-primary-subtle text-primary rounded-pill px-3">
              Showing <?= count($attendanceRows) ?> Entries
            </span>
          </div>
          <div class="small text-muted">
            Date Window: <strong><?= date('d-M-Y', strtotime($fromDate)) ?></strong> to <strong><?= date('d-M-Y', strtotime($toDate)) ?></strong>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-nowrap" style="font-size: 13.5px;">
              <thead class="table-dark" style="background: #07203a; border-color: #0b476a;">
                <tr>
                  <th class="ps-3 py-3" style="width: 70px;">Log ID</th>
                  <th class="py-3">Date</th>
                  <th class="py-3 text-center">IN</th>
                  <th class="py-3 text-center">OUT</th>
                  <th class="py-3">Employee ID</th>
                  <th class="py-3">Employee Name</th>
                  <th class="py-3">Location / Device</th>
                  <!--<th class="py-3">Verification</th>-->
                  <th class="py-3 text-end pe-3 no-print" style="width: 140px;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($attendanceRows)): ?>
                  <tr>
                    <td colspan="9" class="text-center py-5 text-muted">
                      <div class="py-4">
                        <i class="bi bi-inbox fs-1 text-secondary opacity-50 d-block mb-2"></i>
                        <h6 class="fw-bold text-secondary">No attendance logs found</h6>
                        <p class="small text-muted mb-0">Try changing the date filter range or search keywords.</p>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($attendanceRows as $attendanceRow): 
                    $row = $attendanceRow['base'];
                    $displayName = !empty($row['full_name_resolved']) ? $row['full_name_resolved'] : (!empty($row['employee_name']) ? $row['employee_name'] : $row['employee_code']);
                    $punchDateOnly = date('d-M-Y', strtotime($row['attendance_date']));
                    $inRow = $attendanceRow['in'];
                    $outRow = $attendanceRow['out'];
                  ?>
                    <tr>
                      <td class="ps-3 fw-bold text-muted">#<?= $row['id'] ?></td>
                      <td>
                        <span class="badge bg-light text-dark border px-2 py-1">
                          <i class="bi bi-calendar3 me-1 text-primary"></i> <?= $punchDateOnly ?>
                        </span>
                      </td>
                      <td class="text-center">
                        <?php if ($inRow): ?>
                          <div class="fw-bold text-success"><?= date('h:i:s A', strtotime($inRow['punch_time'])) ?></div>
                          <div class="small text-muted"><?= date('d-M-Y', strtotime($inRow['punch_time'])) ?></div>
                        <?php else: ?>
                          <span class="badge bg-secondary-subtle text-secondary">Pending</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-center">
                        <?php if ($outRow): ?>
                          <div class="fw-bold text-warning"><?= date('h:i:s A', strtotime($outRow['punch_time'])) ?></div>
                          <div class="small text-muted"><?= date('d-M-Y', strtotime($outRow['punch_time'])) ?></div>
                        <?php else: ?>
                          <span class="badge bg-secondary-subtle text-secondary">Pending</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge bg-dark-subtle text-dark px-2 py-1 font-monospace fw-bold">
                          <?= htmlspecialchars($row['employee_code']) ?>
                        </span>
                      </td>
                      <td>
                        <div class="fw-bold text-dark"><?= htmlspecialchars($displayName) ?></div>
                        <?php if (!empty($row['designation'])): ?>
                          <div class="small text-muted" style="font-size: 11.5px;"><?= htmlspecialchars($row['designation']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge bg-secondary-subtle text-secondary px-2 py-1 text-uppercase" style="font-size: 11.5px; font-weight: 600;">
                          <i class="bi bi-hdd-network me-1"></i> <?= htmlspecialchars(strtolower($row['device_name'] ?? '') === 'prt' ? 'PFTR' : ($row['device_name'] ?: 'coaching')) ?>
                        </span>
                      </td>
                      <!--<td>-->
                      <!--  <span class="small text-muted">-->
                      <!--    <?= htmlspecialchars($row['verification_mode'] ?: 'Fingerprint') ?>-->
                      <!--  </span>-->
                      <!--</td>-->
                      <td class="text-end pe-3 no-print">
                        <button type="button" 
                                class="btn btn-sm edit-log-btn shadow-sm"
                                style="background-color: #f59e0b !important; color: #000000 !important; font-weight: 700 !important; border: 1px solid #d97706 !important; padding: 4px 12px; font-size: 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"
                                data-id="<?= $row['id'] ?>"
                                data-code="<?= htmlspecialchars($row['employee_code']) ?>"
                                data-name="<?= htmlspecialchars($row['employee_name'] ?: $displayName) ?>"
                                data-in-id="<?= $inRow ? (int)$inRow['id'] : 0 ?>"
                                data-out-id="<?= $outRow ? (int)$outRow['id'] : 0 ?>"
                                data-in-time="<?= $inRow ? date('Y-m-d\TH:i:s', strtotime($inRow['punch_time'])) : '' ?>"
                                data-out-time="<?= $outRow ? date('Y-m-d\TH:i:s', strtotime($outRow['punch_time'])) : '' ?>"
                                data-device="<?= htmlspecialchars($row['device_name'] ?: 'coaching') ?>"
                                title="Edit this punch log">
                          <i class="bi bi-pencil-square" style="color: #000000 !important; font-size: 13px;"></i> 
                          <span style="color: #000000 !important; font-weight: 700 !important;">Edit</span>
                        </button>
                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('Are you sure you want to delete Attendance Log #<?= $row['id'] ?> for Employee <?= htmlspecialchars($row['employee_code']) ?>?');">
                          <input type="hidden" name="action" value="delete_log">
                          <input type="hidden" name="log_id" value="<?= $row['id'] ?>">
                          <button type="submit" 
                                  class="btn btn-sm shadow-sm" 
                                  style="background-color: #ef4444 !important; color: #ffffff !important; font-weight: 700 !important; border: 1px solid #dc2626 !important; padding: 4px 10px; font-size: 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"
                                  title="Delete log">
                            <i class="bi bi-trash-fill" style="color: #ffffff !important; font-size: 13px;"></i>
                            <span style="color: #ffffff !important; font-weight: 700 !important;">Delete</span>
                          </button>
                        </form>
                      </td>

                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</main>

<!-- Edit Punch Log Modal -->
<div class="modal fade" id="editLogModal" tabindex="-1" aria-labelledby="editLogModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <div class="modal-header bg-dark text-white py-3">
        <h5 class="modal-title fw-bold" id="editLogModalLabel">
          <i class="bi bi-pencil-square text-warning me-2"></i> Edit Attendance Punch Log
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="" novalidate>
        <input type="hidden" name="action" value="edit_log">
        <input type="hidden" name="log_id" id="modalEditLogId">
        <input type="hidden" name="in_log_id" id="modalEditInLogId">
        <input type="hidden" name="out_log_id" id="modalEditOutLogId">
        
        <div class="modal-body p-4">
          <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-info-circle-fill fs-5"></i>
            <div>Editing Log ID: <strong id="modalEditLogIdDisplay">#0</strong>. Changes will be saved directly into <code>attendance_logs</code>.</div>
          </div>

          <div class="mb-3">
            <label for="modalEditPunchInTime" class="form-label small fw-bold text-secondary mb-1">
              <i class="bi bi-calendar-event me-1"></i> Punch IN Date &amp; Time:
            </label>
            <input type="datetime-local" step="1" class="form-control" id="modalEditPunchInTime" name="punchin_time">
          </div>
          <div class="mb-3">
            <label for="modalEditPunchOutTime" class="form-label small fw-bold text-secondary mb-1">
              <i class="bi bi-calendar-event me-1"></i> Punch OUT Date &amp; Time:
            </label>
            <input type="datetime-local" step="1" class="form-control" id="modalEditPunchOutTime" name="punchout_time">
            <div class="form-text small">Edit both punch times or fill in a pending punch. Existing punch times are required.</div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label for="modalEditEmpCode" class="form-label small fw-bold text-secondary mb-1">
                <i class="bi bi-person-badge me-1"></i> Employee ID / Code: <span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control font-monospace fw-bold" id="modalEditEmpCode" name="employee_code" required>
            </div>
          </div>

          <div class="mb-3">
            <label for="modalEditEmpName" class="form-label small fw-bold text-secondary mb-1">
              <i class="bi bi-person me-1"></i> Employee Full Name:
            </label>
            <input type="text" class="form-control" id="modalEditEmpName" name="employee_name" placeholder="e.g. Ramesh Kumar">
          </div>

          <div class="row g-2 mb-2">
            <div class="col-sm-6">
              <label for="modalEditDevice" class="form-label small fw-bold text-secondary mb-1">
                <i class="bi bi-hdd-network me-1"></i> Area / Device Name:
              </label>
              <select class="form-select" id="modalEditDevice" name="device_name">
                <option value="Coaching">Coaching</option>
                <option value="PFTR">PFTR</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light py-2 px-4">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning text-dark fw-bold px-4" style="background-color: #f59e0b !important; border-color: #d97706 !important; color: #000000 !important;">
            <i class="bi bi-check2-circle me-1" style="color: #000000 !important;"></i> <span style="color: #000000 !important;">Save Changes</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add New Punch Log Modal -->
<div class="modal fade" id="addLogModal" tabindex="-1" aria-labelledby="addLogModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <div class="modal-header bg-primary text-white py-3">
        <h5 class="modal-title fw-bold" id="addLogModalLabel">
          <i class="bi bi-plus-circle me-2"></i> Add New Punch Entry
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="" novalidate>
        <input type="hidden" name="action" value="add_log">
        
        <div class="modal-body p-4">
          <div class="mb-3">
            <label for="addPunchTime" class="form-label small fw-bold text-secondary mb-1">
              <i class="bi bi-calendar-event me-1"></i> IN Punch Date &amp; Time: <span class="text-danger">*</span>
            </label>
            <input type="datetime-local" step="1" class="form-control" id="addPunchTime" name="punch_time" value="<?= date('Y-m-d\TH:i:s') ?>" required>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label for="addEmpCode" class="form-label small fw-bold text-secondary mb-1">
                <i class="bi bi-person-badge me-1"></i> Employee ID: <span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control font-monospace fw-bold" id="addEmpCode" name="employee_code" placeholder="e.g. sbn007813" required list="employeesDatalist">
              <datalist id="employeesDatalist">
                <?php foreach ($allEmployees as $emp): ?>
                  <option value="<?= htmlspecialchars($emp['employee_id']) ?>" data-name="<?= htmlspecialchars($emp['full_name']) ?>" data-location="<?= htmlspecialchars($emp['department'] ?? '') ?>"><?= htmlspecialchars($emp['full_name']) ?> (<?= htmlspecialchars($emp['designation']) ?>)</option>
                <?php endforeach; ?>
              </datalist>
            </div>
          </div>

          <div class="mb-3" id="addPunchTimeOutWrap">
            <label for="addPunchTimeOut" class="form-label small fw-bold text-secondary mb-1">
              <i class="bi bi-calendar-event me-1"></i> OUT Punch Date &amp; Time:
            </label>
            <input type="datetime-local" step="1" class="form-control" id="addPunchTimeOut" name="punch_time_out">
            <div class="form-text small">Leave blank for an IN-only punch. Use Edit to add the OUT time later.</div>
          </div>

          <div class="mb-3">
            <label for="addEmpName" class="form-label small fw-bold text-secondary mb-1">
              <i class="bi bi-person me-1"></i> Employee Name:
            </label>
            <input type="text" class="form-control" id="addEmpName" name="employee_name" placeholder="e.g. Ramesh Kumar">
          </div>

          <div class="mb-2">
            <div class="col-sm-6 px-0">
              <label for="addDevice" class="form-label small fw-bold text-secondary mb-1">
                <i class="bi bi-hdd-network me-1"></i> Area / Device:
              </label>
              <select class="form-select" id="addDevice" name="device_name">
                <option value="Coaching">Coaching</option>
                <option value="PFTR">PFTR</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light py-2 px-4">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-bold px-4">
            <i class="bi bi-check-circle me-1"></i> Create Entry
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
  // Open the native calendar when clicking anywhere in a punch date/time field.
  document.querySelectorAll('#editLogModal input[type="datetime-local"], #addLogModal input[type="datetime-local"]').forEach(input => {
    input.addEventListener("click", function (event) {
      if (event.button !== 0 || typeof this.showPicker !== "function") return;
      try {
        this.showPicker();
      } catch (error) {
        // Keep native keyboard and calendar-icon controls available if unsupported.
      }
    });
  });

  // Validate submitted fields only. Hidden calendar controls can have step
  // mismatches and must not block an otherwise valid attendance submission.
  document.querySelectorAll('#editLogModal form, #addLogModal form').forEach(form => {
    const feedback = document.createElement('div');
    feedback.className = 'alert alert-danger py-2 small';
    feedback.setAttribute('role', 'alert');
    feedback.hidden = true;
    form.querySelector('.modal-body').prepend(feedback);
    form.addEventListener('submit', event => {
      feedback.hidden = true;
      for (const field of form.querySelectorAll('input[name], select[name], textarea[name]')) {
        if (field.disabled) continue;
        const missing = field.required && field.value.trim() === '';
        if (!missing && field.checkValidity()) continue;
        event.preventDefault();
        const label = form.querySelector('label[for="' + field.id + '"]');
        const fieldName = label ? label.textContent.replace(/\s+/g, ' ').trim() : 'Punch entry';
        feedback.textContent = fieldName + ' ' + (missing ? 'Please fill out this field.' : field.validationMessage);
        feedback.hidden = false;
        const visibleField = field._flatpickr ? field._flatpickr.altInput || field : field;
        visibleField.focus();
        return;
      }
    });
    form.addEventListener('input', () => { feedback.hidden = true; });
    form.closest('.modal').addEventListener('show.bs.modal', () => { feedback.hidden = true; });
  });

  // Bind click handlers for editing log modal
  const editModalEl = document.getElementById("editLogModal");
  const editModal = new bootstrap.Modal(editModalEl);

  document.querySelectorAll(".edit-log-btn").forEach(btn => {
    btn.addEventListener("click", function () {
      const logId = this.dataset.id;
      const code = this.dataset.code;
      const name = this.dataset.name;
      const dev = this.dataset.device.toLowerCase() === 'prt' ? 'PFTR' : this.dataset.device;

      document.getElementById("modalEditLogId").value = logId;
      document.getElementById("modalEditLogIdDisplay").innerText = "#" + logId;
      document.getElementById("modalEditEmpCode").value = code;
      document.getElementById("modalEditEmpName").value = name;
      document.getElementById("modalEditInLogId").value = this.dataset.inId;
      document.getElementById("modalEditOutLogId").value = this.dataset.outId;
      const punchInInput = document.getElementById("modalEditPunchInTime");
      const punchOutInput = document.getElementById("modalEditPunchOutTime");
      punchInInput.value = this.dataset.inTime;
      punchOutInput.value = this.dataset.outTime;
      punchInInput.required = Number(this.dataset.inId) > 0;
      punchOutInput.required = Number(this.dataset.outId) > 0;

      const devSelect = document.getElementById("modalEditDevice");
      let devMatched = false;
      for (let i = 0; i < devSelect.options.length; i++) {
        if (devSelect.options[i].value.toLowerCase() === dev.toLowerCase() || dev.toLowerCase().includes(devSelect.options[i].value.toLowerCase())) {
          devSelect.selectedIndex = i;
          devMatched = true;
          break;
        }
      }
      if (!devMatched) {
        devSelect.value = "Coaching";
      }

      editModal.show();
    });
  });

  // Auto-fill employee name when selecting employee_code in add modal
  const addEmpCodeInput = document.getElementById("addEmpCode");
  const addEmpNameInput = document.getElementById("addEmpName");
  const addDevice = document.getElementById("addDevice");
  const employeesDatalist = document.getElementById("employeesDatalist");
  const allEmployeeOptions = employeesDatalist ? Array.from(employeesDatalist.options).map(option => option.cloneNode(true)) : [];
  const addLogModal = document.getElementById("addLogModal");
  const addPunchTime = document.getElementById("addPunchTime");
  const setCurrentPunchTime = (input) => {
    if (!input) return;
    const now = new Date();
    const pad = value => String(value).padStart(2, "0");
    input.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
  };
  setCurrentPunchTime(addPunchTime);
  if (addLogModal) {
    addLogModal.addEventListener("show.bs.modal", () => setCurrentPunchTime(addPunchTime));
  }
  if (addEmpCodeInput && addEmpNameInput) {
    const fillEmployeeName = () => {
      const val = addEmpCodeInput.value.trim().toLowerCase();
      const option = allEmployeeOptions.find(opt => opt.value.trim().toLowerCase() === val);
      addEmpNameInput.value = option ? option.dataset.name || '' : '';
    };
    addEmpCodeInput.addEventListener("input", fillEmployeeName);
    addEmpCodeInput.addEventListener("change", fillEmployeeName);
  }
});
</script>

<?php include 'footer.php'; ?>
