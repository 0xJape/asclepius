<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Set timezone to Philippine Standard Time (GMT+8)
date_default_timezone_set('Asia/Manila');

// Verify user is logged in
checkAuth();

// Get filter parameters
$selectedBarangay = isset($_GET['barangay']) ? (int)$_GET['barangay'] : 0;
$predictionDays = isset($_GET['prediction_days']) ? (int)$_GET['prediction_days'] : 16; // Default to 16-day prediction (maximum)
$historyDays = isset($_GET['history_days']) ? (int)$_GET['history_days'] : 90; // Default to 90 days history
$startDate = date('Y-m-d', strtotime('-' . $historyDays . ' days'));
$endDate = date('Y-m-d');

// Open-Meteo API configuration
define('DEFAULT_LATITUDE', 6.2167); // Tupi, South Cotabato coordinates
define('DEFAULT_LONGITUDE', 124.9500);

// Function to get weather data from Open-Meteo API
function getWeatherData($latitude = null, $longitude = null, $days = 16) {
    try {
        // Use coordinates if provided, otherwise use default location
        $lat = $latitude ?: DEFAULT_LATITUDE;
        $lon = $longitude ?: DEFAULT_LONGITUDE;
        
        // Build Open-Meteo API URL
        $url = "https://api.open-meteo.com/v1/forecast?" .
            "latitude=$lat&longitude=$lon" .
            // Request hourly humidity and weather code; Open-Meteo parameter names vary so we keep existing hourly fields
            "&hourly=temperature_2m,relative_humidity_2m,precipitation,weather_code" .
            // Request daily summaries (we'll compute daily humidity from hourly if daily mean is not provided)
            "&daily=temperature_2m_max,temperature_2m_min,precipitation_sum,weather_code" .
            "&forecast_days=$days" .
            "&timezone=Asia%2FManila";
        
        // Make API request using cURL for better error handling
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For HTTPS
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception("cURL Error: " . curl_error($ch));
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("API Error: HTTP Code " . $httpCode);
        }
        
        // Parse JSON response
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['hourly'])) {
            throw new Exception("Invalid weather data response");
        }
        
        // Convert Open-Meteo data to our expected format
        $weatherData = convertOpenMeteoData($data);
        
        return $weatherData;
        
    } catch (Exception $e) {
        error_log("Weather API error: " . $e->getMessage());
        
        // Return error structure if API call fails
        return [
            'success' => false,
            'current' => [
                'temp_c' => 28,
                'condition' => ['text' => 'Unknown'],
                'humidity' => 70,
                'precip_mm' => 0,
                'wind_kph' => 10,
                'weather_code' => 1
            ],
            'forecast' => [],
            'location' => [
                'name' => 'Tupi',
                'region' => 'South Cotabato',
                'country' => 'Philippines'
            ],
            'error' => $e->getMessage()
        ];
    }
}

// Convert Open-Meteo data to our expected format
function convertOpenMeteoData($openMeteoData) {
    $current_hour = date('G'); // Get current hour (0-23)
    
    // Get current weather from hourly data
    $current_temp = $openMeteoData['hourly']['temperature_2m'][$current_hour] ?? 28;
    // Support both possible hourly humidity key names
    $current_humidity = $openMeteoData['hourly']['relative_humidity_2m'][$current_hour] ??
                        $openMeteoData['hourly']['relativehumidity_2m'][$current_hour] ?? 70;
    $current_precip = $openMeteoData['hourly']['precipitation'][$current_hour] ?? 0;
    $current_weather_code = $openMeteoData['hourly']['weather_code'][$current_hour] ?? $openMeteoData['hourly']['weathercode'][$current_hour] ?? 1;
    
    // Convert weather code to condition text
    $weather_condition = getWeatherCondition($current_weather_code);
    
    // Build forecast array
    $forecast = [];
    $daily_data = $openMeteoData['daily'];
    
    // Prepare shortcuts for hourly arrays for fallback averaging
    $hourly_times = $openMeteoData['hourly']['time'] ?? [];
    $hourly_humidity1 = $openMeteoData['hourly']['relative_humidity_2m'] ?? [];
    $hourly_humidity2 = $openMeteoData['hourly']['relativehumidity_2m'] ?? [];

    for ($i = 0; $i < count($daily_data['time']); $i++) {
        // Prefer daily mean humidity if provided by API (check a couple of possible keys)
        $dailyHumidity = null;
        if (isset($daily_data['relativehumidity_2m_mean'][$i])) {
            $dailyHumidity = $daily_data['relativehumidity_2m_mean'][$i];
        } elseif (isset($daily_data['relative_humidity_2m_mean'][$i])) {
            $dailyHumidity = $daily_data['relative_humidity_2m_mean'][$i];
        }

        // Fallback: compute average from hourly humidity values for that date
        if ($dailyHumidity === null) {
            $date = $daily_data['time'][$i]; // format YYYY-MM-DD
            $sum = 0;
            $count = 0;
            foreach ($hourly_times as $hIdx => $hTime) {
                if (strpos($hTime, $date) === 0) {
                    if (isset($hourly_humidity1[$hIdx])) {
                        $sum += $hourly_humidity1[$hIdx];
                        $count++;
                    } elseif (isset($hourly_humidity2[$hIdx])) {
                        $sum += $hourly_humidity2[$hIdx];
                        $count++;
                    }
                }
            }

            if ($count > 0) {
                $dailyHumidity = $sum / $count;
            } else {
                // Last resort: use the current hourly humidity
                $dailyHumidity = $current_humidity;
            }
        }

        $forecast[] = [
            'date' => $daily_data['time'][$i],
            'day' => [
                'maxtemp_c' => $daily_data['temperature_2m_max'][$i],
                'mintemp_c' => $daily_data['temperature_2m_min'][$i],
                // average temperature for compatibility with existing code
                'avgtemp_c' => isset($daily_data['temperature_2m_max'][$i], $daily_data['temperature_2m_min'][$i]) ? (
                    ($daily_data['temperature_2m_max'][$i] + $daily_data['temperature_2m_min'][$i]) / 2
                ) : null,
                'totalprecip_mm' => $daily_data['precipitation_sum'][$i],
                'avghumidity' => $dailyHumidity,
                'condition' => ['text' => getWeatherCondition($daily_data['weather_code'][$i] ?? $daily_data['weathercode'][$i] ?? 0)]
            ]
        ];
    }
    
    return [
        'success' => true,
        'current' => [
            'temp_c' => $current_temp,
            'condition' => ['text' => $weather_condition],
            'humidity' => $current_humidity,
            'precip_mm' => $current_precip,
            'wind_kph' => 10, // Open-Meteo hourly doesn't include wind by default
            'weather_code' => $current_weather_code
        ],
        'forecast' => $forecast,
        'location' => [
            'name' => 'Tupi',
            'region' => 'South Cotabato',
            'country' => 'Philippines',
            'lat' => $openMeteoData['latitude'] ?? DEFAULT_LATITUDE,
            'lon' => $openMeteoData['longitude'] ?? DEFAULT_LONGITUDE
        ],
        'error' => null
    ];
}

// Convert WMO weather codes to readable conditions
function getWeatherCondition($code) {
    $conditions = [
        0 => 'Clear sky',
        1 => 'Mainly clear',
        2 => 'Partly cloudy',
        3 => 'Overcast',
        45 => 'Fog',
        48 => 'Depositing rime fog',
        51 => 'Light drizzle',
        53 => 'Moderate drizzle',
        55 => 'Dense drizzle',
        61 => 'Slight rain',
        63 => 'Moderate rain',
        65 => 'Heavy rain',
        71 => 'Slight snow',
        73 => 'Moderate snow',
        75 => 'Heavy snow',
        80 => 'Slight rain showers',
        81 => 'Moderate rain showers',
        82 => 'Violent rain showers',
        95 => 'Thunderstorm',
        96 => 'Thunderstorm with slight hail',
        99 => 'Thunderstorm with heavy hail'
    ];
    
    return $conditions[$code] ?? 'Unknown';
}

// Get barangays for dropdown
function getBarangays() {
    $db = getDBConnection();
    try {
        $stmt = $db->query("SELECT barangay_id, name, latitude, longitude FROM barangays ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("Error getting barangays: " . $e->getMessage());
        return [];
    }
}

// Get barangays for dropdown
$barangays = getBarangays();

// Debug: Add HTML comment for debugging
$debugInfo = "<!-- DEBUG: Loaded " . count($barangays) . " barangays, Selected: $selectedBarangay -->";

// Get selected barangay's location data
$locationData = null;
if ($selectedBarangay > 0) {
    foreach ($barangays as $barangay) {
        if ($barangay['barangay_id'] == $selectedBarangay) {
            $locationData = [
                'latitude' => $barangay['latitude'],
                'longitude' => $barangay['longitude']
            ];
            break;
        }
    }
}

// Get weather data
$weatherData = getWeatherData(
    $locationData && isset($locationData['latitude']) ? $locationData['latitude'] : null,
    $locationData && isset($locationData['longitude']) ? $locationData['longitude'] : null,
    $predictionDays // Open-Meteo supports up to 16 days forecast
);

// For testing, let's store in session to avoid repeated API calls
$_SESSION['last_weather_data'] = $weatherData;

// Get selected barangay name for display
$selectedBarangayName = 'All Barangays';
if ($selectedBarangay > 0) {
    foreach ($barangays as $barangay) {
        if ($barangay['barangay_id'] == $selectedBarangay) {
            $selectedBarangayName = $barangay['name'];
            break;
        }
    }
}
?>

<?php
// Function to calculate dengue mosquito breeding risk score based on weather parameters
function calculateRiskScore($temp, $humidity, $rainfall) {
    // Initialize risk score
    $riskScore = 0;
    
    // Temperature factors (28-32°C is optimal for dengue mosquitoes)
    if ($temp >= 28 && $temp <= 32) {
        $riskScore += 40; // High risk temperature range
    } elseif ($temp >= 26 && $temp < 28 || $temp > 32 && $temp <= 34) {
        $riskScore += 30; // Moderate-high risk temperature range
    } elseif ($temp >= 24 && $temp < 26 || $temp > 34 && $temp <= 36) {
        $riskScore += 20; // Moderate risk temperature range
    } elseif ($temp >= 20 && $temp < 24 || $temp > 36 && $temp <= 40) {
        $riskScore += 10; // Low-moderate risk temperature range
    } else {
        $riskScore += 5; // Low risk temperature range
    }
    
    // Humidity factors (high humidity favors breeding)
    if ($humidity >= 80) {
        $riskScore += 30; // High risk humidity
    } elseif ($humidity >= 70 && $humidity < 80) {
        $riskScore += 25; // Moderate-high risk humidity
    } elseif ($humidity >= 60 && $humidity < 70) {
        $riskScore += 15; // Moderate risk humidity
    } elseif ($humidity >= 50 && $humidity < 60) {
        $riskScore += 10; // Low-moderate risk humidity
    } else {
        $riskScore += 5; // Low risk humidity
    }
    
    // Rainfall factors (standing water creates breeding sites)
    if ($rainfall > 10) {
        $riskScore += 30; // High risk rainfall
    } elseif ($rainfall > 5 && $rainfall <= 10) {
        $riskScore += 25; // Moderate-high risk rainfall
    } elseif ($rainfall > 2 && $rainfall <= 5) {
        $riskScore += 15; // Moderate risk rainfall
    } elseif ($rainfall > 0 && $rainfall <= 2) {
        $riskScore += 10; // Low-moderate risk rainfall
    } else {
        $riskScore += 0; // No rain, minimal breeding sites from rainfall
    }
    
    return $riskScore;
}

// Function to get risk level and color based on score
function getRiskLevel($score) {
    if ($score >= 80) {
        return ['level' => 'Very High', 'color' => 'darkred', 'class' => 'bg-danger text-white'];
    } elseif ($score >= 60) {
        return ['level' => 'High', 'color' => 'red', 'class' => 'bg-danger text-white'];
    } elseif ($score >= 40) {
        return ['level' => 'Moderate', 'color' => 'orange', 'class' => 'bg-warning'];
    } elseif ($score >= 20) {
        return ['level' => 'Low', 'color' => 'yellow', 'class' => 'bg-info'];
    } else {
        return ['level' => 'Very Low', 'color' => 'green', 'class' => 'bg-success text-white'];
    }
}

// Calculate current risk score and level
$currentTemp = $weatherData['current']['temp_c'];
$currentHumidity = $weatherData['current']['humidity'];
$currentRainfall = $weatherData['current']['precip_mm'];
$currentRiskScore = calculateRiskScore($currentTemp, $currentHumidity, $currentRainfall);
$currentRiskLevel = getRiskLevel($currentRiskScore);

// Calculate forecasted risk for each day
$forecastRisk = [];
foreach ($weatherData['forecast'] as $day) {
    $dayTemp = $day['day']['avgtemp_c'];
    $dayHumidity = $day['day']['avghumidity'];
    $dayRainfall = $day['day']['totalprecip_mm'];
    $dayRiskScore = calculateRiskScore($dayTemp, $dayHumidity, $dayRainfall);
    $dayRiskLevel = getRiskLevel($dayRiskScore);
    
    $forecastRisk[] = [
        'date' => $day['date'],
        'day' => date('D, M j', strtotime($day['date'])),
        'temp' => $dayTemp,
        'humidity' => $dayHumidity,
        'rainfall' => $dayRainfall,
        'riskScore' => $dayRiskScore,
        'riskLevel' => $dayRiskLevel
    ];
}

// Generate recommendations based on risk level
function getRecommendations($riskLevel) {
    switch ($riskLevel['level']) {
        case 'Very High':
            return [
                'Eliminate all standing water in and around homes immediately',
                'Use mosquito repellent at all times when outdoors',
                'Ensure all windows and doors have intact screens',
                'Wear long sleeves and pants, even during daytime',
                'Conduct daily yard inspections for potential breeding sites',
                'Consider community-wide mosquito control operations'
            ];
        case 'High':
            return [
                'Remove standing water from containers around the home',
                'Use mosquito repellent regularly when outdoors',
                'Wear protective clothing during peak mosquito activity times',
                'Ensure proper drainage around homes',
                'Consider using mosquito nets during sleep'
            ];
        case 'Moderate':
            return [
                'Check and empty containers that collect water weekly',
                'Use mosquito repellent during outdoor activities',
                'Be aware of peak mosquito activity times (dawn and dusk)',
                'Ensure window screens are in good condition'
            ];
        case 'Low':
            return [
                'Maintain regular inspection of potential water containers',
                'Use mosquito repellent for extended outdoor activities',
                'Continue environmental management practices'
            ];
        default:
            return [
                'Maintain general mosquito awareness',
                'Continue routine prevention measures'
            ];
    }
}

$recommendations = getRecommendations($currentRiskLevel);

// Calculate a simple dengue transmission trend
$riskTrend = 'stable';
if (count($forecastRisk) >= 3) {
    $firstDayScore = $forecastRisk[0]['riskScore'];
    $lastDayScore = $forecastRisk[count($forecastRisk)-1]['riskScore'];
    $difference = $lastDayScore - $firstDayScore;
    
    if ($difference > 10) {
        $riskTrend = 'increasing';
    } elseif ($difference < -10) {
        $riskTrend = 'decreasing';
    }
}

// Create data for chart
$riskChartData = [];
$dateLabels = [];
$riskScores = [];
$temperatureData = [];
$humidityData = [];
$rainfallData = [];

foreach ($forecastRisk as $day) {
    $dateLabels[] = $day['day'];
    $riskScores[] = $day['riskScore'];
    $temperatureData[] = $day['temp'];
    $humidityData[] = $day['humidity'];
    $rainfallData[] = $day['rainfall'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dengue Risk Prediction - Dengue Monitoring System</title>
    
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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Modern Design System CSS -->
    <link href="assets/css/modern.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/dashboard.css" rel="stylesheet">
    
    <style>
        /* Prediction Page Specific Styling */
        .risk-meter {
            position: relative;
            width: 200px;
            height: 100px;
            margin: 0 auto;
            overflow: hidden;
        }
        .risk-meter .meter {
            position: relative;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: conic-gradient(
                #198754 0% 20%,
                #0dcaf0 20% 40%,
                #ffc107 40% 60%,
                #fd7e14 60% 80%,
                #dc3545 80% 100%
            );
            margin-bottom: -100px;
        }
        .risk-meter .needle {
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 4px;
            background: #333;
            transform-origin: bottom center;
            z-index: 10;
        }
        
        /* Analytics-inspired Styling */
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
        
        /* Forecast Card Enhanced Styling */
        .forecast-card {
            transition: all 0.3s ease;
            border-radius: 15px !important;
            overflow: hidden;
        }
        
        .forecast-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.2) !important;
        }
        
        .forecast-card .card-body {
            padding: 1.25rem;
        }
        
        .forecast-card .weather-icon-container img {
            transition: all 0.3s ease;
        }
        
        .forecast-card:hover .weather-icon-container img {
            transform: scale(1.15);
        }
        
        .forecast-card .risk-score-section {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .forecast-card:hover .risk-score-section {
            background-color: #f0f0f0 !important;
        }
        
        .forecast-card .weather-factors .col-4 div {
            transition: all 0.3s ease;
        }
        
        .forecast-card:hover .weather-factors .col-4 div {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        /* Risk Score Animation */
        @keyframes pulseRisk {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .forecast-card:hover .display-6 {
            animation: pulseRisk 1.5s infinite ease-in-out;
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
        
        /* Weather Factor Card Hover Effects */
        .weather-factor-card {
            transition: all 0.3s ease;
        }
        
        .weather-factor-card:hover {
            background-color: #ffffff !important;
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1) !important;
        }
        
        /* Temperature Range Animation */
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .forecast-card:hover .temp-line div:last-child {
            background: linear-gradient(90deg, #0d6efd, #dc3545, #0d6efd);
            background-size: 200% 200%;
            animation: gradientShift 3s ease infinite;
        }
        
        /* Card Header Decoration Animation */
        @keyframes pulseCircle {
            0% { transform: scale(1); opacity: 0.7; }
            50% { transform: scale(1.2); opacity: 0.5; }
            100% { transform: scale(1); opacity: 0.7; }
        }
        
        .forecast-card:hover .position-absolute {
            animation: pulseCircle 3s ease-in-out infinite;
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
        
        .trend-stable {
            background: #f8f9fa;
            color: #6c757d;
        }
        
        .risk-card {
            transition: transform 0.3s ease;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .risk-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }
        
        .severity-critical { background: #dc3545; color: white; }
        .severity-high { background: #fd7e14; color: white; }
        .severity-moderate { background: #ffc107; color: black; }
        .severity-low { background: #0dcaf0; color: white; }
        .severity-verylow { background: #198754; color: white; }
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
                <a href="analytics.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Analytics</span>
                </a>
                <a href="prediction.php" class="menu-item active">
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
                    <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Demo User'); ?></div>
                    <div class="user-role">Administrator</div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="content-header d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="page-title mb-0">Dengue Risk Prediction</h1>
                <small class="text-muted">Mosquito breeding risk based on weather conditions
                    <?php if ($selectedBarangay > 0): ?>
                        <br><strong>Selected Area:</strong> <?php echo htmlspecialchars($selectedBarangayName); ?>
                    <?php else: ?>
                        <br><strong>Viewing:</strong> All Barangays
                    <?php endif; ?>
                </small>
            </div>
        </header>

        <?php echo $debugInfo; ?>

        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" action="prediction.php" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-map-marker-alt me-2 text-primary"></i>Barangay
                    </label>
                    <select class="form-select shadow-sm" name="barangay" id="barangaySelect">
                        <option value="0" <?php echo $selectedBarangay == 0 ? 'selected' : ''; ?>>All Barangays</option>
                        <?php foreach ($barangays as $barangay): ?>
                            <option value="<?php echo $barangay['barangay_id']; ?>" 
                                    <?php echo $selectedBarangay == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($barangay['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-calendar-alt me-2 text-primary"></i>Forecast Days
                    </label>
                    <select class="form-select shadow-sm" name="prediction_days">
                        <option value="3" <?php echo $predictionDays == 3 ? 'selected' : ''; ?>>3 days</option>
                        <option value="5" <?php echo $predictionDays == 5 ? 'selected' : ''; ?>>5 days</option>
                        <option value="7" <?php echo $predictionDays == 7 ? 'selected' : ''; ?>>7 days</option>
                        <option value="10" <?php echo $predictionDays == 10 ? 'selected' : ''; ?>>10 days</option>
                        <option value="14" <?php echo $predictionDays == 14 ? 'selected' : ''; ?>>14 days</option>
                        <option value="16" <?php echo $predictionDays == 16 ? 'selected' : ''; ?>>16 days (max)</option>
                    </select>
                </div>
                
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2 shadow">
                        <i class="fas fa-search me-2"></i> Generate Prediction
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Current Dengue Risk Summary -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="analytics-card h-100 shadow">
                    <div class="card-header bg-primary text-white d-flex align-items-center">
                        <div class="stat-icon bg-white text-primary me-3">
                            <i class="fas fa-bug"></i>
                        </div>
                        <h5 class="mb-0">Current Dengue Risk</h5>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($weatherData['success']): ?>
                            <h2 class="mb-3">
                                <span class="badge <?php echo $currentRiskLevel['class']; ?> p-3 shadow-sm" style="font-size: 1.5rem;">
                                    <?php echo $currentRiskLevel['level']; ?> Risk
                                </span>
                            </h2>
                            
                            <div class="risk-meter mb-3">
                                <div class="meter shadow"></div>
                                <div class="needle" style="height: 100px; transform: rotate(<?php echo $currentRiskScore * 1.8; ?>deg);"></div>
                            </div>
                            
                            <div class="mt-4 p-3 bg-light rounded-3">
                                <h4 class="mb-1">Risk Score: <strong><?php echo $currentRiskScore; ?>/100</strong></h4>
                                <div class="progress mb-3" style="height: 10px;">
                                    <div class="progress-bar <?php echo str_replace('bg-', 'bg-', $currentRiskLevel['class']); ?>" 
                                         role="progressbar" style="width: <?php echo $currentRiskScore; ?>%" 
                                         aria-valuenow="<?php echo $currentRiskScore; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <p class="mb-0 text-muted">
                                    Based on current conditions at <?php echo date('h:i A'); ?> PST
                                    <br><small class="text-muted">Last updated: <?php echo date('M j, Y \a\t g:i A'); ?></small>
                                </p>
                            </div>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                    <span><i class="fas fa-temperature-high text-danger me-2"></i> Temperature:</span>
                                    <span class="fw-bold"><?php echo $currentTemp; ?>°C</span>
                                </div>
                                <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                    <span><i class="fas fa-tint text-primary me-2"></i> Humidity:</span>
                                    <span class="fw-bold"><?php echo $currentHumidity; ?>%</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span><i class="fas fa-cloud-rain text-secondary me-2"></i> Rainfall:</span>
                                    <span class="fw-bold"><?php echo $currentRainfall; ?> mm</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Unable to calculate risk: No weather data available
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="analytics-card h-100 shadow">
                    <div class="card-header bg-primary text-white d-flex align-items-center">
                        <div class="stat-icon bg-white text-primary me-3">
                            <i class="fas fa-shield-virus"></i>
                        </div>
                        <h5 class="mb-0">Risk Trend & Prevention Measures</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($weatherData['success']): ?>
                            <div class="d-flex align-items-center mb-4">
                                <div class="me-3">
                                    <div class="stat-icon severity-<?php echo strtolower(str_replace(' ', '', $currentRiskLevel['level'])); ?>">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                </div>
                                <div>
                                    <h5 class="mb-1">Dengue Risk Forecast</h5>
                                    <div class="d-flex align-items-center">
                                        <span class="trend-indicator trend-<?php echo $riskTrend; ?> me-2">
                                            <i class="fas fa-<?php echo $riskTrend == 'increasing' ? 'arrow-up' : ($riskTrend == 'decreasing' ? 'arrow-down' : 'arrow-right'); ?> me-1"></i>
                                            <?php echo ucfirst($riskTrend); ?>
                                        </span>
                                        <span class="text-muted small">
                                            Next <?php echo isset($weatherData['forecast']) ? count($weatherData['forecast']) : 0; ?> days
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <h5 class="d-flex align-items-center mb-3">
                                <div class="stat-icon bg-light text-primary me-2" style="width: 40px; height: 40px; font-size: 1rem;">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <span>Recommended Prevention Measures</span>
                            </h5>
                            
                            <div class="list-group shadow-sm">
                                <?php foreach ($recommendations as $index => $recommendation): ?>
                                <div class="list-group-item list-group-item-action d-flex align-items-center">
                                    <div class="rounded-circle bg-<?php 
                                        echo $index < 2 ? 'danger' : ($index < 4 ? 'warning' : 'info'); 
                                    ?> text-white d-flex align-items-center justify-content-center me-3" 
                                         style="width: 28px; height: 28px; font-size: 0.8rem;">
                                        <span><?php echo $index + 1; ?></span>
                                    </div>
                                    <span><?php echo $recommendation; ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Unable to generate recommendations: No weather data available
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Dengue Risk Forecast -->
        <div class="analytics-card mb-4 shadow">
            <div class="card-header d-flex justify-content-between align-items-center bg-primary text-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-white text-primary me-3">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <h5 class="mb-0">Daily Dengue Risk Forecast</h5>
                </div>              
            </div>
            <div class="card-body">
                <?php if (!$weatherData['success']): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Failed to retrieve weather data: <?php echo htmlspecialchars($weatherData['error']); ?>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($forecastRisk as $index => $day): ?>
                        <div class="col-sm-6 col-md-4 col-lg-<?php echo min(3, 12 / count($forecastRisk)); ?>">
                            <div class="card forecast-card h-100 border-0 shadow" style="border-radius: 12px; overflow: hidden; transition: all 0.3s ease; font-size: 0.9rem;">
                                <!-- Card Header with Day and Risk Label -->
                                <div class="position-relative">
                                    <!-- Risk Badge - Positioned at top right - Smaller -->
                                    <div class="position-absolute top-0 end-0 m-1">
                                        <div class="badge <?php echo $day['riskLevel']['class']; ?> p-1 shadow" style="font-size: 0.65rem;">
                                            <?php echo $day['riskLevel']['level']; ?> Risk
                                        </div>
                                    </div>
                                    
                                    <!-- Day Header with Enhanced Gradient Background - Smaller -->
                                    <div class="text-center py-2" 
                                         style="background: linear-gradient(45deg, #2c3e50, #3498db); color: white; position: relative; overflow: hidden;">
                                        <!-- Decorative Elements - Smaller -->
                                        <div class="position-absolute" style="top: -10px; right: -10px; width: 30px; height: 30px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                                        <div class="position-absolute" style="bottom: -5px; left: -5px; width: 20px; height: 20px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                                        
                                        <h6 class="mb-0 fw-bold position-relative"><?php echo $day['day']; ?></h6>
                                        <div class="small text-white-50" style="font-size: 0.7rem;"><?php echo date('Y-m-d', strtotime($day['date'])); ?></div>
                                    </div>
                                </div>
                                
                                <!-- Enhanced Weather Condition Section -->
                                <div class="card-body text-center p-2">
                                    <div class="mb-4 position-relative">
                                        <!-- Weather Icon with Enhanced Styling - Smaller -->
                                        <div class="weather-icon-container position-relative d-inline-block">
                                            <div class="rounded-circle p-2" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; margin: 0 auto; background: linear-gradient(135deg, #f8f9fa, #e9ecef); box-shadow: 0 3px 8px rgba(0,0,0,0.1);">
                                       <?php
                                          // Safely read the icon and condition text with fallbacks to avoid undefined index warnings
                                          $icon = $weatherData['forecast'][$index]['day']['condition']['icon'] ?? 'assets/dengue_logo.png';
                                          $condText = $weatherData['forecast'][$index]['day']['condition']['text'] ?? 'Unknown';
                                       ?>
                                       <img src="<?php echo htmlspecialchars($icon); ?>"
                                           alt="<?php echo htmlspecialchars($condText); ?>"
                                           style="width: 45px; height: 45px; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.1));">
                                            </div>
                                            
                                            <!-- Decorative elements - Smaller -->
                                            <div class="position-absolute" style="width: 12px; height: 12px; background-color: rgba(13, 110, 253, 0.1); border-radius: 50%; top: -3px; left: 3px;"></div>
                                            <div class="position-absolute" style="width: 10px; height: 10px; background-color: rgba(220, 53, 69, 0.1); border-radius: 50%; bottom: 5px; right: 0;"></div>
                                        </div>
                                        
                                        <!-- Weather Condition Text - Smaller -->
                                        <p class="mt-2 mb-1 text-primary fw-bold" style="font-size: 0.9rem;">
                                            <?php echo $weatherData['forecast'][$index]['day']['condition']['text']; ?>
                                        </p>
                                        
                                        <!-- Enhanced Temperature Range Display -->
                                        <?php 
                                        $minTemp = $weatherData['forecast'][$index]['day']['mintemp_c'];
                                        $maxTemp = $weatherData['forecast'][$index]['day']['maxtemp_c'];
                                        $tempRange = $maxTemp - $minTemp;
                                        ?>
                                        <div class="temp-range mt-2">
                                            <!-- Temperature Label - Smaller -->
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <span class="temp-min text-primary fw-bold" style="font-size: 0.7rem;">
                                                    <i class="fas fa-temperature-low"></i> <?php echo $minTemp; ?>°
                                                </span>
                                                <span class="temp-max text-danger fw-bold" style="font-size: 0.7rem;">
                                                    <i class="fas fa-temperature-high"></i> <?php echo $maxTemp; ?>°
                                                </span>
                                            </div>
                                            
                                            <!-- Temperature Gradient Bar - Smaller -->
                                            <div class="position-relative" style="height: 6px;">
                                                <!-- Background -->
                                                <div class="position-absolute w-100" style="height: 6px; border-radius: 3px; background-color: #f0f0f0; top: 0; overflow: hidden;">
                                                    <!-- Gradient Fill -->
                                                    <div class="w-100 h-100" style="background: linear-gradient(90deg, #0d6efd, #6c757d, #dc3545);"></div>
                                                </div>
                                                
                                                <!-- Current Temperature Marker - Smaller -->
                                                <div class="position-absolute" style="top: -4px; left: <?php echo (($day['temp'] - $minTemp) / ($tempRange > 0 ? $tempRange : 1)) * 100; ?>%; transform: translateX(-50%);">
                                                    <div style="width: 14px; height: 14px; background-color: white; border: 2px solid <?php echo $day['riskLevel']['color']; ?>; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></div>
                                                </div>
                                            </div>                                        
                                        </div>
                                    </div>
                                    
                                    <!-- Enhanced Risk Score Section with Interactive Gauge - Smaller -->
                                    <div class="risk-score-section my-2 p-2 rounded" style="background-color: #f8f9fa; border: 1px solid rgba(0,0,0,0.05);">
                                        <h6 class="mb-2 fw-bold" style="font-size: 0.8rem;">Risk Score</h6>
                                        
                                        <!-- Circular Risk Indicator - Smaller -->
                                        <div class="position-relative mb-2">
                                            <div class="mx-auto" style="width: 70px; height: 70px; position: relative;">
                                                <!-- Base Circle -->
                                                <div style="width: 70px; height: 70px; border-radius: 50%; background-color: #e9ecef; position: absolute; top: 0; left: 0;"></div>
                                                
                                                <!-- Colored Progress Circle -->
                                                <div style="width: 70px; height: 70px; border-radius: 50%; 
                                                            background: conic-gradient(
                                                                <?php echo $day['riskLevel']['color']; ?> 0% <?php echo $day['riskScore']; ?>%, 
                                                                #e9ecef <?php echo $day['riskScore']; ?>% 100%
                                                            ); 
                                                            position: absolute; 
                                                            top: 0; 
                                                            left: 0;"></div>
                                                
                                                <!-- Inner White Circle -->
                                                <div style="width: 50px; height: 50px; border-radius: 50%; background-color: white; position: absolute; top: 10px; left: 10px; display: flex; align-items: center; justify-content: center;">
                                                    <span class="fw-bold" style="color: <?php echo $day['riskLevel']['color']; ?>; font-size: 1.2rem;">
                                                        <?php echo $day['riskScore']; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Risk Level Text - Smaller -->
                                        <div class="text-center">
                                            <span class="badge bg-<?php 
                                            if ($day['riskScore'] >= 80) echo "danger";
                                            elseif ($day['riskScore'] >= 60) echo "orange";
                                            elseif ($day['riskScore'] >= 40) echo "warning";
                                            elseif ($day['riskScore'] >= 20) echo "info";
                                            else echo "success";
                                            ?> px-2 py-1" style="font-size: 0.7rem; <?php if ($day['riskScore'] >= 60 && $day['riskScore'] < 80) echo "background-color: #fd7e14;"; ?>">
                                                <?php 
                                                if ($day['riskScore'] >= 80) echo "Very High Risk";
                                                elseif ($day['riskScore'] >= 60) echo "High Risk";
                                                elseif ($day['riskScore'] >= 40) echo "Moderate Risk";
                                                elseif ($day['riskScore'] >= 20) echo "Low Risk";
                                                else echo "Very Low Risk";
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <!-- Enhanced Weather Factors with Interactive Elements - Smaller -->
                                    <div class="weather-factors mt-2">
                                        <h6 class="mb-2 text-start fw-bold" style="font-size: 0.8rem;">Weather Factors</h6>
                                        <div class="row g-2 text-center">
                                            <div class="col-4">
                                                <div class="p-1 rounded bg-light weather-factor-card" style="border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 1px 4px rgba(0,0,0,0.05); height: 100%;">
                                                    <div class="rounded-circle mx-auto mb-1" style="width: 24px; height: 24px; background-color: rgba(220, 53, 69, 0.1); display: flex; align-items: center; justify-content: center;">
                                                        <i class="fas fa-temperature-high text-danger" style="font-size: 0.7rem;"></i>
                                                    </div>
                                                    <div class="text-muted" style="font-size: 0.65rem;">Temp</div>
                                                    <div class="fw-bold" style="font-size: 0.9rem;"><?php echo $day['temp']; ?>°C</div>
                                                    <?php 
                                                    // Add risk indicator for temperature
                                                    $tempRisk = 'Low';
                                                    $tempColor = 'success';
                                                    if ($day['temp'] >= 28 && $day['temp'] <= 32) {
                                                        $tempRisk = 'High';
                                                        $tempColor = 'danger';
                                                    } elseif ($day['temp'] >= 26 && $day['temp'] < 28 || $day['temp'] > 32 && $day['temp'] <= 34) {
                                                        $tempRisk = 'Medium';
                                                        $tempColor = 'warning';
                                                    }
                                                    ?>
                                                    <div class="mt-1"><small class="text-<?php echo $tempColor; ?>" style="font-size: 0.65rem;"><?php echo $tempRisk; ?></small></div>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="p-1 rounded bg-light weather-factor-card" style="border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 1px 4px rgba(0,0,0,0.05); height: 100%;">
                                                    <div class="rounded-circle mx-auto mb-1" style="width: 24px; height: 24px; background-color: rgba(13, 110, 253, 0.1); display: flex; align-items: center; justify-content: center;">
                                                        <i class="fas fa-tint text-primary" style="font-size: 0.7rem;"></i>
                                                    </div>
                                                    <div class="text-muted" style="font-size: 0.65rem;">Humidity</div>
                                                    <div class="fw-bold" style="font-size: 0.9rem;"><?php echo $day['humidity']; ?>%</div>
                                                    <?php 
                                                    // Add risk indicator for humidity
                                                    $humidityRisk = 'Low';
                                                    $humidityColor = 'success';
                                                    if ($day['humidity'] >= 80) {
                                                        $humidityRisk = 'High';
                                                        $humidityColor = 'danger';
                                                    } elseif ($day['humidity'] >= 70 && $day['humidity'] < 80) {
                                                        $humidityRisk = 'Medium';
                                                        $humidityColor = 'warning';
                                                    }
                                                    ?>
                                                    <div class="mt-1"><small class="text-<?php echo $humidityColor; ?>" style="font-size: 0.65rem;"><?php echo $humidityRisk; ?></small></div>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="p-1 rounded bg-light weather-factor-card" style="border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 1px 4px rgba(0,0,0,0.05); height: 100%;">
                                                    <div class="rounded-circle mx-auto mb-1" style="width: 24px; height: 24px; background-color: rgba(108, 117, 125, 0.1); display: flex; align-items: center; justify-content: center;">
                                                        <i class="fas fa-cloud-rain text-secondary" style="font-size: 0.7rem;"></i>
                                                    </div>
                                                    <div class="text-muted" style="font-size: 0.65rem;">Rainfall</div>
                                                    <div class="fw-bold" style="font-size: 0.9rem;"><?php echo $day['rainfall']; ?> mm</div>
                                                    <?php 
                                                    // Add risk indicator for rainfall
                                                    $rainRisk = 'Low';
                                                    $rainColor = 'success';
                                                    if ($day['rainfall'] >= 10) {
                                                        $rainRisk = 'High';
                                                        $rainColor = 'danger';
                                                    } elseif ($day['rainfall'] >= 5 && $day['rainfall'] < 10) {
                                                        $rainRisk = 'Medium';
                                                        $rainColor = 'warning';
                                                    }
                                                    ?>
                                                    <div class="mt-1"><small class="text-<?php echo $rainColor; ?>" style="font-size: 0.65rem;"><?php echo $rainRisk; ?></small></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Enhanced Card Footer with Mosquito Activity - Smaller -->
                                <div class="card-footer p-2 text-center border-0" style="background: linear-gradient(45deg, rgba(240,240,240,0.7), rgba(250,250,250,0.7));">
                                    <div class="d-flex align-items-center justify-content-center">
                                        <div class="rounded-circle me-1" style="width: 20px; height: 20px; background: <?php echo $day['riskLevel']['color']; ?>10; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-mosquito" style="color: <?php echo $day['riskLevel']['color']; ?>; font-size: 0.6rem;"></i>
                                        </div>
                                        <div>
                                            <small class="text-muted" style="font-size: 0.65rem;">Mosquito Activity:</small>
                                            <span class="fw-bold ms-1" style="color: <?php echo $day['riskLevel']['color']; ?>; font-size: 0.7rem;">
                                                <?php 
                                                if ($day['riskScore'] >= 80) echo "Very High";
                                                elseif ($day['riskScore'] >= 60) echo "High";
                                                elseif ($day['riskScore'] >= 40) echo "Moderate";
                                                elseif ($day['riskScore'] >= 20) echo "Low";
                                                else echo "Very Low";
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Risk Factor Trends Chart -->
        <div class="analytics-card mb-4 shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-white text-primary me-3">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h5 class="mb-0">Risk Factor Trends</h5>
                </div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-light active" data-chart="line">
                        <i class="fas fa-chart-line me-1"></i>Line
                    </button>
                    <button class="btn btn-outline-light" data-chart="bar">
                        <i class="fas fa-chart-bar me-1"></i>Bar
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="riskFactorsChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Weather Impact on Dengue -->
        <div class="analytics-card mb-4 shadow">
            <div class="card-header bg-primary text-white d-flex align-items-center">
                <div class="stat-icon bg-white text-primary me-3">
                    <i class="fas fa-biohazard"></i>
                </div>
                <h5 class="mb-0">How Weather Factors Influence Dengue Transmission</h5>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="stat-icon bg-danger text-white me-3" style="width: 50px; height: 50px;">
                                        <i class="fas fa-temperature-high"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-0">Temperature</h5>
                                        <p class="text-muted mb-0 small">Affects mosquito development</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-light border mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span>Current:</span>
                                        <span class="badge bg-primary px-3 py-2"><?php echo $weatherData['current']['temp_c']; ?>°C</span>
                                    </div>
                                    <div class="progress mb-2" style="height: 12px;">
                                        <?php
                                        $tempWidth = 0;
                                        $tempClass = 'bg-success';
                                        $temp = $weatherData['current']['temp_c'];
                                        
                                        if ($temp >= 28 && $temp <= 32) {
                                            $tempWidth = 100;
                                            $tempClass = 'bg-danger';
                                        } elseif ($temp >= 26 && $temp < 28 || $temp > 32 && $temp <= 34) {
                                            $tempWidth = 75;
                                            $tempClass = 'bg-warning';
                                        } elseif ($temp >= 24 && $temp < 26 || $temp > 34 && $temp <= 36) {
                                            $tempWidth = 50;
                                            $tempClass = 'bg-info';
                                        } elseif ($temp >= 20 && $temp < 24 || $temp > 36 && $temp <= 40) {
                                            $tempWidth = 25;
                                            $tempClass = 'bg-success';
                                        }
                                        ?>
                                        <div class="progress-bar <?php echo $tempClass; ?>" role="progressbar" 
                                             style="width: <?php echo $tempWidth; ?>%" 
                                             aria-valuenow="<?php echo $tempWidth; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <div class="d-flex justify-content-between small">
                                        <span>Low Risk</span>
                                        <span>High Risk</span>
                                    </div>
                                </div>
                                
                                <div class="list-group list-group-flush small">
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-2 border-0">
                                        <span><i class="fas fa-circle text-danger me-1" style="font-size: 0.6rem;"></i> 28-32°C:</span>
                                        <span class="text-danger fw-bold">Optimal (high risk)</span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-2 border-0">
                                        <span><i class="fas fa-circle text-warning me-1" style="font-size: 0.6rem;"></i> 26-28°C & 32-34°C:</span>
                                        <span class="text-warning fw-bold">Favorable</span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-2 border-0">
                                        <span><i class="fas fa-circle text-success me-1" style="font-size: 0.6rem;"></i> &lt;20°C & &gt;40°C:</span>
                                        <span class="text-success fw-bold">Unfavorable</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="stat-icon bg-primary text-white me-3" style="width: 50px; height: 50px;">
                                        <i class="fas fa-tint"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-0">Humidity</h5>
                                        <p class="text-muted mb-0 small">Affects mosquito survival</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-light border mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span>Current:</span>
                                        <span class="badge bg-primary px-3 py-2"><?php echo $weatherData['current']['humidity']; ?>%</span>
                                    </div>
                                    <div class="progress mb-2" style="height: 12px;">
                                        <?php
                                        $humidityWidth = 0;
                                        $humidityClass = 'bg-success';
                                        $humidity = $weatherData['current']['humidity'];
                                        
                                        if ($humidity >= 80) {
                                            $humidityWidth = 100;
                                            $humidityClass = 'bg-danger';
                                        } elseif ($humidity >= 70 && $humidity < 80) {
                                            $humidityWidth = 75;
                                            $humidityClass = 'bg-warning';
                                        } elseif ($humidity >= 60 && $humidity < 70) {
                                            $humidityWidth = 50;
                                            $humidityClass = 'bg-info';
                                        } elseif ($humidity >= 50 && $humidity < 60) {
                                            $humidityWidth = 25;
                                            $humidityClass = 'bg-success';
                                        }
                                        ?>
                                        <div class="progress-bar <?php echo $humidityClass; ?>" role="progressbar" 
                                             style="width: <?php echo $humidityWidth; ?>%" 
                                             aria-valuenow="<?php echo $humidityWidth; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <div class="d-flex justify-content-between small">
                                        <span>Low Risk</span>
                                        <span>High Risk</span>
                                    </div>
                                </div>
                                
                                <div class="list-group list-group-flush small">
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-2 border-0">
                                        <span><i class="fas fa-circle text-danger me-1" style="font-size: 0.6rem;"></i> 80%+:</span>
                                        <span class="text-danger fw-bold">Ideal (high risk)</span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-2 border-0">
                                        <span><i class="fas fa-circle text-warning me-1" style="font-size: 0.6rem;"></i> 70-80%:</span>
                                        <span class="text-warning fw-bold">Favorable</span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-2 border-0">
                                        <span><i class="fas fa-circle text-success me-1" style="font-size: 0.6rem;"></i> &lt;50%:</span>
                                        <span class="text-success fw-bold">Unfavorable</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="stat-icon bg-secondary text-white me-3" style="width: 50px; height: 50px;">
                                        <i class="fas fa-cloud-rain"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-0">Rainfall</h5>
                                        <p class="text-muted mb-0 small">Creates breeding sites</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-light border mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span>Current:</span>
                                        <span class="badge bg-primary px-3 py-2"><?php echo $weatherData['current']['precip_mm']; ?> mm</span>
                                    </div>
                                    <div class="progress mb-2" style="height: 12px;">
                                        <?php
                                        $rainfallWidth = 0;
                                        $rainfallClass = 'bg-success';
                                        $rainfall = $weatherData['current']['precip_mm'];
                                        
                                        if ($rainfall > 10) {
                                            $rainfallWidth = 100;
                                            $rainfallClass = 'bg-danger';
                                        } elseif ($rainfall > 5 && $rainfall <= 10) {
                                            $rainfallWidth = 75;
                                            $rainfallClass = 'bg-warning';
                                        } elseif ($rainfall > 2 && $rainfall <= 5) {
                                            $rainfallWidth = 50;
                                            $rainfallClass = 'bg-info';
                                        } elseif ($rainfall > 0 && $rainfall <= 2) {
                                            $rainfallWidth = 25;
                                            $rainfallClass = 'bg-success';
                                        }
                                        ?>
                                        <div class="progress-bar <?php echo $rainfallClass; ?>" role="progressbar" 
                                             style="width: <?php echo $rainfallWidth; ?>%" 
                                             aria-valuenow="<?php echo $rainfallWidth; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <div class="d-flex justify-content-between small">
                                        <span>Low Risk</span>
                                        <span>High Risk</span>
                                    </div>
                                </div>
                                
                                <div class="list-group list-group-flush small">
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-2 border-0">
                                        <span><i class="fas fa-circle text-danger me-1" style="font-size: 0.6rem;"></i> &gt;10mm:</span>
                                        <span class="text-danger fw-bold">High risk</span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-2 border-0">
                                        <span><i class="fas fa-circle text-warning me-1" style="font-size: 0.6rem;"></i> 5-10mm:</span>
                                        <span class="text-warning fw-bold">Moderate risk</span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-2 border-0">
                                        <span><i class="fas fa-circle text-success me-1" style="font-size: 0.6rem;"></i> 0mm:</span>
                                        <span class="text-success fw-bold">Low risk</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info mt-4 d-flex shadow-sm">
                    <div class="me-3">
                        <i class="fas fa-info-circle fa-2x"></i>
                    </div>
                    <div>
                        <h5 class="alert-heading">Did you know?</h5>
                        <p class="mb-0">The combination of warm temperatures (28-32°C), high humidity (>70%), and recent rainfall creates the perfect breeding conditions for Aedes aegypti mosquitoes. These conditions increase both mosquito population and the efficiency of virus transmission.</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Debug Information -->
        <div class="analytics-card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-light text-dark me-2" style="width: 40px; height: 40px;">
                        <i class="fas fa-code"></i>
                    </div>
                    <h5 class="mb-0">API Response Data (Debug)</h5>
                </div>
                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#debugData" aria-expanded="false" aria-controls="debugData">
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>
            <div class="collapse" id="debugData">
                <div class="card-body p-0">
                    <pre class="bg-light p-3 m-0" style="max-height: 300px; overflow-y: auto; border-radius: 0;"><?php echo json_encode($weatherData, JSON_PRETTY_PRINT); ?></pre>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Page loaded, creating chart...');
    
    // Auto-submit form when barangay changes
    document.getElementById('barangaySelect').addEventListener('change', function() {
        console.log('Barangay changed to:', this.value);
        this.form.submit();
    });
    
    // Create the Risk Factors Chart
    const canvas = document.getElementById('riskFactorsChart');
    if (!canvas) {
        console.error('Canvas not found!');
        return;
    }
    
    // Check if we have weather data
    <?php if ($weatherData['success'] && !empty($dateLabels)): ?>
    console.log('Creating chart with real weather data...');
    console.log('Labels:', <?php echo json_encode($dateLabels); ?>);
    console.log('Temperature data:', <?php echo json_encode($temperatureData); ?>);
    console.log('Humidity data:', <?php echo json_encode($humidityData); ?>);
    console.log('Rainfall data:', <?php echo json_encode($rainfallData); ?>);
    console.log('Risk scores:', <?php echo json_encode($riskScores); ?>);
    
    const ctx = canvas.getContext('2d');
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($dateLabels); ?>,
            datasets: [{
                label: 'Temperature (°C)',
                data: <?php echo json_encode($temperatureData); ?>,
                borderColor: 'rgb(220, 53, 69)',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                tension: 0.3
            }, {
                label: 'Humidity (%)',
                data: <?php echo json_encode($humidityData); ?>,
                borderColor: 'rgb(13, 110, 253)',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                tension: 0.3
            }, {
                label: 'Rainfall (mm)',
                data: <?php echo json_encode($rainfallData); ?>,
                borderColor: 'rgb(108, 117, 125)',
                backgroundColor: 'rgba(108, 117, 125, 0.1)',
                tension: 0.3,
                yAxisID: 'y1'
            }, {
                label: 'Risk Score',
                data: <?php echo json_encode($riskScores); ?>,
                borderColor: 'rgb(255, 193, 7)',
                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                tension: 0.3,
                yAxisID: 'y2'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Temperature (°C) / Humidity (%)'
                    }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Rainfall (mm)'
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                },
                y2: {
                    type: 'linear',
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Risk Score (0-100)'
                    },
                    min: 0,
                    max: 100,
                    grid: {
                        drawOnChartArea: false,
                    },
                    offset: true
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Weather Risk Factors for Dengue'
                }
            }
        }
    });
    
    // Toggle between line and bar chart
    document.querySelectorAll('[data-chart]').forEach(button => {
        button.addEventListener('click', function() {
            const chartType = this.getAttribute('data-chart');
            chart.config.type = chartType;
            chart.update();
            
            // Update active button
            document.querySelectorAll('[data-chart]').forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
        });
    });
    
    console.log('Chart created successfully with real data!');
    
    <?php else: ?>
    console.log('No weather data available, showing placeholder...');
    
    // Show placeholder message in chart area
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#6c757d';
    ctx.font = '16px Arial';
    ctx.textAlign = 'center';
    ctx.fillText('No weather data available', canvas.width / 2, canvas.height / 2);
    
    <?php endif; ?>
});
</script>

<script>
// Initialize tooltip
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
});
</script></body>
</html>
