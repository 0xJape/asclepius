<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

// Get patient ID from URL
$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$patient_id) {
    header('Location: patients.php');
    exit;
}

// Get patient details with latest case information
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
    $db = getDBConnection();
    $checkQuery = "SELECT COUNT(*) as patient_count FROM patients";
    $result = $db->query($checkQuery);
    $count = $result->fetch(PDO::FETCH_ASSOC);
    
    if ($count['patient_count'] == 0) {
        header('Location: patients.php?error=No patients found in database');
    } else {
        header('Location: patients.php?error=Patient ID ' . $patient_id . ' not found');
    }
    exit;
}

$cases = getPatientCases($patient_id);
$totalCases = count($cases);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?> - Patient Profile</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/dengue_logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/dengue_logo.png">
    
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Google Fonts - Poppins (Display) + Inter (Body) -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Modern Design System CSS -->
    <link href="assets/css/modern.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/dashboard.css" rel="stylesheet">
    
    <style>
        .patient-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .patient-avatar-large {
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.2);
            border: 4px solid rgba(255,255,255,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: white;
            font-weight: 700;
            font-size: 2.5rem;
            backdrop-filter: blur(10px);
        }
        
        .info-card {
            transition: all 0.3s ease;
            border: none;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .case-timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .case-timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 2rem;
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #e9ecef;
        }
        
        .timeline-item.critical {
            border-left-color: #dc3545;
        }
        
        .timeline-item.severe {
            border-left-color: #fd7e14;
        }
        
        .timeline-item.moderate {
            border-left-color: #ffc107;
        }
        
        .timeline-item.mild {
            border-left-color: #28a745;
        }
        
        .timeline-item.recovered {
            border-left-color: #6c757d;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.5rem;
            top: 1.5rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: white;
            border: 3px solid #e9ecef;
        }
        
        .timeline-item.critical::before {
            border-color: #dc3545;
        }
        
        .timeline-item.severe::before {
            border-color: #fd7e14;
        }
        
        .timeline-item.moderate::before {
            border-color: #ffc107;
        }
        
        .timeline-item.mild::before {
            border-color: #28a745;
        }
        
        .timeline-item.recovered::before {
            border-color: #6c757d;
        }
        
        .temperature-badge {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: 600;
        }
        
        .temp-normal {
            background: #d4edda;
            color: #155724;
        }
        
        .temp-elevated {
            background: #fff3cd;
            color: #856404;
        }
        
        .temp-high {
            background: #f8d7da;
            color: #721c24;
        }
        
        .symptoms-tag {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            margin: 0.25rem;
            display: inline-block;
            font-size: 0.875rem;
        }
        
        .quick-info {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .quick-info .row > div {
            border-right: 1px solid rgba(255,255,255,0.2);
        }
        
        .quick-info .row > div:last-child {
            border-right: none;
        }
    </style>
</head>

<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <i class="fas fa-heartbeat me-2"></i>
                <span>ASCLEPIUS</span>
            </div>
            <button class="d-none d-lg-none sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-section">
                <a href="dashboard.php" class="menu-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="patients.php" class="menu-item active">
                    <i class="fas fa-users"></i>
                    <span>Patients</span>
                </a>
                <a href="analytics.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Analytics</span>
                </a>
                <a href="prediction.php" class="menu-item">
                    <i class="fas fa-brain"></i>
                    <span>Risk Prediction</span>
                </a>
                <a href="alerts.php" class="menu-item">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Alerts</span>
                </a>
            </div>
            
            <div class="menu-section">
                <div class="menu-title">Management</div>
                <a href="chatbot/chatbot.php" class="menu-item">
                    <i class="fas fa-robot"></i>
                    <span>AI Chatbot</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Back Button -->
        <div class="mb-4">
            <a href="patients.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Patients
            </a>
        </div>

        <!-- Success/Info Messages -->
        <?php if (isset($_SESSION['info_message'])): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i>
            <?php 
            echo htmlspecialchars($_SESSION['info_message']); 
            unset($_SESSION['info_message']); // Clear the message after displaying
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php 
            echo htmlspecialchars($_SESSION['success_message']); 
            unset($_SESSION['success_message']); // Clear the message after displaying
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['add_case_message'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-plus-circle me-2"></i>
            <?php 
            echo htmlspecialchars($_SESSION['add_case_message']); 
            unset($_SESSION['add_case_message']); // Clear the message after displaying
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Patient Header -->
        <div class="patient-header">
            <div class="row align-items-center">
                <div class="col-auto">
                    <div class="patient-avatar-large">
                        <?php echo strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1)); ?>
                    </div>
                </div>
                <div class="col">
                    <h1 class="mb-2"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h1>
                    <div class="row g-3 mb-3">
                        <div class="col-auto">
                            <i class="fas fa-id-card me-2"></i>
                            Patient ID: #<?php echo $patient['patient_id']; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar me-2"></i>
                            <?php echo $patient['age']; ?> years old
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-<?php echo strtolower($patient['gender']) === 'male' ? 'mars' : 'venus'; ?> me-2"></i>
                            <?php echo htmlspecialchars($patient['gender']); ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            <?php echo htmlspecialchars($patient['barangay_name']); ?>
                        </div>
                    </div>
                    
                    <!-- Quick Info Section -->
                    <div class="quick-info">
                        <div class="row text-center">
                            <div class="col-3">
                                <div class="fw-bold"><?php echo $totalCases; ?></div>
                                <small>Total Cases</small>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold">
                                    <?php echo $patient['latest_status'] ? htmlspecialchars($patient['latest_status']) : 'None'; ?>
                                </div>
                                <small>Latest Status</small>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold">
                                    <?php echo $patient['latest_case_date'] ? date('M j', strtotime($patient['latest_case_date'])) : 'None'; ?>
                                </div>
                                <small>Last Case</small>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold"><?php echo date('M j, Y', strtotime($patient['created_at'])); ?></div>
                                <small>Registered</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-auto">
                    <?php if ($patient['latest_status']): ?>
                    <span class="badge fs-6 px-3 py-2 bg-<?php 
                        echo match(strtolower($patient['latest_status'])) {
                            'critical' => 'danger',
                            'severe' => 'warning',
                            'moderate' => 'info',
                            'mild' => 'success',
                            'recovered' => 'secondary',
                            default => 'light'
                        }; 
                    ?>">
                        <?php echo htmlspecialchars($patient['latest_status']); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Patient Information -->
            <div class="col-lg-4">
                <div class="card info-card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-user me-2"></i>Personal Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="text-muted small">Full Name</label>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Date of Birth</label>
                            <p class="mb-0"><?php echo date('F j, Y', strtotime($patient['date_of_birth'])); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Age</label>
                            <p class="mb-0"><?php echo $patient['age']; ?> years old</p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Gender</label>
                            <p class="mb-0">
                                <i class="fas fa-<?php echo strtolower($patient['gender']) === 'male' ? 'mars text-primary' : 'venus text-danger'; ?> me-2"></i>
                                <?php echo htmlspecialchars($patient['gender']); ?>
                            </p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Contact Number</label>
                            <p class="mb-0">
                                <?php if ($patient['contact_number']): ?>
                                    <a href="tel:<?php echo $patient['contact_number']; ?>" class="text-decoration-none">
                                        <i class="fas fa-phone me-1"></i>
                                        <?php echo htmlspecialchars($patient['contact_number']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Not provided</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Address</label>
                            <p class="mb-0"><?php echo htmlspecialchars($patient['address']); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Barangay</label>
                            <p class="mb-0">
                                <i class="fas fa-map-marker-alt me-1 text-primary"></i>
                                <?php echo htmlspecialchars($patient['barangay_name']); ?>
                            </p>
                        </div>
                        
                        <div class="mb-0">
                            <label class="text-muted small">Registration Date</label>
                            <p class="mb-0"><?php echo date('F j, Y g:i A', strtotime($patient['created_at'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case History -->
            <div class="col-lg-8">
                <div class="card info-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>Medical Case History
                        </h5>
                        <div>
                            <span class="badge bg-info me-2"><?php echo $totalCases; ?> Total Cases</span>
                            <button class="btn btn-success btn-sm me-2" onclick="addNewCase()">
                                <i class="fas fa-plus me-1"></i>Add Case
                            </button>
                            <div class="btn-group">
                                <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-download me-1"></i>Export
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" onclick="exportPatientPDF()">
                                        <i class="fas fa-file-pdf me-2 text-danger"></i>Export as PDF
                                    </a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportCaseHistory()">
                                        <i class="fas fa-file-csv me-2 text-success"></i>Export as CSV
                                    </a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="#" onclick="printPatientProfile()">
                                        <i class="fas fa-print me-2 text-secondary"></i>Print Profile
                                    </a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($cases)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No medical case history</h5>
                                <p class="text-muted">This patient has no recorded cases yet.</p>
                                <button class="btn btn-primary" onclick="addNewCase()">
                                    <i class="fas fa-plus me-1"></i>Add First Case
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="case-timeline">
                                <?php foreach ($cases as $case): ?>
                                <div class="timeline-item <?php echo strtolower($case['status']); ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h6 class="mb-1">Case #<?php echo $case['case_id']; ?></h6>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?php echo date('F j, Y', strtotime($case['date_reported'])); ?>
                                                (<?php echo $case['days_ago']; ?> days ago)
                                            </small>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-<?php 
                                                echo match(strtolower($case['status'])) {
                                                    'critical' => 'danger',
                                                    'severe' => 'warning',
                                                    'moderate' => 'info',
                                                    'mild' => 'success',
                                                    'recovered' => 'secondary',
                                                    default => 'light'
                                                }; 
                                            ?>">
                                                <?php echo htmlspecialchars($case['status']); ?>
                                            </span>
                                            <?php if ($case['temperature']): ?>
                                            <span class="temperature-badge <?php 
                                                if ($case['temperature'] >= 39) echo 'temp-high';
                                                elseif ($case['temperature'] >= 37.5) echo 'temp-elevated';
                                                else echo 'temp-normal';
                                            ?>">
                                                <i class="fas fa-thermometer-half me-1"></i>
                                                <?php echo $case['temperature']; ?>°C
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($case['symptoms']): ?>
                                    <div class="mb-3">
                                        <label class="text-muted small">Symptoms</label>
                                        <div>
                                            <?php 
                                            $symptoms = explode(',', $case['symptoms']);
                                            foreach ($symptoms as $symptom): 
                                            ?>
                                            <span class="symptoms-tag"><?php echo trim(htmlspecialchars($symptom)); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($case['notes']): ?>
                                    <div class="mb-3">
                                        <label class="text-muted small">Medical Notes</label>
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($case['notes'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                                                                           
                                    <div class="btn-group btn-group-sm">
                                        <!-- Edit Case Button - Direct Link -->
                                        <a href="edit_case.php?id=<?php echo $case['case_id']; ?>" 
                                        class="btn btn-outline-primary"
                                        title="Edit Case #<?php echo $case['case_id']; ?>">
                                            <i class="fas fa-edit"></i>
                                            <span class="d-none d-md-inline ms-1">Edit</span>
                                        </a>
                                        
                                        <!-- Delete Case Button - Direct Link with Confirmation -->
                                        <a href="delete_case.php?id=<?php echo $case['case_id']; ?>&patient_id=<?php echo $patient_id; ?>" 
                                        class="btn btn-outline-danger"
                                        title="Delete Case #<?php echo $case['case_id']; ?>"
                                        onclick="return confirm('Are you sure you want to delete this case? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i>
                                            <span class="d-none d-md-inline ms-1">Delete</span>
                                        </a>
                                        
                                        <!-- View Case Details Button (Optional) -->
                                        <a href="view_case.php?id=<?php echo $case['case_id']; ?>" 
                                        class="btn btn-outline-info"
                                        title="View Case Details">
                                            <i class="fas fa-eye"></i>
                                            <span class="d-none d-md-inline ms-1">View</span>
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        
    </main>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Action functions
function addNewCase() {
    window.location.href = `add_case.php?patient_id=<?php echo $patient_id; ?>`;
}

function editPatient() {
    // Fixed: Should edit patient info, not case
    window.location.href = `edit_patient.php?id=<?php echo $patient_id; ?>`;
}

function editCase(caseId) {
    console.log('Editing case:', caseId);
    window.location.href = `cases/edit_case.php?id=${caseId}`;
}

function deleteCase(caseId) {
    if (confirm('Are you sure you want to delete this case? This action cannot be undone.')) {
        window.location.href = `cases/delete_case.php?id=${caseId}&patient_id=<?php echo $patient_id; ?>`;
    }
}
function deletePatient() {
    if (confirm('Are you sure you want to delete this patient and all their cases? This action cannot be undone.')) {
        window.location.href = `delete_patient.php?id=<?php echo $patient_id; ?>`;
    }
}

function generateReport() {
    // Use the PDF export as the main report
    exportPatientPDF();
}

function sendAlert() {
    // Enhanced alert functionality
    if (confirm('Send alert for this patient?')) {
        fetch('send_patient_alert.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                patient_id: <?php echo $patient_id; ?>,
                type: 'patient_alert'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Alert sent successfully!');
            } else {
                alert('Failed to send alert: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error sending alert');
        });
    }
}

function exportCaseHistory() {
    window.location.href = `export_case_history.php?patient_id=<?php echo $patient_id; ?>`;
}

function exportPatientPDF() {
    // Show loading indicator
    const originalText = event.target.innerHTML;
    event.target.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating PDF...';
    event.target.disabled = true;
    
    // Open PDF export in new window
    window.open(`export_patient_pdf.php?patient_id=<?php echo $patient_id; ?>`, '_blank');
    
    // Reset button after a short delay
    setTimeout(() => {
        event.target.innerHTML = originalText;
        event.target.disabled = false;
    }, 2000);
}

// Additional utility functions
function viewCaseDetails(caseId) {
    window.location.href = `view_case.php?id=${caseId}`;
}

function printPatientProfile() {
    window.print();
}

function sharePatientLink() {
    if (navigator.share) {
        navigator.share({
            title: 'Patient Profile',
            url: window.location.href
        });
    } else {
        // Fallback: copy to clipboard
        navigator.clipboard.writeText(window.location.href).then(() => {
            alert('Patient profile link copied to clipboard!');
        });
    }
}
</script>

</body>
</html>