<?php
/**
 * Delete one complete editor report sheet by token_id.
 * The report key is whitelisted and all deletes are scoped to the user's station.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../connection.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required.']);
    exit;
}

$role = strtoupper((string)($_SESSION['role'] ?? ''));
if (!in_array($role, ['EDITOR', 'ADMIN'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only an editor can delete sheets.']);
    exit;
}

$tokenId = trim((string)($_POST['token_id'] ?? ''));
$reportKey = basename((string)($_POST['report_key'] ?? ''));
$stationId = (int)($_SESSION['station_id'] ?? 0);

if ($tokenId === '' || $stationId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Token and station are required.']);
    exit;
}

$families = [
    'normal-report.php' => ['mcc_normal_scorecard_report', 'mcc_chemical_report'],
    'intensive-report.php' => ['mcc_intensive_scorecard_report', 'mcc_chemical_report'],
    'intensive_scorecard_2.php' => ['mcc_intensive_scorecard_2_report', 'mcc_chemical_report'],
    'intensive_pantry_scorecard.php' => ['mcc_intensive_pantry_report', 'mcc_chemical_report'],
    'DC-Scorecard.php' => ['dc_mcc_report', 'dc_mcc_chemical_report', 'mcc_chemical_report'],
    'Platform-Return-TrainsScorecard.php' => ['mcc_prt_scorecard_report', 'mcc_chemical_report'],
    'vande-bharat-report.php' => ['mcc_vb_scorecard_report', 'mcc_chemical_report'],
    'chemical-report.php' => ['mcc_chemical_report'],
    'intensive-chemical-report.php' => ['mcc_chemical_report'],
    'pantry-chemical.php' => ['mcc_chemical_report'],
    'Platform-Return-Chemical.php' => ['mcc_chemical_report'],
    'dc-chemical.php' => ['dc_mcc_chemical_report', 'mcc_chemical_report'],
    'vande-bharat-chemical.php' => ['mcc_chemical_report'],
    'surprise-pit-office.php' => ['mcc_surprise_reports'],
    'surprise-pf-trains.php' => ['mcc_surprise_reports'],
];

if (!isset($families[$reportKey])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported report sheet.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $deleted = 0;
    foreach (array_unique($families[$reportKey]) as $table) {
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE token_id = :token_id AND station_id = :station_id");
        $stmt->execute(['token_id' => $tokenId, 'station_id' => $stationId]);
        $deleted += $stmt->rowCount();
    }
    $pdo->commit();

    if ($deleted === 0) {
        echo json_encode(['success' => false, 'message' => 'Sheet not found for this station.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Sheet deleted successfully.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to delete the sheet.']);
}
