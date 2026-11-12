<?php

header('Content-Type: application/json');

// =====================================================
// SECURITY
// =====================================================

// CHANGE THIS to your own random secret.
// Use the SAME key in your PC's config.json.
$API_KEY = '9412418709';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'POST required'
    ]);
    exit;
}

$receivedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if (!hash_equals($API_KEY, $receivedKey)) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Unauthorized'
    ]);
    exit;
}


// =====================================================
// READ JSON
// =====================================================

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON'
    ]);
    exit;
}


// =====================================================
// ATTENDANCE DATA
// =====================================================

$employeeCode = trim((string)($input['employee_code'] ?? ''));

$employeeName = isset($input['employee_name'])
    ? trim((string)$input['employee_name'])
    : null;
    
$punchTime = trim((string)($input['punch_time'] ?? ''));

$direction = isset($input['direction'])
    ? trim((string)$input['direction'])
    : null;

$deviceId = isset($input['device_id'])
    ? trim((string)$input['device_id'])
    : null;

$serialNumber = isset($input['serial_number'])
    ? trim((string)$input['serial_number'])
    : null;

$verificationMode = isset($input['verification_mode'])
    ? trim((string)$input['verification_mode'])
    : null;

$deviceName = isset($input['device_name'])
    ? trim((string)$input['device_name'])
    : null;


if ($employeeCode === '' || $punchTime === '') {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'employee_code and punch_time are required'
    ]);

    exit;
}

$punchTimestamp = strtotime($punchTime);

if ($punchTimestamp === false) {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid punch_time'
    ]);

    exit;
}

$punchTimeFormatted = date('Y-m-d H:i:s', $punchTimestamp);


// =====================================================
// DATABASE
// =====================================================

$dbHost = 'localhost';
$dbName = 'mccbeatleanalyti_db';
$dbUser = 'mccbeatleanalyti_user';
$dbPass = 'Cee;[+&e$a+9rAXe';


try {

    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );


    // =================================================
    // FIND THE EMPLOYEE'S PREVIOUS PUNCH
    // =================================================

    $findStmt = $pdo->prepare("
        SELECT id, punch_time, punchin_time, punchout_time
        FROM attendance_logs
        WHERE employee_code = :employee_code
          AND punch_time <= :punch_time
        ORDER BY punch_time DESC
        LIMIT 1
    ");

    $findStmt->execute([
        ':employee_code' => $employeeCode,
        ':punch_time' => $punchTimeFormatted
    ]);

    $existing = $findStmt->fetch();
    $action = 'inserted';


    if ($existing) {

        $windowStart = !empty($existing['punchin_time'])
            ? $existing['punchin_time']
            : $existing['punch_time'];

        $elapsed = $punchTimestamp - strtotime($windowStart);

        if ($elapsed === 0) {

            // The same punch was received again; keep the row as it is.
            $action = 'unchanged';

        } elseif (!empty($existing['punchout_time'])) {

            // A completed attendance record stays closed. A later punch starts
            // a new IN record, even within 18 hours of the previous IN.
            if ($punchTimestamp <= strtotime($existing['punchout_time'])) {
                $action = 'unchanged';
            }

        } elseif ($elapsed > 0 && $elapsed <= 64800) {

            // Within 18 hours: this punch is the OUT punch.
            $updateStmt = $pdo->prepare("
                UPDATE attendance_logs
                SET punchout_time = :punchout_time
                WHERE id = :id
            ");

            $updateStmt->execute([
                ':punchout_time' => $punchTimeFormatted,
                ':id' => $existing['id']
            ]);

            $action = 'updated';

        } else {

            // No second punch within 18 hours: close the open row at
            // punchin_time + 18 hours, then start a new row below.
            $closeStmt = $pdo->prepare("
                UPDATE attendance_logs
                SET punchout_time = DATE_ADD(
                    COALESCE(punchin_time, punch_time),
                    INTERVAL 18 HOUR
                )
                WHERE id = :id
                  AND punchout_time IS NULL
            ");

            $closeStmt->execute([
                ':id' => $existing['id']
            ]);
        }
    }


    // =================================================
    // INSERT A NEW PUNCH-IN AFTER A COMPLETED OR EXPIRED WINDOW
    // =================================================

    if ($action === 'inserted') {

        $sql = "
            INSERT INTO attendance_logs
            (
                employee_code,
                employee_name,
                punch_time,
                punchin_time,
                direction,
                device_id,
                serial_number,
                verification_mode,
                device_name
            )
            VALUES
            (
                :employee_code,
                :employee_name,
                :punch_time,
                :punchin_time,
                :direction,
                :device_id,
                :serial_number,
                :verification_mode,
                :device_name
            )
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':employee_code' => $employeeCode,
            ':employee_name' => $employeeName,
            ':punch_time' => $punchTimeFormatted,
            ':punchin_time' => $punchTimeFormatted,
            ':direction' => $direction,
            ':device_id' => $deviceId,
            ':serial_number' => $serialNumber,
            ':verification_mode' => $verificationMode,
            ':device_name' => $deviceName
        ]);
    }


    echo json_encode([
        'ok' => true,
        'action' => $action
    ]);


} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'Database error'
    ]);
}
?>
