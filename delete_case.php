<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

$case_id = $_GET['id'] ?? null;

if (!$case_id) {
    $_SESSION['error'] = 'No case ID provided';
    header('Location: patients.php');
    exit;
}

// Fetch case data for confirmation
$db = getDBConnection();
$stmt = $db->prepare("
    SELECT pc.*, p.first_name, p.last_name, p.patient_id
    FROM patient_cases pc
    JOIN patients p ON pc.patient_id = p.patient_id
    WHERE pc.case_id = ?
");
$stmt->execute([$case_id]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    $_SESSION['error'] = 'Case not found';
    header('Location: patients.php');
    exit;
}

// Handle deletion
if ($_POST && isset($_POST['confirm_delete'])) {
    try {
        $deleteStmt = $db->prepare("DELETE FROM patient_cases WHERE case_id = ?");
        $deleteStmt->execute([$case_id]);
        
        $_SESSION['success'] = 'Case deleted successfully';
        header('Location: view_patient.php?id=' . $case['patient_id']);
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error deleting case: ' . $e->getMessage();
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
    <title>Delete Case - Dengue Early Warning System</title>
    <meta name="description" content="Delete patient case">
    
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
                <h1 class="page-title mb-0">Delete Case #<?php echo $case_id; ?></h1>
                <small class="text-muted">Confirm case deletion</small>
            </div>
            <div>
                <a href="view_patient.php?id=<?php echo $case['patient_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Patient
                </a>
            </div>
        </header>

        <!-- Warning Alert -->
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
            <div>
                <strong>Warning: This action cannot be undone!</strong>
                <div>You are about to permanently delete this case record. This will remove all associated data.</div>
            </div>
        </div>

        <!-- Case Information Card -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <i class="fas fa-file-medical me-2"></i>
                            Case Information to be Deleted
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Case ID</label>
                                    <div class="info-value">#<?php echo $case_id; ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Patient Name</label>
                                    <div class="info-value"><?php echo htmlspecialchars($case['first_name'] . ' ' . $case['last_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Date Reported</label>
                                    <div class="info-value"><?php echo date('M j, Y', strtotime($case['date_reported'])); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-4">
                                <div class="info-item">
                                    <label class="info-label">Current Status</label>
                                    <div class="info-value">
                                        <span class="badge bg-<?php 
                                            echo $case['status'] == 'Mild' ? 'success' : 
                                                ($case['status'] == 'Moderate' ? 'warning' : 
                                                ($case['status'] == 'Severe' ? 'danger' : 
                                                ($case['status'] == 'Critical' ? 'dark' : 
                                                ($case['status'] == 'Recovered' ? 'info' : 'secondary')))); 
                                        ?>">
                                            <?php echo htmlspecialchars($case['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="info-item">
                                    <label class="info-label">Symptoms</label>
                                    <div class="info-value"><?php echo htmlspecialchars($case['symptoms'] ?: 'No symptoms recorded'); ?></div>
                                </div>
                            </div>
                        </div>
                        <?php if ($case['notes']): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="info-item">
                                    <label class="info-label">Notes</label>
                                    <div class="info-value"><?php echo htmlspecialchars($case['notes']); ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
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
                            Confirm Deletion
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning mb-4">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Before proceeding:</strong>
                            <ul class="mb-0 mt-2">
                                <li>This will permanently delete the case record</li>
                                <li>All associated data will be removed from the system</li>
                                <li>This action cannot be reversed</li>
                                <li>Consider archiving instead of deleting if data retention is required</li>
                            </ul>
                        </div>
                        
                        <form method="POST" id="deleteForm">
                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="confirmCheck" required>
                                    <label class="form-check-label" for="confirmCheck">
                                        I understand that this action is permanent and cannot be undone
                                    </label>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="view_patient.php?id=<?php echo $case['patient_id']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times me-1"></i> Cancel
                                </a>
                                <button type="submit" name="confirm_delete" class="btn btn-danger" id="deleteBtn" disabled>
                                    <i class="fas fa-trash me-1"></i> Delete Case Permanently
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
// Enable delete button only when checkbox is checked
document.addEventListener('DOMContentLoaded', function() {
    const confirmCheck = document.getElementById('confirmCheck');
    const deleteBtn = document.getElementById('deleteBtn');
    const deleteForm = document.getElementById('deleteForm');
    
    confirmCheck.addEventListener('change', function() {
        deleteBtn.disabled = !this.checked;
    });
    
    // Additional confirmation before submission
    deleteForm.addEventListener('submit', function(e) {
        if (!confirm('Are you absolutely sure you want to delete this case? This action cannot be undone.')) {
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

.alert ul {
    padding-left: 1.5rem;
}
</style>

</body>
</html>