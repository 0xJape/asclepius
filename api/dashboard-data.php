<?php
// filepath: c:\xampp\htdocs\asclpe\api\dashboard-data.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Start session and check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get database connection
$conn = getDBConnection(); // Fixed function name

// Get the days parameter for filtering
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;

// Calculate dates
$today = date('Y-m-d');
$start_date = date('Y-m-d', strtotime("-{$days} days"));

// Prepare response array
$response = [
    'stats' => [],
    'alerts' => [],
    'cases' => [],
    'barangay_data' => []
];

// Get environmental data (replace with actual sensor data in production)
$env_data = [
    'temperature' => rand(28, 35),
    'humidity' => rand(65, 85)
];

// Calculate risk score based on environmental conditions and case numbers
function calculateRiskScore($cases, $temp, $humidity) {
    $score = 0;
    
    // Cases weight
    if ($cases > 50) $score += 40;
    elseif ($cases > 20) $score += 20;
    else $score += 10;
    
    // Temperature weight (dengue mosquitoes thrive in 28-32°C)
    if ($temp >= 28 && $temp <= 32) $score += 30;
    elseif ($temp > 32) $score += 20;
    
    // Humidity weight (optimal breeding conditions 70-80%)
    if ($humidity >= 70 && $humidity <= 80) $score += 30;
    elseif ($humidity > 80) $score += 20;
    
    return min($score, 100);
}

try {
    // Fetch total cases (all time)
    $total_query = "SELECT COUNT(*) as total FROM patient_cases";
    $total_result = $conn->query($total_query);
    $total_cases = $total_result->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Fetch cases for selected period
    $period_cases_query = "SELECT COUNT(*) as period_cases FROM patient_cases WHERE date_reported >= ?";
    $stmt = $conn->prepare($period_cases_query);
    $stmt->execute([$start_date]);
    $period_cases = $stmt->fetch(PDO::FETCH_ASSOC)['period_cases'];
    
    // Fetch previous period cases for comparison
    $prev_start = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
    $prev_end = date('Y-m-d', strtotime("-{$days} days"));
    $prev_cases_query = "SELECT COUNT(*) as prev_cases FROM patient_cases WHERE date_reported BETWEEN ? AND ?";
    $stmt = $conn->prepare($prev_cases_query);
    $stmt->execute([$prev_start, $prev_end]);
    $prev_cases = $stmt->fetch(PDO::FETCH_ASSOC)['prev_cases'];
    
    // Calculate trend
    $trend_percent = $prev_cases > 0 ? (($period_cases - $prev_cases) / $prev_cases) * 100 : 0;
    
    // Calculate current risk score
    $risk_score = calculateRiskScore($period_cases, $env_data['temperature'], $env_data['humidity']);
    
    // Determine risk level
    $risk_level = 'LOW';
    $risk_color = 'success';
    if ($risk_score > 70) {
        $risk_level = 'HIGH';
        $risk_color = 'danger';
    } elseif ($risk_score > 40) {
        $risk_level = 'MEDIUM';
        $risk_color = 'warning';
    }
    
    // Prepare stats data
    $response['stats'] = [
        'total_cases' => $total_cases,
        'period_cases' => $period_cases,
        'trend_percent' => round($trend_percent),
        'risk_score' => $risk_score,
        'risk_level' => $risk_level,
        'risk_color' => $risk_color,
        'temperature' => $env_data['temperature'],
        'humidity' => $env_data['humidity'],
        'days' => $days
    ];
    
    // Fetch barangay data for the selected period (for charts and map)
    $barangay_query = "
        SELECT 
            b.barangay_id,
            b.name,
            b.latitude,
            b.longitude,
            b.population,
            COUNT(pc.case_id) as case_count
        FROM barangays b
        LEFT JOIN patients p ON b.barangay_id = p.barangay_id
        LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id 
            AND pc.date_reported >= ?
        GROUP BY b.barangay_id, b.name, b.latitude, b.longitude, b.population
        ORDER BY case_count DESC
    ";
    $stmt = $conn->prepare($barangay_query);
    $stmt->execute([$start_date]);
    $barangay_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['barangay_data'] = $barangay_data;
    
    // Fetch active alerts (using the existing alerts system)
    $alerts = getActiveAlerts(); // Use your existing function
    $response['alerts'] = $alerts['priority'] ?? [];
    
    // Fetch recent cases with patient and barangay data
    $cases_query = "
        SELECT 
            pc.case_id,
            pc.date_reported,
            pc.status,
            pc.temperature,
            pc.symptoms,
            p.first_name,
            p.last_name,
            p.age,
            p.gender,
            b.name as barangay_name,
            b.latitude,
            b.longitude
        FROM patient_cases pc
        JOIN patients p ON pc.patient_id = p.patient_id
        JOIN barangays b ON p.barangay_id = b.barangay_id
        WHERE pc.date_reported >= ?
        ORDER BY pc.date_reported DESC, pc.created_at DESC
        LIMIT 20
    ";
    $stmt = $conn->prepare($cases_query);
    $stmt->execute([$start_date]);
    $cases_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($cases_data as $case) {
        $response['cases'][] = [
            'id' => $case['case_id'],
            'date' => $case['date_reported'],
            'patient_name' => $case['first_name'] . ' ' . $case['last_name'],
            'age' => $case['age'],
            'gender' => $case['gender'],
            'barangay' => $case['barangay_name'],
            'latitude' => $case['latitude'],
            'longitude' => $case['longitude'],
            'status' => $case['status'],
            'temperature' => $case['temperature'],
            'symptoms' => $case['symptoms']
        ];
    }
    
    // Set response headers
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
    error_log("Dashboard API Error: " . $e->getMessage());
}
?>