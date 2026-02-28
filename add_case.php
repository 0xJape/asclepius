<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

$patient_id = $_GET['patient_id'] ?? null;
$from_add_patient = $_GET['from_add_patient'] ?? false;

if (!$patient_id) {
    $_SESSION['error'] = 'No patient ID provided';
    header('Location: patients.php');
    exit;
}

// Get patient information
$db = getDBConnection();
$stmt = $db->prepare("
    SELECT p.*, b.name as barangay_name, 
           TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
    FROM patients p 
    JOIN barangays b ON p.barangay_id = b.barangay_id 
    WHERE p.patient_id = ?
");
$stmt->execute([$patient_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    $_SESSION['error'] = 'Patient not found';
    header('Location: patients.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $date_reported = $_POST['date_reported'];
        $symptoms = trim($_POST['symptoms']);
        $status = $_POST['status'];
        $temperature = $_POST['temperature'] ? (float)$_POST['temperature'] : null;
        $notes = trim($_POST['notes']);
        
        // Basic validation
        $errors = [];
        
        if (empty($date_reported)) {
            $errors[] = "Date reported is required.";
        } else {
            // Check if date is not in the future
            if (strtotime($date_reported) > time()) {
                $errors[] = "Date reported cannot be in the future.";
            }
        }
        
        if (empty($status)) {
            $errors[] = "Please select a case status.";
        }
        
        if ($temperature && ($temperature < 35 || $temperature > 45)) {
            $errors[] = "Please enter a valid temperature (35-45°C).";
        }
        
        // If no errors, insert the case
        if (empty($errors)) {
            $insertQuery = "
                INSERT INTO patient_cases (patient_id, date_reported, symptoms, status, temperature, notes, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $insertStmt = $db->prepare($insertQuery);
            $success = $insertStmt->execute([
                $patient_id,
                $date_reported,
                $symptoms ?: null,
                $status,
                $temperature,
                $notes ?: null
            ]);
            
            if ($success) {
                $case_id = $db->lastInsertId();
                $_SESSION['success'] = "New case has been successfully added for {$patient['first_name']} {$patient['last_name']}.";
                header("Location: view_patient.php?id={$patient_id}");
                exit;
            } else {
                $errors[] = "Failed to add case. Please try again.";
            }
        }
        
    } catch (PDOException $e) {
        error_log("Error adding case: " . $e->getMessage());
        $errors[] = "Database error occurred. Please try again.";
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
    <title>Add Case - Dengue Early Warning System</title>
    <meta name="description" content="Add new case for patient">
    
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
                <h1 class="page-title mb-0">Add New Case</h1>
                <small class="text-muted">Create a new medical case record</small>
            </div>
            <div>
                <a href="view_patient.php?id=<?php echo $patient_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Patient
                </a>
            </div>
        </header>

        <!-- Display session messages -->
        <?php if (isset($_SESSION['info_message'])): ?>
        <div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-info-circle me-2"></i>
            <?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Display errors -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Please correct the following errors:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
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
                                    <div class="info-value"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                                </div>
                            </div>
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
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="info-item">
                                    <label class="info-label">Address</label>
                                    <div class="info-value"><?php echo htmlspecialchars($patient['address']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-item">
                                    <label class="info-label">Barangay</label>
                                    <div class="info-value"><?php echo htmlspecialchars($patient['barangay_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-item">
                                    <label class="info-label">Date of Birth</label>
                                    <div class="info-value"><?php echo date('M j, Y', strtotime($patient['date_of_birth'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Case Form -->
        <div class="row g-3">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <i class="fas fa-plus me-2"></i>
                            New Case Details
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="date_reported" class="form-label">Date Reported <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="date_reported" name="date_reported" 
                                           value="<?php echo $_POST['date_reported'] ?? date('Y-m-d'); ?>" 
                                           max="<?php echo date('Y-m-d'); ?>" required>
                                    <div class="invalid-feedback">
                                        Please provide a valid date.
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="status" class="form-label">Case Status <span class="text-danger">*</span></label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="">Select Status</option>
                                        <option value="Mild" <?php echo ($_POST['status'] ?? '') == 'Mild' ? 'selected' : ''; ?>>Mild</option>
                                        <option value="Moderate" <?php echo ($_POST['status'] ?? '') == 'Moderate' ? 'selected' : ''; ?>>Moderate</option>
                                        <option value="Severe" <?php echo ($_POST['status'] ?? '') == 'Severe' ? 'selected' : ''; ?>>Severe</option>
                                        <option value="Critical" <?php echo ($_POST['status'] ?? '') == 'Critical' ? 'selected' : ''; ?>>Critical</option>
                                    </select>
                                    <div class="invalid-feedback">
                                        Please select a case status.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row g-3 mt-2">
                                <div class="col-md-6">
                                    <label for="temperature" class="form-label">Temperature (°C)</label>
                                    <input type="number" class="form-control" id="temperature" name="temperature" 
                                           value="<?php echo $_POST['temperature'] ?? ''; ?>" 
                                           min="35" max="45" step="0.1" placeholder="e.g., 38.5">
                                    <div class="form-text">Normal range: 36.0 - 37.5°C</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="symptoms" class="form-label">Symptoms</label>
                                    <textarea class="form-control" id="symptoms" name="symptoms" rows="3" 
                                              placeholder="Describe patient symptoms..."><?php echo htmlspecialchars($_POST['symptoms'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="row g-3 mt-2">
                                <div class="col-12">
                                    <label for="notes" class="form-label">Medical Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4" 
                                              placeholder="Additional medical notes, treatment details, etc..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="row g-3 mt-4">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between">
                                        <a href="<?php echo $from_add_patient ? 'add_patient.php' : 'view_patient.php?id=' . $patient_id; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times me-1"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-plus me-1"></i> Add Case
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
