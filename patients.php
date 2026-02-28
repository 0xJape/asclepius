<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$order = isset($_GET['order']) ? $_GET['order'] : 'asc';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$barangayFilter = isset($_GET['barangay']) ? $_GET['barangay'] : '';

// Get all patients with their latest case information
function getAllPatients($search = '', $sortBy = 'name', $order = 'asc', $statusFilter = '', $barangayFilter = '') {
    $db = getDBConnection();
    
    try {
        $whereConditions = [];
        $params = [];
        
        // Search condition
        if (!empty($search)) {
            $whereConditions[] = "(CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.contact_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        // Status filter
        if (!empty($statusFilter)) {
            $whereConditions[] = "pc.status = ?";
            $params[] = $statusFilter;
        }
        
        // Barangay filter
        if (!empty($barangayFilter)) {
            $whereConditions[] = "b.barangay_id = ?";
            $params[] = $barangayFilter;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Determine sort column
        $sortColumn = match($sortBy) {
            'name' => 'CONCAT(p.first_name, " ", p.last_name)',
            'age' => 'TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE())',
            'barangay' => 'b.name',
            'status' => 'pc.status',
            'date' => 'pc.date_reported',
            default => 'CONCAT(p.first_name, " ", p.last_name)'
        };
        
        $orderClause = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        
        $query = "
            SELECT DISTINCT
                p.patient_id,
                p.first_name,
                p.last_name,
                p.date_of_birth,
                p.gender,
                p.contact_number,
                p.address,
                b.name as barangay_name,
                b.barangay_id,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
                pc.status as latest_status,
                pc.date_reported as latest_case_date,
                pc.case_id as latest_case_id,
                COUNT(pc2.case_id) as total_cases
            FROM patients p
            LEFT JOIN barangays b ON p.barangay_id = b.barangay_id
            LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id AND pc.date_reported = (
                SELECT MAX(date_reported) 
                FROM patient_cases pc_inner 
                WHERE pc_inner.patient_id = p.patient_id
            )
            LEFT JOIN patient_cases pc2 ON p.patient_id = pc2.patient_id
            $whereClause
            GROUP BY p.patient_id, p.first_name, p.last_name, p.date_of_birth, p.gender, 
                     p.contact_number, p.address, b.name, b.barangay_id, pc.status, 
                     pc.date_reported, pc.case_id
            ORDER BY $sortColumn $orderClause
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Error getting patients: " . $e->getMessage());
        return [];
    }
}

// Get barangays for filter dropdown
function getBarangays() {
    $db = getDBConnection();
    try {
        $stmt = $db->query("SELECT barangay_id, name FROM barangays ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("Error getting barangays: " . $e->getMessage());
        return [];
    }
}

$patients = getAllPatients($search, $sortBy, $order, $statusFilter, $barangayFilter);
$barangays = getBarangays();
$totalPatients = count($patients);

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportPatientsToCSV($patients, $search, $statusFilter, $barangayFilter);
    exit;
}

function exportPatientsToCSV($patients, $search = '', $statusFilter = '', $barangayFilter = '') {
    // Set headers for CSV download
    $filename = 'Patient Details' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for proper UTF-8 encoding in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV Headers
    $headers = [
        'Patient ID',
        'First Name',
        'Last Name',
        'Full Name',
        'Age',
        'Gender',
        'Date of Birth',
        'Contact Number',
        'Address',
        'Barangay',
        'Latest Case Status',
        'Latest Case Date',
        'Total Cases',
        'Latest Case ID',
        'Export Date',
        'Export Filters'
    ];
    
    fputcsv($output, $headers);
    
    // Prepare filter info
    $filterInfo = [];
    if (!empty($search)) $filterInfo[] = "Search: $search";
    if (!empty($statusFilter)) $filterInfo[] = "Status: $statusFilter";
    if (!empty($barangayFilter)) {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT name FROM barangays WHERE barangay_id = ?");
        $stmt->execute([$barangayFilter]);
        $barangayName = $stmt->fetchColumn();
        if ($barangayName) $filterInfo[] = "Barangay: $barangayName";
    }
    $filterString = !empty($filterInfo) ? implode(', ', $filterInfo) : 'No filters applied';
    
    // Add data rows
    foreach ($patients as $patient) {
        $row = [
            $patient['patient_id'],
            $patient['first_name'],
            $patient['last_name'],
            $patient['first_name'] . ' ' . $patient['last_name'],
            $patient['age'],
            $patient['gender'],
            date('Y-m-d', strtotime($patient['date_of_birth'])),
            $patient['contact_number'] ?: 'N/A',
            $patient['address'],
            $patient['barangay_name'],
            $patient['latest_status'] ?: 'No cases',
            $patient['latest_case_date'] ? date('Y-m-d', strtotime($patient['latest_case_date'])) : 'N/A',
            $patient['total_cases'],
            $patient['latest_case_id'] ?: 'N/A',
            date('Y-m-d H:i:s'),
            $filterString
        ];
        
        fputcsv($output, $row);
    }
    
    // Add summary row
    fputcsv($output, []); // Empty row
    fputcsv($output, ['EXPORT SUMMARY']);
    fputcsv($output, ['Total Patients Exported', count($patients)]);
    fputcsv($output, ['Export Date', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Exported By', $_SESSION['user_name'] ?? 'System User']);
    fputcsv($output, ['Applied Filters', $filterString]);
    
    fclose($output);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients - Dengue Monitoring System</title>
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
        .patient-card {
            transition: all 0.3s ease;
            border-left: 4px solid #e9ecef;
        }
        
        .patient-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .patient-card.status-critical {
            border-left-color: #dc3545;
        }
        
        .patient-card.status-severe {
            border-left-color: #fd7e14;
        }
        
        .patient-card.status-moderate {
            border-left-color: #ffc107;
        }
        
        .patient-card.status-mild {
            border-left-color: #28a745;
        }
        
        .patient-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .search-filters {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .sort-badge {
            background: #e9ecef;
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: 0.875rem;
            margin-left: 0.5rem;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .cases-timeline {
            max-height: 200px;
            overflow-y: auto;
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
        <!-- Header -->
        <header class="content-header d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="page-title mb-0">Patient Management</h1>
                <small class="text-muted">Manage patient records and case histories</small>
            </div>
            <div>
                <a href="add_patient.php" class="btn btn-success me-2">
                    <i class="fas fa-plus me-1"></i> Add Patient
                </a>
                <button class="btn btn-primary" onclick="exportPatients()" title="Export current patient list to CSV">
                    <i class="fas fa-download me-1"></i> Export CSV
                </button>
            </div>
        </header>

        <!-- Search and Filters -->
        <div class="search-filters">
            <form method="GET" action="patients.php" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Search Patients</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" name="search" 
                               placeholder="Search by name or contact..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All Status</option>
                        <option value="Critical" <?php echo $statusFilter === 'Critical' ? 'selected' : ''; ?>>Critical</option>
                        <option value="Severe" <?php echo $statusFilter === 'Severe' ? 'selected' : ''; ?>>Severe</option>
                        <option value="Moderate" <?php echo $statusFilter === 'Moderate' ? 'selected' : ''; ?>>Moderate</option>
                        <option value="Mild" <?php echo $statusFilter === 'Mild' ? 'selected' : ''; ?>>Mild</option>
                        <option value="Recovered" <?php echo $statusFilter === 'Recovered' ? 'selected' : ''; ?>>Recovered</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Barangay</label>
                    <select class="form-select" name="barangay">
                        <option value="">All Barangays</option>
                        <?php foreach ($barangays as $barangay): ?>
                        <option value="<?php echo $barangay['barangay_id']; ?>" 
                                <?php echo $barangayFilter == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($barangay['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Sort By</label>
                    <select class="form-select" name="sort">
                        <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name</option>
                        <option value="age" <?php echo $sortBy === 'age' ? 'selected' : ''; ?>>Age</option>
                        <option value="barangay" <?php echo $sortBy === 'barangay' ? 'selected' : ''; ?>>Barangay</option>
                        <option value="status" <?php echo $sortBy === 'status' ? 'selected' : ''; ?>>Status</option>
                        <option value="date" <?php echo $sortBy === 'date' ? 'selected' : ''; ?>>Latest Case</option>
                    </select>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label">Order</label>
                    <select class="form-select" name="order">
                        <option value="asc" <?php echo $order === 'asc' ? 'selected' : ''; ?>>↑</option>
                        <option value="desc" <?php echo $order === 'desc' ? 'selected' : ''; ?>>↓</option>
                    </select>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block w-100">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Results Summary -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h6 class="mb-0">
                    <?php echo $totalPatients; ?> Patient<?php echo $totalPatients !== 1 ? 's' : ''; ?> Found
                    <?php if (!empty($search)): ?>
                        <span class="sort-badge">
                            <i class="fas fa-search"></i> "<?php echo htmlspecialchars($search); ?>"
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($statusFilter)): ?>
                        <span class="sort-badge">
                            <i class="fas fa-filter"></i> <?php echo htmlspecialchars($statusFilter); ?>
                        </span>
                    <?php endif; ?>
                </h6>
            </div>
            <div>
                <?php if (!empty($search) || !empty($statusFilter) || !empty($barangayFilter)): ?>
                <a href="patients.php" class="btn btn-link btn-sm">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Patient Cards -->
        <div class="row g-4">
            <?php if (empty($patients)): ?>
                <div class="col-12">
                    <div class="card text-center py-5">
                        <div class="card-body">
                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No patients found</h5>
                            <p class="text-muted">Try adjusting your search criteria or add a new patient.</p>
                            <a href="add_patient.php" class="btn btn-primary">
                                <i class="fas fa-plus me-1"></i> Add First Patient
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($patients as $patient): ?>
                <div class="col-lg-6 col-xl-4">
                    <div class="card patient-card status-<?php echo strtolower($patient['latest_status'] ?? 'mild'); ?> h-100">
                        <div class="card-body">
                            <!-- Patient Header -->
                            <div class="d-flex align-items-center mb-3">
                                <div class="patient-avatar me-3">
                                    <?php echo strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1)); ?>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-0 fw-bold">
                                        <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                                    </h6>
                                    <small class="text-muted">
                                        <i class="fas fa-id-card me-1"></i>
                                        ID: <?php echo $patient['patient_id']; ?>
                                    </small>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                            data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="view_patient.php?id=<?php echo $patient['patient_id']; ?>">
                                            <i class="fas fa-eye me-2"></i>View Details
                                        </a></li>  
                                        <li><a class="dropdown-item" href="add_case.php?patient_id=<?php echo $patient['patient_id']; ?>">
                                            <i class="fas fa-plus me-2"></i>Add Case
                                        </a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="deletePatient(<?php echo $patient['patient_id']; ?>)">
                                            <i class="fas fa-trash me-2"></i>Delete
                                        </a></li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Patient Info -->
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <small class="text-muted d-block">Age</small>
                                    <span class="fw-medium"><?php echo $patient['age']; ?> years</span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Gender</small>
                                    <span class="fw-medium">
                                        <i class="fas fa-<?php echo strtolower($patient['gender']) === 'male' ? 'mars' : 'venus'; ?> me-1"></i>
                                        <?php echo htmlspecialchars($patient['gender']); ?>
                                    </span>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted d-block">Barangay</small>
                                    <span class="fw-medium">
                                        <i class="fas fa-map-marker-alt me-1"></i>
                                        <?php echo htmlspecialchars($patient['barangay_name']); ?>
                                    </span>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted d-block">Contact</small>
                                    <span class="fw-medium">
                                        <i class="fas fa-phone me-1"></i>
                                        <?php echo htmlspecialchars($patient['contact_number'] ?? 'N/A'); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Latest Case Status -->
                            <?php if ($patient['latest_status']): ?>
                            <div class="mb-3">
                                <small class="text-muted d-block mb-1">Latest Case Status</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-<?php 
                                        echo match(strtolower($patient['latest_status'])) {
                                            'critical' => 'danger',
                                            'severe' => 'warning',
                                            'moderate' => 'info',
                                            'mild' => 'success',
                                            'recovered' => 'secondary',
                                            default => 'light'
                                        }; 
                                    ?> px-2 py-1">
                                        <span class="status-dot bg-white bg-opacity-75"></span>
                                        <?php echo htmlspecialchars($patient['latest_status']); ?>
                                    </span>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($patient['latest_case_date'])); ?>
                                    </small>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Case Summary -->
                            <div class="bg-light p-2 rounded">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Total Cases</small>
                                    <span class="badge bg-primary"><?php echo $patient['total_cases']; ?></span>
                                </div>
                                <?php if ($patient['latest_case_id']): ?>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <small class="text-muted">Latest Case ID</small>
                                    <small class="fw-medium">#<?php echo $patient['latest_case_id']; ?></small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="card-footer bg-transparent">
                            <div class="d-flex gap-2">
                                <a href="view_patient.php?id=<?php echo $patient['patient_id']; ?>" 
                                   class="btn btn-outline-primary btn-sm flex-fill">
                                    <i class="fas fa-eye me-1"></i> View
                                </a>
                                <a href="add_case.php?patient_id=<?php echo $patient['patient_id']; ?>" 
                                   class="btn btn-primary btn-sm flex-fill">
                                    <i class="fas fa-plus me-1"></i> Add Case
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function deletePatient(patientId) {
    if (confirm('Are you sure you want to delete this patient? This action cannot be undone.')) {
        window.location.href = `delete_patient.php?id=${patientId}`;
    }
}

function exportPatients() {
    // Get current URL parameters to maintain filters in export
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    
    // Show loading indication
    const exportBtn = event.target;
    const originalText = exportBtn.innerHTML;
    exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Exporting...';
    exportBtn.disabled = true;
    
    // Create temporary link for download
    const exportUrl = `patients.php?${params.toString()}`;
    
    // Trigger download
    window.location.href = exportUrl;
    
    // Reset button after a short delay
    setTimeout(() => {
        exportBtn.innerHTML = originalText;
        exportBtn.disabled = false;
    }, 2000);
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

</body>
</html>