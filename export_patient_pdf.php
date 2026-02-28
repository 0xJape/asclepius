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

// Include FPDF library
require('vendor/fpdf/fpdf.php');

// Get patient details function
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
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            return null;
        }
        
        // Get latest case status
        $latestQuery = "
            SELECT status, case_id, date_reported
            FROM patient_cases 
            WHERE patient_id = ?
            ORDER BY date_reported DESC, created_at DESC
            LIMIT 1
        ";  
        
        $latestStmt = $db->prepare($latestQuery);
        $latestStmt->execute([$patient_id]);
        $latestCase = $latestStmt->fetch(PDO::FETCH_ASSOC);
        
        // Merge latest case data
        $patient['latest_status'] = $latestCase['status'] ?? null;
        $patient['latest_case_id'] = $latestCase['case_id'] ?? null;
        $patient['latest_case_date'] = $latestCase['date_reported'] ?? null;
        
        return $patient;
        
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

// Create PDF class extending FPDF
class PatientPDF extends FPDF
{
    // Header
    function Header()
    {
        // Logo or header image (if available)
        // $this->Image('logo.png',10,6,30);
        
        // Set font
        $this->SetFont('Arial','B',20);
        
        // Move to the right
        $this->Cell(80);
        
        // Title
        $this->SetTextColor(220, 53, 69); // Bootstrap danger color
        $this->Cell(110,10,'DENGUE MONITORING SYSTEM',0,0,'C');
        
        // Line break
        $this->Ln(10);
        
        $this->SetFont('Arial','',14);
        $this->SetTextColor(108, 117, 125); // Bootstrap secondary color
        $this->Cell(0,10,'Patient Medical Report',0,0,'C');
        
        // Line break
        $this->Ln(15);
        
        // Add a line
        $this->SetDrawColor(220, 53, 69);
        $this->Line(10, 35, 200, 35);
        $this->Ln(10);
    }

    // Footer
    function Footer()
    {
        // Position at 1.5 cm from bottom
        $this->SetY(-15);
        
        // Add a line
        $this->SetDrawColor(220, 53, 69);
        $this->Line(10, $this->GetY()-5, 200, $this->GetY()-5);
        
        // Set font
        $this->SetFont('Arial','I',10);
        $this->SetTextColor(108, 117, 125);
        
        // Page number and generation info
        $this->Cell(0,10,'Generated on: ' . date('F j, Y g:i A') . ' | Page '.$this->PageNo().'/{nb}',0,0,'C');
    }
    
    // Enhanced Cell with background color
    function ColoredCell($w, $h, $txt, $border=0, $ln=0, $align='', $fill=false, $r=255, $g=255, $b=255)
    {
        if($fill) {
            $this->SetFillColor($r, $g, $b);
        }
        $this->Cell($w, $h, $txt, $border, $ln, $align, $fill);
    }
    
    // Section header
    function SectionHeader($title)
    {
        $this->Ln(5);
        $this->SetFont('Arial','B',14);
        $this->SetTextColor(13, 110, 253); // Bootstrap primary color
        $this->ColoredCell(0, 10, $title, 0, 1, 'L', true, 248, 249, 250);
        $this->Ln(3);
    }
    
    // Patient info row
    function InfoRow($label, $value, $full_width = false)
    {
        $this->SetFont('Arial','B',10);
        $this->SetTextColor(108, 117, 125);
        
        if ($full_width) {
            $this->Cell(40, 8, $label . ':', 0, 0, 'L');
            $this->SetFont('Arial','',10);
            $this->SetTextColor(33, 37, 41);
            $this->Cell(0, 8, $value, 0, 1, 'L');
        } else {
            $this->Cell(40, 8, $label . ':', 0, 0, 'L');
            $this->SetFont('Arial','',10);
            $this->SetTextColor(33, 37, 41);
            $this->Cell(50, 8, $value, 0, 0, 'L');
        }
        
        $this->Ln(6);
    }
}

// Create PDF instance
$pdf = new PatientPDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Patient Information Section
$pdf->SectionHeader('PATIENT INFORMATION');

$pdf->InfoRow('Patient ID', '#' . $patient['patient_id']);
$pdf->InfoRow('Full Name', $patient['first_name'] . ' ' . $patient['last_name'], true);
$pdf->InfoRow('Date of Birth', date('F j, Y', strtotime($patient['date_of_birth'])));
$pdf->InfoRow('Age', $patient['age'] . ' years old');
$pdf->InfoRow('Gender', $patient['gender']);
$pdf->InfoRow('Contact Number', $patient['contact_number'] ?: 'Not provided');
$pdf->InfoRow('Address', $patient['address'], true);
$pdf->InfoRow('Barangay', $patient['barangay_name']);
$pdf->InfoRow('Registration Date', date('F j, Y', strtotime($patient['created_at'])), true);

// Case Summary Section
$pdf->SectionHeader('CASE SUMMARY');

$totalCases = count($cases);
$pdf->InfoRow('Total Cases', $totalCases);
$pdf->InfoRow('Latest Status', $patient['latest_status'] ?: 'No cases recorded');
$pdf->InfoRow('Last Case Date', $patient['latest_case_date'] ? date('F j, Y', strtotime($patient['latest_case_date'])) : 'None');

// Case History Section
if (!empty($cases)) {
    $pdf->SectionHeader('MEDICAL CASE HISTORY');
    
    // Table header
    $pdf->SetFont('Arial','B',9);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->ColoredCell(20, 10, 'Case ID', 1, 0, 'C', true, 13, 110, 253);
    $pdf->ColoredCell(30, 10, 'Date Reported', 1, 0, 'C', true, 13, 110, 253);
    $pdf->ColoredCell(25, 10, 'Status', 1, 0, 'C', true, 13, 110, 253);
    $pdf->ColoredCell(25, 10, 'Temperature', 1, 0, 'C', true, 13, 110, 253);
    $pdf->ColoredCell(90, 10, 'Symptoms', 1, 1, 'C', true, 13, 110, 253);
    
    // Table content
    $pdf->SetFont('Arial','',8);
    $pdf->SetTextColor(33, 37, 41);
    
    foreach ($cases as $index => $case) {
        // Alternate row colors
        $fill = ($index % 2 == 0);
        $bgR = $fill ? 248 : 255;
        $bgG = $fill ? 249 : 255;
        $bgB = $fill ? 250 : 255;
        
        // Calculate row height based on content
        $symptoms = $case['symptoms'] ? substr($case['symptoms'], 0, 60) . (strlen($case['symptoms']) > 60 ? '...' : '') : 'None';
        $rowHeight = 8;
        
        $pdf->ColoredCell(20, $rowHeight, '#' . $case['case_id'], 1, 0, 'C', $fill, $bgR, $bgG, $bgB);
        $pdf->ColoredCell(30, $rowHeight, date('M j, Y', strtotime($case['date_reported'])), 1, 0, 'C', $fill, $bgR, $bgG, $bgB);
        $pdf->ColoredCell(25, $rowHeight, $case['status'], 1, 0, 'C', $fill, $bgR, $bgG, $bgB);
        $pdf->ColoredCell(25, $rowHeight, $case['temperature'] ? $case['temperature'] . '°C' : 'N/A', 1, 0, 'C', $fill, $bgR, $bgG, $bgB);
        $pdf->ColoredCell(90, $rowHeight, $symptoms, 1, 1, 'L', $fill, $bgR, $bgG, $bgB);
        
        // Add notes if available
        if ($case['notes'] && strlen(trim($case['notes'])) > 0) {
            $pdf->SetFont('Arial','I',7);
            $pdf->SetTextColor(108, 117, 125);
            $notes = substr($case['notes'], 0, 100) . (strlen($case['notes']) > 100 ? '...' : '');
            $pdf->Cell(20, 6, '', 0, 0); // Empty space for alignment
            $pdf->Cell(170, 6, 'Notes: ' . $notes, 0, 1, 'L');
            $pdf->SetFont('Arial','',8);
            $pdf->SetTextColor(33, 37, 41);
        }
    }
} else {
    $pdf->SectionHeader('MEDICAL CASE HISTORY');
    $pdf->SetFont('Arial','I',12);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 20, 'No medical cases recorded for this patient.', 0, 1, 'C');
}

// Add disclaimer
$pdf->Ln(15);
$pdf->SetFont('Arial','I',9);
$pdf->SetTextColor(108, 117, 125);
$pdf->MultiCell(0, 5, 'DISCLAIMER: This document contains confidential medical information. It should be handled in accordance with applicable privacy laws and regulations. This report is generated for official medical and administrative purposes only.');

// Output PDF
$filename = 'Patient_' . $patient['patient_id'] . '_' . str_replace(' ', '_', $patient['first_name'] . '_' . $patient['last_name']) . '_' . date('Y-m-d') . '.pdf';

// Set headers for PDF download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

$pdf->Output('D', $filename);
?>
