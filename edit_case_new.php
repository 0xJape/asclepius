<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

$message = '';
$case_id = $_GET['id'] ?? null;

if (!$case_id) {
    header('Location: patients.php');
    exit;
}

// Fetch case data
$db = getDBConnection();
$stmt = $db->prepare("
    SELECT pc.*, p.first_name, p.last_name, p.age, p.gender, p.contact_number, 
           p.address, b.name as barangay_name
    FROM patient_cases pc
    JOIN patients p ON pc.patient_id = p.patient_id
    JOIN barangays b ON p.barangay_id = b.barangay_id
    WHERE pc.case_id = ?
");
$stmt->execute([$case_id]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    header('Location: patients.php');
    exit;
}

// Handle form submission
if ($_POST) {
    $status = $_POST['status'];
    $symptoms = $_POST['symptoms'];
    $notes = $_POST['notes'];
    
    $updateStmt = $db->prepare("
        UPDATE patient_cases 
        SET status = ?, symptoms = ?, notes = ?
        WHERE case_id = ?
    ");
    
    if ($updateStmt->execute([$status, $symptoms, $notes, $case_id])) {
        $message = 'Case updated successfully!';
        // Refresh case data
        $stmt->execute([$case_id]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $message = 'Error updating case.';
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
    <title>Edit Case - Dengue Early Warning System</title>
    <meta name="description" content="Edit patient case information">
    
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
        <!-- Header -->
        <header class="content-header d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="page-title mb-0">Edit Case #<?php echo $case_id; ?></h1>
                <small class="text-muted">Update patient case information</small>
            </div>
            <div>
                <a href="view_patient.php?id=<?php echo $case['patient_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Patient
                </a>
            </div>
        </header>

        <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Patient Information Card -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <i class="fas fa-user me-2"></i>
                            Patient Information
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Full Name</label>
                                    <div class="info-value"><?php echo htmlspecialchars($case['first_name'] . ' ' . $case['last_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="info-item">
                                    <label class="info-label">Age</label>
                                    <div class="info-value"><?php echo htmlspecialchars($case['age']); ?> years</div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="info-item">
                                    <label class="info-label">Gender</label>
                                    <div class="info-value"><?php echo htmlspecialchars($case['gender']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Contact Number</label>
                                    <div class="info-value"><?php echo htmlspecialchars($case['contact_number']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="info-item">
                                    <label class="info-label">Address</label>
                                    <div class="info-value"><?php echo htmlspecialchars($case['address']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-item">
                                    <label class="info-label">Barangay</label>
                                    <div class="info-value"><?php echo htmlspecialchars($case['barangay_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-item">
                                    <label class="info-label">Date Reported</label>
                                    <div class="info-value"><?php echo date('M j, Y', strtotime($case['date_reported'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Case Form -->
        <div class="row g-3">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <i class="fas fa-edit me-2"></i>
                            Edit Case Details
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="status" class="form-label">Case Status</label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="">Select Status</option>
                                        <option value="Active" <?php echo $case['status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Recovered" <?php echo $case['status'] == 'Recovered' ? 'selected' : ''; ?>>Recovered</option>
                                        <option value="Deceased" <?php echo $case['status'] == 'Deceased' ? 'selected' : ''; ?>>Deceased</option>
                                        <option value="Under Treatment" <?php echo $case['status'] == 'Under Treatment' ? 'selected' : ''; ?>>Under Treatment</option>
                                    </select>
                                    <div class="invalid-feedback">
                                        Please select a case status.
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Current Status</label>
                                    <div class="form-control-plaintext">
                                        <span class="badge bg-<?php 
                                            echo $case['status'] == 'Active' ? 'danger' : 
                                                ($case['status'] == 'Recovered' ? 'success' : 
                                                ($case['status'] == 'Deceased' ? 'dark' : 'warning')); 
                                        ?>">
                                            <?php echo htmlspecialchars($case['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row g-3 mt-2">
                                <div class="col-12">
                                    <label for="symptoms" class="form-label">Symptoms</label>
                                    <textarea class="form-control" id="symptoms" name="symptoms" rows="4" 
                                              placeholder="Describe patient symptoms..."><?php echo htmlspecialchars($case['symptoms']); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="row g-3 mt-2">
                                <div class="col-12">
                                    <label for="notes" class="form-label">Medical Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4" 
                                              placeholder="Additional medical notes, treatment progress, etc..."><?php echo htmlspecialchars($case['notes']); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="row g-3 mt-4">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between">
                                        <a href="view_patient.php?id=<?php echo $case['patient_id']; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times me-1"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i> Update Case
                                        </button>
                                    </div>
                                </div>
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
// Form validation
(function() {
    'use strict';
    window.addEventListener('load', function() {
        var forms = document.getElementsByClassName('needs-validation');
        var validation = Array.prototype.filter.call(forms, function(form) {
            form.addEventListener('submit', function(event) {
                if (form.checkValidity() === false) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    }, false);
})();

// Sidebar toggle for mobile
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }
});
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

.form-control-plaintext {
    padding-top: 0.375rem;
    padding-bottom: 0.375rem;
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
</style>

</body>
</html>
