<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

// Get filter parameters
$selectedBarangay = isset($_GET['barangay']) ? (int)$_GET['barangay'] : 0;
$dateRange = isset($_GET['range']) ? $_GET['range'] : '30';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportToCSV($selectedBarangay, $startDate, $endDate);
    exit;
}

// Get barangays for dropdown
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

// Get analytics data
function getAnalyticsData($barangayId = 0, $startDate = '', $endDate = '') {
    $db = getDBConnection();
    
    try {
        $whereClause = "WHERE pc.date_reported BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($barangayId > 0) {
            $whereClause .= " AND b.barangay_id = ?";
            $params[] = $barangayId;
        }
        
        $query = "
            SELECT 
                b.barangay_id,
                b.name as barangay_name,
                b.population,
                b.latitude,
                b.longitude,
                COUNT(pc.case_id) as total_cases,
                COUNT(CASE WHEN pc.status = 'Critical' THEN 1 END) as critical_cases,
                COUNT(CASE WHEN pc.status = 'Severe' THEN 1 END) as severe_cases,
                COUNT(CASE WHEN pc.status = 'Moderate' THEN 1 END) as moderate_cases,
                COUNT(CASE WHEN pc.status = 'Mild' THEN 1 END) as mild_cases,
                COUNT(CASE WHEN pc.status = 'Recovered' THEN 1 END) as recovered_cases,
                AVG(pc.temperature) as avg_temperature,
                COUNT(DISTINCT p.patient_id) as unique_patients,
                MIN(pc.date_reported) as first_case_date,
                MAX(pc.date_reported) as latest_case_date
            FROM barangays b
            LEFT JOIN patients p ON b.barangay_id = p.barangay_id
            LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id
            $whereClause
            GROUP BY b.barangay_id, b.name, b.population, b.latitude, b.longitude
            ORDER BY total_cases DESC, b.name
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Error getting analytics data: " . $e->getMessage());
        return [];
    }
}

// Get time series data for charts
function getTimeSeriesData($barangayId = 0, $startDate = '', $endDate = '') {
    $db = getDBConnection();
    
    try {
        $whereClause = "WHERE pc.date_reported BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($barangayId > 0) {
            $whereClause .= " AND b.barangay_id = ?";
            $params[] = $barangayId;
        }
        
        $query = "
            SELECT 
                DATE(pc.date_reported) as report_date,
                COUNT(pc.case_id) as daily_cases,
                COUNT(CASE WHEN pc.status IN ('Critical', 'Severe') THEN 1 END) as severe_cases
            FROM patient_cases pc
            JOIN patients p ON pc.patient_id = p.patient_id
            JOIN barangays b ON p.barangay_id = b.barangay_id
            $whereClause
            GROUP BY DATE(pc.date_reported)
            ORDER BY report_date
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Error getting time series data: " . $e->getMessage());
        return [];
    }
}

// Get age group analysis
function getAgeGroupAnalysis($barangayId = 0, $startDate = '', $endDate = '') {
    $db = getDBConnection();
    
    try {
        $whereClause = "WHERE pc.date_reported BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($barangayId > 0) {
            $whereClause .= " AND b.barangay_id = ?";
            $params[] = $barangayId;
        }
        
        $query = "
            SELECT 
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 5 THEN '0-4'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 15 THEN '5-14'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 25 THEN '15-24'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 35 THEN '25-34'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 45 THEN '35-44'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 55 THEN '45-54'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 65 THEN '55-64'
                    ELSE '65+'
                END as age_group,
                COUNT(DISTINCT p.patient_id) as patient_count,
                COUNT(pc.case_id) as case_count
            FROM patients p
            JOIN patient_cases pc ON p.patient_id = pc.patient_id
            JOIN barangays b ON p.barangay_id = b.barangay_id
            $whereClause
            GROUP BY age_group
            ORDER BY age_group
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Error getting age group analysis: " . $e->getMessage());
        return [];
    }
}

// Export to CSV function
function exportToCSV($barangayId = 0, $startDate = '', $endDate = '') {
    $data = getAnalyticsData($barangayId, $startDate, $endDate);
    
    $filename = 'dengue_analytics_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'Barangay ID',
        'Barangay Name',
        'Population',
        'Total Cases',
        'Critical Cases',
        'Severe Cases',
        'Moderate Cases',
        'Mild Cases',
        'Recovered Cases',
        'Average Temperature',
        'Unique Patients',
        'First Case Date',
        'Latest Case Date',
        'Case Rate per 1000',
        'Recovery Rate %'
    ]);
    
    // CSV data
    foreach ($data as $row) {
        $caseRate = $row['population'] > 0 ? round(($row['total_cases'] / $row['population']) * 1000, 2) : 0;
        $recoveryRate = $row['total_cases'] > 0 ? round(($row['recovered_cases'] / $row['total_cases']) * 100, 2) : 0;
        
        fputcsv($output, [
            $row['barangay_id'],
            $row['barangay_name'],
            $row['population'],
            $row['total_cases'],
            $row['critical_cases'],
            $row['severe_cases'],
            $row['moderate_cases'],
            $row['mild_cases'],
            $row['recovered_cases'],
            round($row['avg_temperature'], 1),
            $row['unique_patients'],
            $row['first_case_date'],
            $row['latest_case_date'],
            $caseRate,
            $recoveryRate
        ]);
    }
    
    fclose($output);
}

// Get all data
$barangays = getBarangays();
$analyticsData = getAnalyticsData($selectedBarangay, $startDate, $endDate);
$timeSeriesData = getTimeSeriesData($selectedBarangay, $startDate, $endDate);
$ageGroupData = getAgeGroupAnalysis($selectedBarangay, $startDate, $endDate);

// Calculate summary statistics
// Get the actual total cases count (not sum of barangay totals)
function getTotalCasesCount($barangayId = 0, $startDate = '', $endDate = '') {
    $db = getDBConnection();
    
    try {
        $whereClause = "WHERE pc.date_reported BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($barangayId > 0) {
            $whereClause .= " AND b.barangay_id = ?";
            $params[] = $barangayId;
        }
        
        $query = "
            SELECT COUNT(pc.case_id) as total_cases
            FROM patient_cases pc
            JOIN patients p ON pc.patient_id = p.patient_id
            JOIN barangays b ON p.barangay_id = b.barangay_id
            $whereClause
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_cases'];
        
    } catch(PDOException $e) {
        error_log("Error getting total cases count: " . $e->getMessage());
        return 0;
    }
}

$totalCases = getTotalCasesCount($selectedBarangay, $startDate, $endDate);
$totalPopulation = array_sum(array_column($analyticsData, 'population'));
$avgTemperature = $totalCases > 0 ? array_sum(array_column($analyticsData, 'avg_temperature')) / count(array_filter($analyticsData, function($item) { return $item['avg_temperature'] > 0; })) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Dengue Monitoring System</title>
    
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
    <!-- Leaflet CSS -->
    <link href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" rel="stylesheet">
    <!-- Modern Design System CSS -->
    <link href="assets/css/modern.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/dashboard.css" rel="stylesheet">
    
    <style>
        .analytics-card {
            transition: all 0.3s ease;
            border: none;
            border-radius: 15px;
            overflow: hidden;
        }
        
        .analytics-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }
        
        .filters-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .trend-indicator {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .trend-up {
            background: #fee;
            color: #dc3545;
        }
        
        .trend-down {
            background: #efe;
            color: #28a745;
        }
        
        .data-table {
            font-size: 0.9rem;
        }
        
        .data-table th {
            background: #f8f9fa;
            border-top: none;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .heatmap-cell {
            padding: 8px;
            text-align: center;
            border-radius: 4px;
            font-weight: 500;
        }
        
        .severity-critical { background: #dc3545; color: white; }
        .severity-severe { background: #fd7e14; color: white; }
        .severity-moderate { background: #ffc107; color: black; }
        .severity-mild { background: #28a745; color: white; }
        .severity-none { background: #e9ecef; color: #6c757d; }
        
        /* Custom map popup styling */
        .custom-popup .leaflet-popup-content-wrapper {
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .custom-popup .leaflet-popup-content {
            margin: 8px 12px;
        }
        
        .map-legend {
            font-family: Arial, sans-serif;
        }
        
        /* Map container improvements */
        #analyticsMap {
            border-radius: 8px;
            overflow: hidden;
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
                <a href="patients.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Patients</span>
                </a>
                <a href="analytics.php" class="menu-item active">
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
                <h1 class="page-title mb-0">Dengue Analytics</h1>
                <small class="text-muted">Comprehensive analysis and insights</small>
            </div>
            <div>
                <button class="btn btn-success me-2" onclick="exportToCSV()">
                    <i class="fas fa-download me-1"></i> Export CSV
                </button>
                <button class="btn btn-primary" onclick="generatePDFReport()">
                    <i class="fas fa-file-pdf me-1"></i> PDF Report
                </button>
            </div>
        </header>

        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" action="analytics.php" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Barangay</label>
                    <select class="form-select" name="barangay" onchange="this.form.submit()">
                        <option value="0">All Barangays</option>
                        <?php foreach ($barangays as $barangay): ?>
                        <option value="<?php echo $barangay['barangay_id']; ?>" 
                                <?php echo $selectedBarangay == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($barangay['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Date Range</label>
                    <select class="form-select" name="range" onchange="updateDateRange(this.value)">
                        <option value="7" <?php echo $dateRange == '7' ? 'selected' : ''; ?>>Last 7 days</option>
                        <option value="30" <?php echo $dateRange == '30' ? 'selected' : ''; ?>>Last 30 days</option>
                        <option value="90" <?php echo $dateRange == '90' ? 'selected' : ''; ?>>Last 90 days</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" class="form-select" name="start_date" value="<?php echo $startDate; ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" class="form-select" name="end_date" value="<?php echo $endDate; ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block w-100">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <a href="analytics.php" class="btn btn-outline-secondary d-block w-100">
                        <i class="fas fa-refresh"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Summary Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card analytics-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-danger text-white me-3">
                            <i class="fas fa-virus"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?php echo number_format($totalCases); ?></h3>
                            <small class="text-muted">Total Cases</small>
                            <div class="trend-indicator trend-up mt-1">
                                <i class="fas fa-arrow-up me-1"></i> Period Analysis
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card analytics-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-info text-white me-3">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?php echo count(array_filter($analyticsData, function($item) { return $item['total_cases'] > 0; })); ?></h3>
                            <small class="text-muted">Affected Barangays</small>
                            <div class="text-muted small mt-1">
                                out of <?php echo count($barangays); ?> total
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card analytics-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-warning text-white me-3">
                            <i class="fas fa-thermometer-half"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?php echo number_format($avgTemperature, 1); ?>°C</h3>
                            <small class="text-muted">Avg Temperature</small>
                            <div class="text-muted small mt-1">
                                from case records
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card analytics-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-success text-white me-3">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?php echo $totalPopulation > 0 ? number_format(($totalCases / $totalPopulation) * 1000, 2) : '0'; ?></h3>
                            <small class="text-muted">Cases per 1,000</small>
                            <div class="text-muted small mt-1">
                                population rate
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Map Row -->
        <div class="row g-4 mb-4">
            <!-- Time Series Chart -->
            <div class="col-lg-8">
                <div class="card analytics-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line me-2"></i>Cases Trend Over Time
                        </h5>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary active" data-chart="line">Line</button>
                            <button class="btn btn-outline-primary" data-chart="bar">Bar</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="timeSeriesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Age Group Analysis -->
            <div class="col-lg-4">
                <div class="card analytics-card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-users me-2"></i>Age Group Distribution
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="ageGroupChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Map and Status Charts -->
        <div class="row g-4 mb-4">
            <!-- Map -->
            <div class="col-lg-8">
                <div class="card analytics-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-map me-2"></i>Geographic Distribution
                        </h5>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary active" data-map="cases">Cases</button>
                            <button class="btn btn-outline-primary" data-map="rate">Rate</button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="analyticsMap" style="height: 400px;"></div>
                    </div>
                </div>
            </div>
            
            <!-- Case Status Distribution -->
            <div class="col-lg-4">
                <div class="card analytics-card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie me-2"></i>Case Severity Distribution
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Data Table -->
        <div class="card analytics-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-table me-2"></i>Detailed Analytics by Barangay
                </h5>
                <div>
                    <button class="btn btn-sm btn-outline-primary me-2" onclick="toggleView('table')">
                        <i class="fas fa-table me-1"></i>Table
                    </button>
                    <button class="btn btn-sm btn-outline-primary" onclick="toggleView('heatmap')">
                        <i class="fas fa-th me-1"></i>Heatmap
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr>
                                <th>Barangay</th>
                                <th>Population</th>
                                <th>Total Cases</th>
                                <th>Critical</th>
                                <th>Severe</th>
                                <th>Moderate</th>
                                <th>Mild</th>
                                <th>Recovered</th>
                                <th>Case Rate</th>
                                <th>Avg Temp</th>
                                <th>Recovery Rate</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analyticsData as $data): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($data['barangay_name']); ?></td>
                                <td><?php echo number_format($data['population']); ?></td>
                                <td>
                                    <span class="badge bg-primary"><?php echo $data['total_cases']; ?></span>
                                </td>
                                <td>
                                    <?php if ($data['critical_cases'] > 0): ?>
                                    <span class="heatmap-cell severity-critical"><?php echo $data['critical_cases']; ?></span>
                                    <?php else: ?>
                                    <span class="heatmap-cell severity-none">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($data['severe_cases'] > 0): ?>
                                    <span class="heatmap-cell severity-severe"><?php echo $data['severe_cases']; ?></span>
                                    <?php else: ?>
                                    <span class="heatmap-cell severity-none">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($data['moderate_cases'] > 0): ?>
                                    <span class="heatmap-cell severity-moderate"><?php echo $data['moderate_cases']; ?></span>
                                    <?php else: ?>
                                    <span class="heatmap-cell severity-none">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($data['mild_cases'] > 0): ?>
                                    <span class="heatmap-cell severity-mild"><?php echo $data['mild_cases']; ?></span>
                                    <?php else: ?>
                                    <span class="heatmap-cell severity-none">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $data['recovered_cases']; ?></td>
                                <td>
                                    <?php 
                                    $rate = $data['population'] > 0 ? ($data['total_cases'] / $data['population']) * 1000 : 0;
                                    echo number_format($rate, 2);
                                    ?>/1000
                                </td>
                                <td>
                                    <?php if ($data['avg_temperature']): ?>
                                    <?php echo number_format($data['avg_temperature'], 1); ?>°C
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $recoveryRate = $data['total_cases'] > 0 ? ($data['recovered_cases'] / $data['total_cases']) * 100 : 0;
                                    ?>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $recoveryRate; ?>%">
                                            <?php echo number_format($recoveryRate, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="analytics.php?barangay=<?php echo $data['barangay_id']; ?>" 
                                           class="btn btn-outline-primary" title="Focus on this barangay">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="patients.php?barangay=<?php echo $data['barangay_id']; ?>" 
                                           class="btn btn-outline-secondary" title="View patients">
                                            <i class="fas fa-users"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/date-fns@2.28.0/index.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@2.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<!-- Leaflet -->
<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>

<script>
// Data from PHP
const analyticsData = <?php echo json_encode($analyticsData); ?>;
const timeSeriesData = <?php echo json_encode($timeSeriesData); ?>;
const ageGroupData = <?php echo json_encode($ageGroupData); ?>;

let timeSeriesChart, ageGroupChart, statusChart, analyticsMap;

document.addEventListener('DOMContentLoaded', function() {
    initializeCharts();
    initializeMap();
    initializeEventListeners();
});

function initializeCharts() {
    // Time Series Chart
    const timeCtx = document.getElementById('timeSeriesChart').getContext('2d');
    timeSeriesChart = new Chart(timeCtx, {
        type: 'line',
        data: {
            labels: timeSeriesData.map(item => item.report_date),
            datasets: [{
                label: 'Daily Cases',
                data: timeSeriesData.map(item => item.daily_cases),
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Severe Cases',
                data: timeSeriesData.map(item => item.severe_cases),
                borderColor: '#fd7e14',
                backgroundColor: 'rgba(253, 126, 20, 0.1)',
                fill: false,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit: 'day'
                    }
                },
                y: {
                    beginAtZero: true
                }
            },
            plugins: {
                legend: {
                    position: 'top'
                }
            }
        }
    });

    // Age Group Chart
    const ageCtx = document.getElementById('ageGroupChart').getContext('2d');
    ageGroupChart = new Chart(ageCtx, {
        type: 'doughnut',
        data: {
            labels: ageGroupData.map(item => item.age_group + ' years'),
            datasets: [{
                data: ageGroupData.map(item => item.case_count),
                backgroundColor: [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                    '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // Status Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const totalCritical = analyticsData.reduce((sum, item) => sum + parseInt(item.critical_cases), 0);
    const totalSevere = analyticsData.reduce((sum, item) => sum + parseInt(item.severe_cases), 0);
    const totalModerate = analyticsData.reduce((sum, item) => sum + parseInt(item.moderate_cases), 0);
    const totalMild = analyticsData.reduce((sum, item) => sum + parseInt(item.mild_cases), 0);
    const totalRecovered = analyticsData.reduce((sum, item) => sum + parseInt(item.recovered_cases), 0);

    statusChart = new Chart(statusCtx, {
        type: 'pie',
        data: {
            labels: ['Critical', 'Severe', 'Moderate', 'Mild', 'Recovered'],
            datasets: [{
                data: [totalCritical, totalSevere, totalModerate, totalMild, totalRecovered],
                backgroundColor: ['#dc3545', '#fd7e14', '#ffc107', '#28a745', '#6c757d']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

function initializeMap() {
    // Initialize map centered on Zamboanga area (adjust coordinates as needed)
    analyticsMap = L.map('analyticsMap').setView([6.9214, 122.0790], 11);
    
    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 18
    }).addTo(analyticsMap);

    // Legend for the map
    const legend = L.control({position: 'bottomright'});
    legend.onAdd = function (map) {
        const div = L.DomUtil.create('div', 'map-legend');
        div.innerHTML = `
            <div style="background: white; padding: 10px; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.2);">
                <h6 style="margin: 0 0 8px 0; font-size: 12px;"><strong>Case Count</strong></h6>
                <div style="display: flex; align-items: center; margin: 4px 0;">
                    <div style="width: 20px; height: 20px; background: #dc3545; border-radius: 50%; margin-right: 8px;"></div>
                    <span style="font-size: 11px;">10+ cases</span>
                </div>
                <div style="display: flex; align-items: center; margin: 4px 0;">
                    <div style="width: 16px; height: 16px; background: #fd7e14; border-radius: 50%; margin-right: 8px;"></div>
                    <span style="font-size: 11px;">5-9 cases</span>
                </div>
                <div style="display: flex; align-items: center; margin: 4px 0;">
                    <div style="width: 12px; height: 12px; background: #ffc107; border-radius: 50%; margin-right: 8px;"></div>
                    <span style="font-size: 11px;">1-4 cases</span>
                </div>
                <div style="display: flex; align-items: center; margin: 4px 0;">
                    <div style="width: 8px; height: 8px; background: #28a745; border-radius: 50%; margin-right: 8px;"></div>
                    <span style="font-size: 11px;">No cases</span>
                </div>
            </div>
        `;
        return div;
    };
    legend.addTo(analyticsMap);

    // Add markers for each barangay
    analyticsData.forEach(barangay => {
        // Use default coordinates if not available (you can adjust these)
        let lat = parseFloat(barangay.latitude) || 6.9214;
        let lng = parseFloat(barangay.longitude) || 122.0790;
        
        // Add some random offset if coordinates are default to spread them out
        if (!barangay.latitude || !barangay.longitude) {
            lat += (Math.random() - 0.5) * 0.1; // Random offset within ~5km
            lng += (Math.random() - 0.5) * 0.1;
        }
        
        const caseCount = parseInt(barangay.total_cases) || 0;
        const population = parseInt(barangay.population) || 1;
        const rate = (caseCount / population) * 1000;
        
        // Determine marker style based on case count
        let radius, color, weight;
        if (caseCount >= 10) {
            radius = 15;
            color = '#dc3545';
            weight = 3;
        } else if (caseCount >= 5) {
            radius = 12;
            color = '#fd7e14';
            weight = 2;
        } else if (caseCount >= 1) {
            radius = 8;
            color = '#ffc107';
            weight = 2;
        } else {
            radius = 5;
            color = '#28a745';
            weight = 1;
        }

        // Create circle marker
        const circle = L.circleMarker([lat, lng], {
            radius: radius,
            fillColor: color,
            color: '#fff',
            weight: weight,
            opacity: 1,
            fillOpacity: 0.8
        });

        // Create detailed popup content
        const popupContent = `
            <div style="min-width: 200px;">
                <h6 style="margin: 0 0 10px 0; color: #333; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                    <i class="fas fa-map-marker-alt" style="color: ${color};"></i> 
                    ${barangay.barangay_name}
                </h6>
                <div style="font-size: 13px; line-height: 1.4;">
                    <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                        <span><strong>Total Cases:</strong></span>
                        <span class="badge" style="background: ${color}; color: white; padding: 2px 6px; border-radius: 10px;">${caseCount}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                        <span><strong>Population:</strong></span>
                        <span>${population.toLocaleString()}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                        <span><strong>Rate:</strong></span>
                        <span>${rate.toFixed(2)}/1000</span>
                    </div>
                    ${barangay.critical_cases > 0 ? `
                    <div style="display: flex; justify-content: space-between; margin: 4px 0; color: #dc3545;">
                        <span><strong>Critical:</strong></span>
                        <span>${barangay.critical_cases}</span>
                    </div>
                    ` : ''}
                    ${barangay.severe_cases > 0 ? `
                    <div style="display: flex; justify-content: space-between; margin: 4px 0; color: #fd7e14;">
                        <span><strong>Severe:</strong></span>
                        <span>${barangay.severe_cases}</span>
                    </div>
                    ` : ''}
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee;">
                        <a href="analytics.php?barangay=${barangay.barangay_id}" 
                           style="color: #007bff; text-decoration: none; font-size: 12px;">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                </div>
            </div>
        `;

        circle.bindPopup(popupContent, {
            maxWidth: 250,
            className: 'custom-popup'
        });
        
        // Add hover effects
        circle.on('mouseover', function (e) {
            this.setStyle({
                weight: weight + 2,
                fillOpacity: 1
            });
        });
        
        circle.on('mouseout', function (e) {
            this.setStyle({
                weight: weight,
                fillOpacity: 0.8
            });
        });
        
        circle.addTo(analyticsMap);
    });

    // Fit map to show all markers
    if (analyticsData.length > 0) {
        const group = new L.featureGroup();
        analyticsData.forEach(barangay => {
            let lat = parseFloat(barangay.latitude) || 6.9214;
            let lng = parseFloat(barangay.longitude) || 122.0790;
            group.addLayer(L.marker([lat, lng]));
        });
        analyticsMap.fitBounds(group.getBounds().pad(0.1));
    }
}

function initializeEventListeners() {
    // Chart type toggle
    document.querySelectorAll('[data-chart]').forEach(button => {
        button.addEventListener('click', function() {
            const chartType = this.dataset.chart;
            timeSeriesChart.config.type = chartType;
            timeSeriesChart.update();
            
            // Update active button
            this.parentElement.querySelectorAll('.btn').forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
        });
    });
    
    // Map view toggle
    document.querySelectorAll('[data-map]').forEach(button => {
        button.addEventListener('click', function() {
            const mapType = this.dataset.map;
            updateMapView(mapType);
            
            // Update active button
            this.parentElement.querySelectorAll('.btn').forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
        });
    });
}

function updateMapView(viewType) {
    // Clear existing markers
    analyticsMap.eachLayer(function (layer) {
        if (layer instanceof L.CircleMarker) {
            analyticsMap.removeLayer(layer);
        }
    });
    
    // Re-add markers based on view type
    analyticsData.forEach(barangay => {
        let lat = parseFloat(barangay.latitude) || 6.9214;
        let lng = parseFloat(barangay.longitude) || 122.0790;
        
        if (!barangay.latitude || !barangay.longitude) {
            lat += (Math.random() - 0.5) * 0.1;
            lng += (Math.random() - 0.5) * 0.1;
        }
        
        const caseCount = parseInt(barangay.total_cases) || 0;
        const population = parseInt(barangay.population) || 1;
        const rate = (caseCount / population) * 1000;
        
        let radius, color, weight, value;
        
        if (viewType === 'rate') {
            // Color based on rate per 1000
            value = rate;
            if (rate >= 10) {
                radius = 15; color = '#dc3545'; weight = 3;
            } else if (rate >= 5) {
                radius = 12; color = '#fd7e14'; weight = 2;
            } else if (rate >= 1) {
                radius = 8; color = '#ffc107'; weight = 2;
            } else {
                radius = 5; color = '#28a745'; weight = 1;
            }
        } else {
            // Color based on case count (default)
            value = caseCount;
            if (caseCount >= 10) {
                radius = 15; color = '#dc3545'; weight = 3;
            } else if (caseCount >= 5) {
                radius = 12; color = '#fd7e14'; weight = 2;
            } else if (caseCount >= 1) {
                radius = 8; color = '#ffc107'; weight = 2;
            } else {
                radius = 5; color = '#28a745'; weight = 1;
            }
        }

        const circle = L.circleMarker([lat, lng], {
            radius: radius,
            fillColor: color,
            color: '#fff',
            weight: weight,
            opacity: 1,
            fillOpacity: 0.8
        });

        const popupContent = `
            <div style="min-width: 200px;">
                <h6 style="margin: 0 0 10px 0; color: #333; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                    <i class="fas fa-map-marker-alt" style="color: ${color};"></i> 
                    ${barangay.barangay_name}
                </h6>
                <div style="font-size: 13px; line-height: 1.4;">
                    <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                        <span><strong>Total Cases:</strong></span>
                        <span class="badge" style="background: ${viewType === 'cases' ? color : '#6c757d'}; color: white; padding: 2px 6px; border-radius: 10px;">${caseCount}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                        <span><strong>Population:</strong></span>
                        <span>${population.toLocaleString()}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                        <span><strong>Rate:</strong></span>
                        <span class="badge" style="background: ${viewType === 'rate' ? color : '#6c757d'}; color: white; padding: 2px 6px; border-radius: 10px;">${rate.toFixed(2)}/1000</span>
                    </div>
                    ${barangay.critical_cases > 0 ? `
                    <div style="display: flex; justify-content: space-between; margin: 4px 0; color: #dc3545;">
                        <span><strong>Critical:</strong></span>
                        <span>${barangay.critical_cases}</span>
                    </div>
                    ` : ''}
                    ${barangay.severe_cases > 0 ? `
                    <div style="display: flex; justify-content: space-between; margin: 4px 0; color: #fd7e14;">
                        <span><strong>Severe:</strong></span>
                        <span>${barangay.severe_cases}</span>
                    </div>
                    ` : ''}
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee;">
                        <a href="analytics.php?barangay=${barangay.barangay_id}" 
                           style="color: #007bff; text-decoration: none; font-size: 12px;">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                </div>
            </div>
        `;

        circle.bindPopup(popupContent, {
            maxWidth: 250,
            className: 'custom-popup'
        });
        
        circle.on('mouseover', function (e) {
            this.setStyle({
                weight: weight + 2,
                fillOpacity: 1
            });
        });
        
        circle.on('mouseout', function (e) {
            this.setStyle({
                weight: weight,
                fillOpacity: 0.8
            });
        });
        
        circle.addTo(analyticsMap);
    });
}

function updateDateRange(range) {
    if (range !== 'custom') {
        const endDate = new Date();
        const startDate = new Date();
        startDate.setDate(endDate.getDate() - parseInt(range));
        
        document.querySelector('input[name="start_date"]').value = startDate.toISOString().split('T')[0];
        document.querySelector('input[name="end_date"]').value = endDate.toISOString().split('T')[0];
        
        // Auto-submit form
        document.querySelector('form').submit();
    }
}

function exportToCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = 'analytics.php?' + params.toString();
}

function generatePDFReport() {
    alert('PDF report generation will be implemented');
}

function toggleView(view) {
    if (view === 'heatmap') {
        // Implement heatmap view
        alert('Heatmap view to be implemented');
    }
}
</script>


</body>
</html>