<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

// Get patient ID from URL
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if (!$patient_id) {
    header('Location: patients.php');
    exit;
}

// Get patient details
function getPatientDetails($patient_id) {
    $db = getDBConnection();
    
    try {
        $query = "
            SELECT 
                p.*,
                b.name as barangay_name,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
            FROM patients p
            LEFT JOIN barangays b ON p.barangay_id = b.barangay_id
            WHERE p.patient_id = ?
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$patient_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Error getting patient details: " . $e->getMessage());
        return null;
    }
}

// Get all cases for this patient
function getPatientCases($patient_id) {
    $db = getDBConnection();
    
    try {
        $query = "
            SELECT 
                pc.*,
                DATEDIFF(CURDATE(), pc.date_reported) as days_ago
            FROM patient_cases pc
            WHERE pc.patient_id = ?
            ORDER BY pc.date_reported DESC, pc.created_at DESC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$patient_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Error getting patient cases: " . $e->getMessage());
        return [];
    }
}

$patient = getPatientDetails($patient_id);

if (!$patient) {
    header('Location: patients.php?error=Patient not found');
    exit;
}

$cases = getPatientCases($patient_id);

// Generate filename
$filename = 'Patient_' . $patient['patient_id'] . '_' . str_replace(' ', '_', $patient['first_name'] . '_' . $patient['last_name']) . '_Cases_' . date('Y-m-d') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Create file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for proper Excel encoding
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Patient Information Header
fputcsv($output, ['PATIENT INFORMATION']);
fputcsv($output, ['Patient ID', $patient['patient_id']]);
fputcsv($output, ['Full Name', $patient['first_name'] . ' ' . $patient['last_name']]);
fputcsv($output, ['Date of Birth', date('Y-m-d', strtotime($patient['date_of_birth']))]);
fputcsv($output, ['Age', $patient['age'] . ' years']);
fputcsv($output, ['Gender', $patient['gender']]);
fputcsv($output, ['Contact Number', $patient['contact_number'] ?: 'Not provided']);
fputcsv($output, ['Address', $patient['address']]);
fputcsv($output, ['Barangay', $patient['barangay_name']]);
fputcsv($output, ['Registration Date', date('Y-m-d H:i:s', strtotime($patient['created_at']))]);

// Empty row
fputcsv($output, []);

// Case Summary
fputcsv($output, ['CASE SUMMARY']);
fputcsv($output, ['Total Cases', count($cases)]);
fputcsv($output, ['Latest Status', $patient['latest_status'] ?: 'No cases recorded']);
fputcsv($output, ['Last Case Date', $patient['latest_case_date'] ?: 'None']);

// Empty row
fputcsv($output, []);

// Cases Header
if (!empty($cases)) {
    fputcsv($output, ['MEDICAL CASE HISTORY']);
    fputcsv($output, [
        'Case ID',
        'Date Reported',
        'Status',
        'Temperature (°C)',
        'Symptoms',
        'Notes',
        'Days Ago',
        'Created Date'
    ]);
    
    // Cases Data
    foreach ($cases as $case) {
        fputcsv($output, [
            $case['case_id'],
            date('Y-m-d', strtotime($case['date_reported'])),
            $case['status'],
            $case['temperature'] ?: 'N/A',
            $case['symptoms'] ?: 'None',
            $case['notes'] ?: 'None',
            $case['days_ago'],
            date('Y-m-d H:i:s', strtotime($case['created_at']))
        ]);
    }
} else {
    fputcsv($output, ['MEDICAL CASE HISTORY']);
    fputcsv($output, ['No cases recorded for this patient']);
}

// Empty row
fputcsv($output, []);

// Footer
fputcsv($output, ['Generated on', date('Y-m-d H:i:s')]);
fputcsv($output, ['System', 'Dengue Monitoring System']);

// Close file pointer
fclose($output);
exit;
?>
