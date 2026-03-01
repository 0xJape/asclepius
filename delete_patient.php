<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

$patient_id = $_GET['id'] ?? null;

if (!$patient_id) {
    $_SESSION['error'] = 'No patient ID provided';
    header('Location: patients.php');
    exit;
}

// Fetch patient data and case count for confirmation
$db = getDBConnection();
$stmt = $db->prepare("
    SELECT p.*, b.name as barangay_name, 
           TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
           COUNT(pc.case_id) as case_count
    FROM patients p
    JOIN barangays b ON p.barangay_id = b.barangay_id
    LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id
    WHERE p.patient_id = ?
    GROUP BY p.patient_id
");
$stmt->execute([$patient_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    $_SESSION['error'] = 'Patient not found';
    header('Location: patients.php');
    exit;
}

// Handle deletion
if ($_POST && isset($_POST['confirm_delete'])) {
    try {
        $db->beginTransaction();
        
        // First delete all patient cases
        $deleteCasesStmt = $db->prepare("DELETE FROM patient_cases WHERE patient_id = ?");
        $deleteCasesStmt->execute([$patient_id]);
        
        // Then delete the patient
        $deletePatientStmt = $db->prepare("DELETE FROM patients WHERE patient_id = ?");
        $deletePatientStmt->execute([$patient_id]);
        
        $db->commit();
        
        $_SESSION['success'] = "Patient '{$patient['first_name']} {$patient['last_name']}' and all associated cases have been successfully deleted.";
        header('Location: patients.php');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = 'Error deleting patient: ' . $e->getMessage();
    }
}

// Get dashboard stats for sidebar
$statsData = getDashboardStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Delete Patient - Dengue Early Warning System</title>
    <meta name="description" content="Delete patient record">
    
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Google Fonts - Poppins (Display) + Inter (Body) -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/dengue_logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/dengue_logo.png">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Modern Design System CSS -->
    <link href="assets/css/modern.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="assets/css/dashboard.css" rel="stylesheet">
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
        
        <div class="sidebar-footer">
            <div class="user-profile">
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                    <div class="user-role">Administrator</div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="content-header d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="page-title mb-0">Delete Patient</h1>
                <small class="text-muted">Permanently remove patient and all associated records</small>
            </div>
            <div>
                <a href="view_patient.php?id=<?php echo $patient_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Patient
                </a>
            </div>
        </header>

        <!-- Critical Warning Alert -->
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <div class="d-flex align-items-start">
                <i class="fas fa-exclamation-triangle fa-2x me-3 mt-1"></i>
                <div>
                    <h5 class="alert-heading mb-2">
                        <strong>CRITICAL ACTION: This operation cannot be undone!</strong>
                    </h5>
                    <p class="mb-2">You are about to permanently delete this patient and <strong>ALL</strong> associated medical records.</p>
                    <hr>
                    <p class="mb-0">
                        <strong>This will remove:</strong>
                    </p>
                    <ul class="mb-0 mt-1">
                        <li>Patient personal information</li>
                        <li>All medical case records (<?php echo $patient['case_count']; ?> cases)</li>
                        <li>Medical history and notes</li>
                        <li>Contact and address information</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Patient Information Card -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-danger">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0">
                            <i class="fas fa-user me-2"></i>
                            Patient Information to be Deleted
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Patient ID</label>
                                    <div class="info-value">#<?php echo $patient_id; ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Full Name</label>
                                    <div class="info-value"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Date of Birth</label>
                                    <div class="info-value"><?php echo date('M j, Y', strtotime($patient['date_of_birth'])); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-2">
                                <div class="info-item">
                                    <label class="info-label">Age</label>
                                    <div class="info-value"><?php echo htmlspecialchars($patient['age']); ?> years</div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="info-item">
                                    <label class="info-label">Gender</label>
                                    <div class="info-value"><?php echo htmlspecialchars($patient['gender']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Contact Number</label>
                                    <div class="info-value"><?php echo htmlspecialchars($patient['contact_number'] ?: 'Not provided'); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Barangay</label>
                                    <div class="info-value"><?php echo htmlspecialchars($patient['barangay_name']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-8">
                                <div class="info-item">
                                    <label class="info-label">Address</label>
                                    <div class="info-value"><?php echo htmlspecialchars($patient['address']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Medical Cases</label>
                                    <div class="info-value">
                                        <span class="badge bg-warning text-dark fs-6">
                                            <?php echo $patient['case_count']; ?> case<?php echo $patient['case_count'] != 1 ? 's' : ''; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="info-item">
                                    <label class="info-label">Registration Date</label>
                                    <div class="info-value"><?php echo date('M j, Y', strtotime($patient['created_at'])); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-item">
                                    <label class="info-label">Last Updated</label>
                                    <div class="info-value"><?php echo date('M j, Y', strtotime($patient['updated_at'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Impact Assessment -->
        <?php if ($patient['case_count'] > 0): ?>
        <div class="card shadow-sm border-warning mb-4">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Data Impact Assessment
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-warning mb-3">
                    <strong>Medical Data Loss Warning:</strong> This patient has active medical records that will be permanently lost.
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-warning"><i class="fas fa-chart-line me-2"></i>Analytics Impact</h6>
                        <ul class="small">
                            <li>Barangay case statistics will be recalculated</li>
                            <li>Historical trend data will be affected</li>
                            <li>Reports may show data discrepancies</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-warning"><i class="fas fa-database me-2"></i>Data Integrity</h6>
                        <ul class="small">
                            <li><?php echo $patient['case_count']; ?> medical case records will be deleted</li>
                            <li>All symptoms and treatment notes will be lost</li>
                            <li>Contact tracing data may be affected</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alternatives Section -->
        <div class="card shadow-sm border-info mb-4">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0">
                    <i class="fas fa-lightbulb me-2"></i>
                    Consider These Alternatives
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="d-flex align-items-start mb-3">
                            <div class="bg-success bg-opacity-10 text-success rounded-circle p-2 me-3">
                                <i class="fas fa-edit"></i>
                            </div>
                            <div>
                                <h6 class="mb-1">Edit Patient Information</h6>
                                <p class="mb-1 small text-muted">Update incorrect information instead of deleting</p>
                                <a href="edit_patient.php?id=<?php echo $patient_id; ?>" class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-edit me-1"></i>Edit Patient
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start mb-3">
                            <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-2 me-3">
                                <i class="fas fa-archive"></i>
                            </div>
                            <div>
                                <h6 class="mb-1">Archive Patient Record</h6>
                                <p class="mb-1 small text-muted">Hide from active lists but preserve data</p>
                                <button class="btn btn-sm btn-outline-warning" onclick="archivePatient()">
                                    <i class="fas fa-archive me-1"></i>Archive Instead
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirmation Form -->
        <div class="row g-3">
            <div class="col-12">
                <div class="card shadow-sm border-danger">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0">
                            <i class="fas fa-trash me-2"></i>
                            Final Confirmation Required
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger mb-4">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="acknowledgeDataLoss" required>
                                <label class="form-check-label" for="acknowledgeDataLoss">
                                    <strong>I understand that all patient data and medical records will be permanently deleted</strong>
                                </label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="acknowledgeImpact" required>
                                <label class="form-check-label" for="acknowledgeImpact">
                                    <strong>I acknowledge the impact on system analytics and reporting</strong>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="confirmAuthority" required>
                                <label class="form-check-label" for="confirmAuthority">
                                    <strong>I have the authority to perform this deletion and take full responsibility</strong>
                                </label>
                            </div>
                        </div>
                        
                        <form method="POST" id="deleteForm">
                            <div class="mb-4">
                                <label for="confirmationText" class="form-label">
                                    <strong>Type "DELETE <?php echo strtoupper($patient['last_name']); ?>" to confirm:</strong>
                                </label>
                                <input type="text" class="form-control" id="confirmationText" 
                                       placeholder="Type the confirmation text exactly as shown above" required>
                                <div class="form-text">This action requires typing the exact confirmation text.</div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <a href="view_patient.php?id=<?php echo $patient_id; ?>" class="btn btn-secondary me-2">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </a>
                                    <a href="patients.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-list me-1"></i> Back to List
                                    </a>
                                </div>
                                <button type="submit" name="confirm_delete" class="btn btn-danger" id="deleteBtn" disabled>
                                    <i class="fas fa-trash me-1"></i> DELETE PATIENT PERMANENTLY
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Custom JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const deleteBtn = document.getElementById('deleteBtn');
    const deleteForm = document.getElementById('deleteForm');
    const confirmationText = document.getElementById('confirmationText');
    const checkboxes = document.querySelectorAll('input[type="checkbox"]');
    
    const requiredText = 'DELETE <?php echo strtoupper($patient['last_name']); ?>';
    
    function checkFormValidity() {
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        const textMatches = confirmationText.value.trim() === requiredText;
        
        deleteBtn.disabled = !(allChecked && textMatches);
        
        if (textMatches) {
            confirmationText.classList.remove('is-invalid');
            confirmationText.classList.add('is-valid');
        } else if (confirmationText.value.trim() !== '') {
            confirmationText.classList.remove('is-valid');
            confirmationText.classList.add('is-invalid');
        }
    }
    
    // Add event listeners
    checkboxes.forEach(cb => cb.addEventListener('change', checkFormValidity));
    confirmationText.addEventListener('input', checkFormValidity);
    
    // Final confirmation before submission
    deleteForm.addEventListener('submit', function(e) {
        if (!confirm('Are you absolutely certain you want to delete this patient and all associated records? This action CANNOT be undone.')) {
            e.preventDefault();
        }
    });
    
    // Sidebar toggle for mobile
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }
});

function archivePatient() {
    if (confirm('Archive functionality is not yet implemented. Would you like to proceed with editing the patient instead?')) {
        window.location.href = 'edit_patient.php?id=<?php echo $patient_id; ?>';
    }
}
</script>

<style>
.info-item {
    margin-bottom: 1rem;
}

.info-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #6c757d;
    display: block;
    margin-bottom: 0.25rem;
}

.info-value {
    font-size: 0.95rem;
    color: #212529;
    font-weight: 500;
}

.card-header {
    border-bottom: 1px solid #e9ecef;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #212529;
}

.content-header {
    padding-bottom: 1rem;
    border-bottom: 1px solid #e9ecef;
}

.border-danger {
    border-color: #dc3545 !important;
}

.border-warning {
    border-color: #ffc107 !important;
}

.border-info {
    border-color: #0dcaf0 !important;
}

.alert ul {
    padding-left: 1.5rem;
}

.form-check-input:checked {
    background-color: #dc3545;
    border-color: #dc3545;
}

.is-valid {
    border-color: #198754;
}

.is-invalid {
    border-color: #dc3545;
}
</style>

</body>
</html>
