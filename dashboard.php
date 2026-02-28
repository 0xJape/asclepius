<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

// Fetch initial dashboard data
$statsData = getDashboardStats();
$alerts = getActiveAlerts();
$recentCases = getRecentCases();

// Allow optional ?days= on page load to limit barangay case window
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;

// If getBarangayCases supports a parameter, prefer that; otherwise run local query
if (function_exists('getBarangayCases')) {
    try {
        // Use the function if it accepts a parameter
        $ref = new ReflectionFunction('getBarangayCases');
        if ($ref->getNumberOfParameters() > 0) {
            $barangayData = getBarangayCases($days);
        } else {
            // Fallback to local SQL
            $db = getDBConnection();
            $stmt = $db->prepare(
                "SELECT b.barangay_id, b.name, b.latitude, b.longitude, b.population,
                    COUNT(pc.case_id) as case_count
                 FROM barangays b
                 LEFT JOIN patients p ON b.barangay_id = p.barangay_id
                 LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id AND pc.date_reported >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
                 GROUP BY b.barangay_id, b.name, b.latitude, b.longitude, b.population
                 ORDER BY case_count DESC"
            );
            $stmt->execute([$days]);
            $barangayData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (ReflectionException $e) {
        // If reflection fails, fall back to local query
        $db = getDBConnection();
        $stmt = $db->prepare(
            "SELECT b.barangay_id, b.name, b.latitude, b.longitude, b.population,
                COUNT(pc.case_id) as case_count
             FROM barangays b
             LEFT JOIN patients p ON b.barangay_id = p.barangay_id
             LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id AND pc.date_reported >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
             GROUP BY b.barangay_id, b.name, b.latitude, b.longitude, b.population
             ORDER BY case_count DESC"
        );
        $stmt->execute([$days]);
        $barangayData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $barangayData = [];
}

// Debugging output
echo "<!-- DEBUG: Barangay Data -->";
echo "<!-- " . print_r($barangayData, true) . " -->";

// Raw data query for debugging
$debug_query = "
    SELECT 
        b.barangay_id,
        b.name,
        b.latitude,
        b.longitude,
        b.population,
        COUNT(pc.case_id) as case_count,
        COUNT(DISTINCT p.patient_id) as unique_patients
    FROM barangays b
    LEFT JOIN patients p ON b.barangay_id = p.barangay_id
    LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id 
        AND pc.date_reported >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
    GROUP BY b.barangay_id, b.name, b.latitude, b.longitude, b.population
    ORDER BY case_count DESC
";

try {
    $db = getDBConnection();
    $stmt = $db->prepare($debug_query);
    $stmt->execute();
    $debug_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<!-- DEBUG QUERY RESULTS: " . print_r($debug_results, true) . " -->";
} catch(Exception $e) {
    echo "<!-- DEBUG ERROR: " . $e->getMessage() . " -->";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dengue Early Warning System - Dashboard</title>
    <meta name="description" content="Real-time dengue monitoring and early warning system dashboard">
    
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
    
    <!-- Maps -->
    <link href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" rel="stylesheet">
    
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Modern Design System CSS -->
    <link href="assets/css/modern.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="assets/css/dashboard.css" rel="stylesheet">
    
    <style>
        /* Custom map popup styling */
        .custom-popup .leaflet-popup-content-wrapper {
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            border: none;
        }
        
        .custom-popup .leaflet-popup-content {
            margin: 12px;
            font-family: 'Inter', sans-serif;
        }
        
        .custom-popup .leaflet-popup-tip {
            background: white;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        /* Map container improvements */
        #map {
            border-radius: 0 0 8px 8px;
            overflow: hidden;
        }
        
        /* Ensure markers are above polygons and clickable */
        .leaflet-marker-pane {
            z-index: 600 !important;
        }
        
        .leaflet-overlay-pane {
            z-index: 200 !important;
        }
        
        /* Make circle markers more visually prominent and clearly clickable */
        .leaflet-interactive {
            cursor: pointer !important;
        }
        
        /* Add hover effect for better UX */
        .leaflet-container {
            background: #f8f9fa;
        }
        
        /* Layer control styling */
        .leaflet-control-layers {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 8px;
            border: 1px solid rgba(0,0,0,0.1);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .leaflet-control-layers-toggle {
            background-image: url('data:image/svg+xml;charset=UTF-8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.25 4.533A9.707 9.707 0 006 3a9.735 9.735 0 00-3 .75c0 .237.014.477.042.711C3.18 6.824 5.14 8.5 7.5 8.5s4.32-1.676 4.458-3.939A9.864 9.864 0 0011.25 4.533z"/><path d="M12.75 4.533A9.707 9.707 0 0018 3a9.735 9.735 0 003 .75c0 .237-.014.477-.042.711C20.82 6.824 18.86 8.5 16.5 8.5s-4.32-1.676-4.458-3.939A9.864 9.864 0 0012.75 4.533z"/><path d="M12 12c0 .5-.5.5-.5.5s-.5 0-.5-.5c0-.5.5-.5.5-.5s.5 0 .5.5z"/></svg>') !important;
        }
        
        /* Priority alerts scrollbar styling */
        .card-body::-webkit-scrollbar {
            width: 4px;
        }
        
        .card-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 2px;
        }
        
        .card-body::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 2px;
        }
        
        .card-body::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Alert item hover effect */
        .list-group-item:hover {
            background-color: #f8f9fa;
            transform: translateX(2px);
            transition: all 0.2s ease;
        }
        
        /* Responsive text truncation */
        .text-truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Map legend styling */
        .map-legend {
            font-family: 'Inter', sans-serif;
        }
        
        /* Quick actions button styling */
        .quick-action-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                <a href="dashboard.php" class="menu-item active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="patients.php" class="menu-item">
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
                <h1 class="page-title mb-0">Dengue Early Warning System</h1>
                <small class="text-muted">Health Monitoring Dashboard</small>
            </div>
            <div>
                <a href="prediction.php" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-chart-pie me-1"></i> Prediction Report
                </a>
                <span class="position-relative">
                    <i class="fas fa-bell fa-lg"></i>
                    <?php if ($statsData['active_alerts'] > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        <?php echo $statsData['active_alerts']; ?>
                    </span>
                    <?php endif; ?>
                </span>
                <span class="ms-3 text-muted small">Last Updated: <?php echo date('M j, Y'); ?> - <?php echo date('h:i A'); ?></span>
            </div>
        </header>

        <!-- Alert Banner -->
        <?php if (($statsData['risk_level'] === 'HIGH' || $statsData['active_alerts'] > 5) && !isset($_COOKIE['alert_dismissed'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4 p-3 rounded">
            <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
            <div>
                <strong>High Alert: Outbreak Risk Detected</strong>
                <div>Multiple barangays showing rapid case increase. Immediate intervention required.</div>
            </div>
            <a href="alerts.php" class="btn btn-danger btn-sm ms-auto">View Details</a>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" onclick="setAlertDismissed()"></button>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="icon bg-primary">
                        <i class="fas fa-virus"></i>
                    </div>
                    <div class="stats-value"><?php echo number_format($statsData['total_cases']); ?></div>
                    <div class="stats-label">Total Cases</div>
                    <span class="trend-indicator <?php echo $statsData['case_trend'] >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        <i class="fas fa-arrow-<?php echo $statsData['case_trend'] >= 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo abs($statsData['case_trend']); ?>% vs last month
                    </span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="icon bg-warning">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="stats-value"><?php echo $statsData['active_alerts']; ?></div>
                    <div class="stats-label">Active Alerts</div>
                    <?php if ($statsData['high_priority']): ?>
                    <span class="trend-indicator trend-up">
                        <i class="fas fa-exclamation-circle"></i> High Priority
                    </span>
                    <?php else: ?>
                    <span class="trend-indicator trend-neutral">
                        <i class="fas fa-check-circle"></i> Normal
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="icon bg-success">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="stats-value"><?php echo $statsData['weekly_cases']; ?></div>
                    <div class="stats-label">New Cases (7 Days)</div>
                    <span class="trend-indicator <?php echo $statsData['weekly_trend'] >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        <i class="fas fa-arrow-<?php echo $statsData['weekly_trend'] >= 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo abs($statsData['weekly_trend']); ?>% vs last week
                    </span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="icon bg-<?php echo $statsData['risk_color']; ?>">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="stats-value text-<?php echo $statsData['risk_color']; ?>"><?php echo strtoupper($statsData['risk_level']); ?></div>
                    <div class="stats-label">Risk Level</div>
                    <span class="trend-indicator trend-neutral">
                        <i class="fas fa-thermometer-half"></i> <?php echo $statsData['temperature']; ?>°C
                        <i class="fas fa-tint ms-2"></i> <?php echo $statsData['humidity']; ?>%
                    </span>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Cases by Barangay</h5>
                        <div>
                            <select class="form-select form-select-sm d-inline-block w-auto me-2" id="dateRangeSelect">
                                <option value="30">Last 30 days</option>
                                <option value="7">Last 7 days</option>
                                <option value="90">Last 90 days</option>
                            </select>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-secondary active" data-view="map">
                                    <i class="fas fa-map-marked-alt"></i> Map
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-view="chart">
                                    <i class="fas fa-chart-bar"></i> Chart
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- Inside the card-body div -->
                    <div class="card-body position-relative p-0">
                        <div id="map" class="rounded-bottom" style="height: 400px;"></div>
                        <canvas id="chart" class="rounded-bottom" style="height: 400px; display: none;"></canvas>
                        <!-- Map instructions overlay -->
                        <div class="position-absolute top-0 start-0 m-2" style="z-index: 1000;">
                            <div class="bg-white rounded-pill px-3 py-1 shadow-sm" style="font-size: 0.75rem; opacity: 0.9;">
                                <i class="fas fa-info-circle text-primary me-1"></i>
                                Click markers for details
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- Priority Alerts -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Priority Alerts</h5>
                        <a href="alerts.php" class="btn btn-link btn-sm">View All</a>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        <div class="list-group list-group-flush">
                            <?php if (isset($alerts['priority']) && count($alerts['priority']) > 0): ?>
                                <?php $alertCount = 0; ?>
                                <?php foreach ($alerts['priority'] as $alert): ?>
                                    <?php if ($alertCount >= 5): ?>
                                        <div class="text-center py-2 border-top">
                                            <a href="alerts.php" class="btn btn-link btn-sm">
                                                <i class="fas fa-plus-circle me-1"></i>
                                                View <?php echo count($alerts['priority']) - 5; ?> more alerts
                                            </a>
                                        </div>
                                        <?php break; ?>
                                    <?php endif; ?>
                                <div class="list-group-item list-group-item-action border-start border-4 border-<?php echo $alert['risk_color']; ?> py-2">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="alert-icon bg-<?php echo $alert['risk_color']; ?> bg-opacity-10 text-<?php echo $alert['risk_color']; ?> rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                                <i class="fas fa-exclamation-triangle fa-sm"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3 min-w-0">
                                            <div class="d-flex justify-content-between align-items-start mb-1">
                                                <h6 class="mb-0 text-truncate me-2" style="font-size: 0.9rem;">
                                                    <?php echo htmlspecialchars($alert['barangay']); ?>
                                                </h6>
                                                <span class="badge bg-<?php echo $alert['risk_color']; ?> rounded-pill flex-shrink-0" style="font-size: 0.7rem;">
                                                    <?php echo $alert['risk_level']; ?>
                                                </span>
                                            </div>
                                            <p class="mb-1 small text-muted text-truncate" style="font-size: 0.8rem;">
                                                <?php echo htmlspecialchars(substr($alert['message'], 0, 80) . (strlen($alert['message']) > 80 ? '...' : '')); ?>
                                            </p>
                                            <small class="text-muted" style="font-size: 0.75rem;">
                                                <i class="fas fa-clock"></i> <?php echo $alert['time_ago']; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php $alertCount++; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                                    <p class="text-muted mb-0">No active alerts</p>
                                    <small class="text-muted">System is operating normally</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Cases Table -->
        <div class="card shadow-sm border-0 mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Patient Cases</h5>
                <a href="patients.php" class="btn btn-link">View All Cases</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Case ID</th>
                                <th>Patient</th>
                                <th>Age</th>
                                <th>Barangay</th>
                                <th>Date Reported</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentCases && count($recentCases) > 0): ?>
                                <?php foreach ($recentCases as $case): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark">#<?php echo $case['id']; ?></span></td>
                                    <td class="fw-medium"><?php echo htmlspecialchars($case['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($case['age']); ?></td>
                                    <td><?php echo htmlspecialchars($case['barangay']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($case['date'])); ?></td>
                                    <td>
                                        <?php
                                            $statusClass = match(strtolower($case['status'])) {
                                                'critical' => 'danger',
                                                'severe' => 'warning',
                                                'moderate' => 'info',
                                                'mild' => 'success',
                                                default => 'secondary'
                                            };
                                        ?>
                                        <span class="badge bg-<?php echo $statusClass; ?> bg-opacity-10 text-<?php echo $statusClass; ?> px-2 py-1">
                                            <?php echo ucfirst($case['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="view_patient.php?id=<?php echo $case['id']; ?>" 
                                               class="btn btn-outline-primary"
                                               data-bs-toggle="tooltip"
                                               title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit_case.php?id=<?php echo $case['id']; ?>" 
                                               class="btn btn-outline-secondary"
                                               data-bs-toggle="tooltip"
                                               title="Edit Case">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="fas fa-clipboard-list fa-2x mb-2"></i>
                                        <div>No recent cases found</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <div class="d-flex flex-column align-items-end">
               
                <a href="add_patient.php" 
                class="btn btn-danger quick-action-btn mb-2" 
                data-bs-toggle="tooltip" 
                title="Add New Patient">
                    <i class="fas fa-plus"></i>
                </a>

                <button type="button" 
                        class="btn btn-primary quick-action-btn mb-2" 
                        data-bs-toggle="modal" 
                        data-bs-target="#generateReportModal"
                        data-bs-tooltip="tooltip"
                        title="Generate Report">
                    <i class="fas fa-file-alt"></i>
                </button>
                <button type="button" 
                        class="btn btn-warning quick-action-btn" 
                        data-bs-toggle="modal" 
                        data-bs-target="#sendAlertModal"
                        data-bs-tooltip="tooltip"
                        title="Send Alert">
                    <i class="fas fa-bell"></i>
                </button>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loadingOverlay">
            <div class="spinner"></div>
        </div>
    </main>
</div>

<!-- Bootstrap Bundle and Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Leaflet.js -->
<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
<!-- topojson-client for converting TopoJSON to GeoJSON -->
<script src="https://unpkg.com/topojson-client@3/dist/topojson-client.min.js"></script>

<!-- Custom JS -->
<script src="assets/js/dashboard.js"></script>

    <!-- Initialize Map and Charts -->

    <script>
        // Function to set alert dismissed cookie
        function setAlertDismissed() {
            document.cookie = "alert_dismissed=true; path=/; max-age=86400"; // 24 hours
        }

        // Global variables
        let map, barChart;

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize map
            map = L.map('map').setView([6.9214, 122.0790], 11); // Updated coordinates for better coverage
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 18
            }).addTo(map);

            // Add barangay data to map
            const barangayData = <?php echo json_encode($barangayData); ?>;
            initializeMap(map, barangayData);

            // Try loading TopoJSON polygons from maps/ if available
            loadAndRenderTopoJSON(map, barangayData);

            // Initialize chart
            initializeChart(barangayData);

            // Initialize view toggle buttons
            initializeViewToggle();

            // Initialize date range selector
            initializeDateRangeSelector();

            // Initialize tooltips
            const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            tooltips.forEach(tooltip => {
                new bootstrap.Tooltip(tooltip);
            });
        });

        function initializeMap(map, barangayData) {
            // Clear existing markers except base tiles
            map.eachLayer(function (layer) {
                if (layer instanceof L.CircleMarker) {
                    map.removeLayer(layer);
                }
            });

            // Add legend
            if (!map.legendControl) {
                const legend = L.control({position: 'bottomright'});
                legend.onAdd = function (map) {
                    const div = L.DomUtil.create('div', 'map-legend');
                    div.innerHTML = `
                        <div style="background: white; padding: 8px; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); font-size: 11px;">
                            <div style="font-weight: bold; margin-bottom: 6px;">Case Count</div>
                            <div style="display: flex; align-items: center; margin: 2px 0;">
                                <div style="width: 16px; height: 16px; background: #dc3545; border-radius: 50%; margin-right: 6px;"></div>
                                <span>10+ cases</span>
                            </div>
                            <div style="display: flex; align-items: center; margin: 2px 0;">
                                <div style="width: 12px; height: 12px; background: #fd7e14; border-radius: 50%; margin-right: 6px;"></div>
                                <span>5-9 cases</span>
                            </div>
                            <div style="display: flex; align-items: center; margin: 2px 0;">
                                <div style="width: 8px; height: 8px; background: #ffc107; border-radius: 50%; margin-right: 6px;"></div>
                                <span>1-4 cases</span>
                            </div>
                            <div style="display: flex; align-items: center; margin: 2px 0;">
                                <div style="width: 6px; height: 6px; background: #28a745; border-radius: 50%; margin-right: 6px;"></div>
                                <span>No cases</span>
                            </div>
                        </div>
                    `;
                    return div;
                };
                legend.addTo(map);
                map.legendControl = legend;
            }

            // Process barangay data and add markers
            barangayData.forEach((barangay, index) => {
                // Use coordinates from database or fall back to default area with offset
                let lat = parseFloat(barangay.latitude) || (6.9214 + (Math.random() - 0.5) * 0.2);
                let lng = parseFloat(barangay.longitude) || (122.0790 + (Math.random() - 0.5) * 0.2);
                
                const caseCount = parseInt(barangay.case_count) || 0;
                const population = parseInt(barangay.population) || 1;
                const rate = (caseCount / population) * 1000;
                
                // Determine marker style based on case count
                let radius, color, weight;
                // Increase marker sizes for better visibility and scale by case count
                if (caseCount >= 10) {
                    radius = 20 + Math.min(caseCount - 10, 25); // base 20, grows with more cases
                    color = '#dc3545';
                    weight = 3;
                } else if (caseCount >= 5) {
                    radius = 15 + (caseCount - 5) * 1.5; // slightly larger scaling
                    color = '#fd7e14';
                    weight = 2;
                } else if (caseCount >= 1) {
                    radius = 12; // make small counts more visible
                    color = '#ffc107';
                    weight = 2;
                } else {
                    radius = 8; // no cases still visible but small
                    color = '#28a745';
                    weight = 1;
                }

                // Create circle marker with higher z-index to ensure it's above polygons
                const circle = L.circleMarker([lat, lng], {
                    radius: radius,
                    fillColor: color,
                    color: '#fff',
                    weight: weight,
                    opacity: 1,
                    fillOpacity: 0.8,
                    pane: 'markerPane' // Ensures markers are above polygons
                });

                // Create detailed popup
                const popupContent = `
                    <div style="min-width: 180px; font-family: 'Inter', sans-serif;">
                        <div style="display: flex; align-items: center; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #eee;">
                            <i class="fas fa-map-marker-alt" style="color: ${color}; margin-right: 6px;"></i>
                            <h6 style="margin: 0; font-size: 14px; font-weight: 600;">${barangay.name}</h6>
                        </div>
                        <div style="font-size: 12px; line-height: 1.4;">
                            <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                                <span style="color: #666;">Cases:</span>
                                <span style="font-weight: 600; color: ${color};">${caseCount}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                                <span style="color: #666;">Population:</span>
                                <span>${population.toLocaleString()}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                                <span style="color: #666;">Rate:</span>
                                <span>${rate.toFixed(2)}/1000</span>
                            </div>
                            ${caseCount > 0 ? `
                            <div style="margin-top: 8px; padding-top: 6px; border-top: 1px solid #eee;">
                                <a href="analytics.php?barangay=${barangay.barangay_id || ''}" 
                                   style="color: #007bff; text-decoration: none; font-size: 11px; display: flex; align-items: center;">
                                    <i class="fas fa-chart-line" style="margin-right: 4px;"></i>
                                    View Analytics
                                </a>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `;

                circle.bindPopup(popupContent, {
                    maxWidth: 200,
                    className: 'custom-popup'
                });
                
                // Add click event to open popup with visual feedback
                circle.on('click', function (e) {
                    this.openPopup();
                    // Add a brief pulse effect
                    const originalRadius = radius;
                    this.setStyle({ radius: radius + 3 });
                    setTimeout(() => {
                        this.setStyle({ radius: originalRadius });
                    }, 150);
                    // Prevent event bubbling to map
                    L.DomEvent.stopPropagation(e);
                });
                
                // Enhanced hover effects for better UX
                circle.on('mouseover', function (e) {
                    this.setStyle({
                        weight: weight + 2,
                        fillOpacity: 1,
                        radius: radius + 2
                    });
                    // Change cursor to pointer
                    map.getContainer().style.cursor = 'pointer';
                });
                
                circle.on('mouseout', function (e) {
                    this.setStyle({
                        weight: weight,
                        fillOpacity: 0.8,
                        radius: radius
                    });
                    // Reset cursor
                    map.getContainer().style.cursor = '';
                });
                
                circle.addTo(map);
            });

            // Fit map to show all markers if data exists
            if (barangayData.length > 0) {
                const group = new L.featureGroup();
                barangayData.forEach(barangay => {
                    let lat = parseFloat(barangay.latitude) || 6.9214;
                    let lng = parseFloat(barangay.longitude) || 122.0790;
                    group.addLayer(L.marker([lat, lng]));
                });
                map.fitBounds(group.getBounds().pad(0.1));
            }
        }

        // Load TopoJSON and render polygons. Matches features by barangay name (case-insensitive). Uses maps/country.topo.json by default.
        function loadAndRenderTopoJSON(map, barangayData) {
            const topoUrl = 'maps/country.topo.json';
            fetch(topoUrl)
                .then(resp => {
                    if (!resp.ok) throw new Error('TopoJSON not found');
                    return resp.json();
                })
                .then(topo => {
                    // Convert all objects to GeoJSON features
                    const collections = [];
                    for (const key in topo.objects) {
                        try {
                            const geo = topojson.feature(topo, topo.objects[key]);
                            if (geo && geo.features) collections.push(...geo.features);
                        } catch (err) {
                            console.warn('Failed to convert topo object', key, err);
                        }
                    }

                    if (collections.length === 0) return;

                    // Create a lookup by normalized barangay name
                    const lookup = {};
                    barangayData.forEach(b => {
                        const name = (b.name || '').toString().trim().toLowerCase();
                        if (name) lookup[name] = b;
                    });

                    // Create a polygon layer group
                    const polygonLayer = L.layerGroup();

                    collections.forEach(feature => {
                        const props = feature.properties || {};
                        const propNames = Object.keys(props).map(k => k.toLowerCase());
                        // Try common property names for barangay/place
                        const candidates = ['name', 'name_en', 'barangay', 'brgy', 'barangay_n', 'BRGY_NAME'];
                        let fname = null;
                        for (const c of candidates) {
                            for (const pk of Object.keys(props)) {
                                if (pk.toLowerCase() === c.toLowerCase() && props[pk]) {
                                    fname = props[pk].toString().trim().toLowerCase();
                                    break;
                                }
                            }
                            if (fname) break;
                        }

                        // Fallback: try any property that looks like a name
                        if (!fname) {
                            for (const pk of Object.keys(props)) {
                                const v = props[pk];
                                if (typeof v === 'string' && v.length > 2) {
                                    fname = v.toString().trim().toLowerCase();
                                    break;
                                }
                            }
                        }

                        // Match with barangayData
                        const match = fname ? lookup[fname] : null;

                        // Determine style by matched case count
                        let caseCount = match ? (parseInt(match.case_count) || 0) : 0;
                        let fillColor = '#28a745';
                        if (caseCount >= 10) fillColor = '#dc3545';
                        else if (caseCount >= 5) fillColor = '#fd7e14';
                        else if (caseCount >= 1) fillColor = '#ffc107';

                        const geojson = L.geoJSON(feature, {
                            style: {
                                color: '#333',
                                weight: 1,
                                fillColor: fillColor,
                                fillOpacity: 0.3, // Reduced opacity so markers are more visible
                                interactive: true
                            },
                            // Ensure polygons are below markers
                            pane: 'overlayPane'
                        });

                        // Popup content for polygons
                        const displayName = (match && match.name) ? match.name : (feature.properties && (feature.properties.name || feature.properties.NAME || ''));
                        geojson.bindPopup(`
                            <div style="font-family: 'Inter', sans-serif;">
                                <h6 style="margin: 0 0 8px 0; color: ${fillColor};">${displayName}</h6>
                                <div style="font-size: 12px;">
                                    <strong>Cases:</strong> ${caseCount}<br/>
                                    ${match ? `<strong>Population:</strong> ${(match.population || 0).toLocaleString()}` : ''}
                                </div>
                            </div>
                        `);

                        // Add click handler to prevent interference with markers
                        geojson.on('click', function(e) {
                            // Only open popup if clicked area doesn't have a marker nearby
                            const clickPoint = e.latlng;
                            let hasNearbyMarker = false;
                            
                            map.eachLayer(function(layer) {
                                if (layer instanceof L.CircleMarker) {
                                    const distance = clickPoint.distanceTo(layer.getLatLng());
                                    if (distance < 50) { // 50 meters threshold
                                        hasNearbyMarker = true;
                                    }
                                }
                            });
                            
                            if (!hasNearbyMarker) {
                                this.openPopup();
                            }
                        });

                        polygonLayer.addLayer(geojson);
                    });

                    // Add control to toggle polygons - start with polygons hidden
                    if (!map.polygonControl) {
                        const overlayMaps = { 'Barangay polygons': polygonLayer };
                        const layerControl = L.control.layers({}, overlayMaps, { collapsed: false }).addTo(map);
                        map.polygonControl = true;
                        
                        // Start with polygons hidden to ensure markers are clickable by default
                        map.removeLayer(polygonLayer);
                    }
                })
                .catch(err => {
                    console.warn('TopoJSON load failed:', err);
                });
        }

        function initializeChart(barangayData) {
            const ctx = document.getElementById('chart').getContext('2d');
            
            barChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: barangayData.map(item => item.name),
                    datasets: [{
                        label: 'Cases',
                        data: barangayData.map(item => parseInt(item.case_count) || 0),
                        backgroundColor: 'rgba(220, 53, 69, 0.8)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Cases by Barangay (Last 30 days)',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    },
                    animation: {
                        duration: 800,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        }

        function initializeViewToggle() {
            const viewButtons = document.querySelectorAll('[data-view]');
            const mapElement = document.getElementById('map');
            const chartElement = document.getElementById('chart');

            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const view = this.dataset.view;
                    
                    // Update active button
                    viewButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show/hide elements
                    if (view === 'map') {
                        mapElement.style.display = 'block';
                        chartElement.style.display = 'none';
                        
                        // Invalidate map size after showing
                        setTimeout(() => {
                            map.invalidateSize();
                        }, 100);
                    } else if (view === 'chart') {
                        mapElement.style.display = 'none';
                        chartElement.style.display = 'block';
                        
                        // Resize chart after showing
                        setTimeout(() => {
                            barChart.resize();
                        }, 100);
                    }
                });
            });
        }

        function initializeDateRangeSelector() {
            document.getElementById('dateRangeSelect').addEventListener('change', function(e) {
                const days = parseInt(e.target.value);
                const overlay = document.getElementById('loadingOverlay');
                overlay.style.display = 'flex';

                // Fetch new data from server (robust handling)
                fetch(`api/dashboard-data.php?days=${days}`)
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok');
                        return response.json();
                    })
                    .then(data => {
                        // Update chart data
                        if (barChart && data.barangay_data) {
                            barChart.data.labels = data.barangay_data.map(item => item.name);
                            barChart.data.datasets[0].data = data.barangay_data.map(item => parseInt(item.case_count) || 0);
                            barChart.options.plugins.title.text = `Cases by Barangay (Last ${days} days)`;
                            barChart.update('active');
                        }

                        // Update map markers
                        if (map && data.barangay_data) {
                            updateMapMarkers(map, data.barangay_data);
                        }

                        // Update stats if provided
                        if (data.stats) updateDashboardStats(data.stats);

                        overlay.style.display = 'none';
                    })
                    .catch(err => {
                        console.error('Failed to fetch dashboard data:', err);
                        // Fallback: reload page with query param so server-side can provide data
                        const url = new URL(window.location.href);
                        url.searchParams.set('days', days);
                        window.location.href = url.toString();
                    });
            });
        }

        function updateMapMarkers(map, barangayData) {
            // Clear existing markers (preserve base layer)
            map.eachLayer(layer => {
                if (layer instanceof L.CircleMarker) {
                    map.removeLayer(layer);
                }
            });
            
            // Add new markers with updated data
            initializeMap(map, barangayData);
        }

        function updateDashboardStats(stats) {
            // Update dashboard statistics cards
            console.log('Updated stats:', stats);
            
            // Example: Update risk level if you have the element
            const riskElement = document.querySelector('.risk-level');
            if (riskElement && stats.risk_level) {
                riskElement.textContent = stats.risk_level;
                riskElement.className = `risk-level text-${stats.risk_color}`;
            }
            
            // Example: Update case count if you have the element
            const caseCountElement = document.querySelector('.period-cases');
            if (caseCountElement && stats.period_cases !== undefined) {
                caseCountElement.textContent = stats.period_cases;
            }
        }

        // Utility functions
        function downloadReport() {
            // Implement report download functionality
            alert('Report download feature to be implemented');
        }

        // Quick action functions
        function addNewCase() {
            window.location.href = 'add_case.php';
        }

        function generateReport() {
            window.location.href = 'reports.php';
        }

        function sendAlert() {
            // Implement alert sending functionality
            alert('Alert sending feature to be implemented');
        }
    </script>
</body>
</html>