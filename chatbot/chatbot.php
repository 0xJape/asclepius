<?php
// gemini_chatbot.php
// Gemini API chatbot with database integration for dengue monitoring
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/env.php';

$apiKey = env('GEMINI_API_KEY');
$geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-05-20:generateContent';

// Database functions for chatbot
function getDengueStatistics() {
    $db = getDBConnection();
    try {
        // Get total cases
        $query = "SELECT COUNT(*) as total_cases FROM patient_cases";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $totalCases = $stmt->fetch(PDO::FETCH_ASSOC)['total_cases'];
        
        // Get total patients
        $query = "SELECT COUNT(*) as total_patients FROM patients";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $totalPatients = $stmt->fetch(PDO::FETCH_ASSOC)['total_patients'];
        
        // Get cases by status (including available statuses)
        $query = "SELECT status, COUNT(*) as count FROM patient_cases GROUP BY status";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add common missing statuses with 0 count if not present
        $allStatuses = ['Active', 'Recovered', 'Critical', 'Severe', 'Moderate', 'Mild', 'Deceased'];
        $existingStatuses = array_column($statusData, 'status');
        foreach ($allStatuses as $status) {
            if (!in_array($status, $existingStatuses)) {
                $statusData[] = ['status' => $status, 'count' => 0];
            }
        }
        
        // Get ALL barangays with case counts (including zero cases)
        $query = "SELECT b.name as barangay, 
                  COALESCE(COUNT(pc.case_id), 0) as case_count,
                  b.barangay_id
                  FROM barangays b 
                  LEFT JOIN patients p ON b.barangay_id = p.barangay_id 
                  LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id 
                  GROUP BY b.barangay_id, b.name 
                  ORDER BY case_count DESC, b.name ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $barangayData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent cases (last 7 days)
        $query = "SELECT COUNT(*) as recent_cases FROM patient_cases 
                  WHERE date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $recentCases = $stmt->fetch(PDO::FETCH_ASSOC)['recent_cases'];
        
        return [
            'total_cases' => $totalCases,
            'total_patients' => $totalPatients,
            'status_breakdown' => $statusData,
            'barangay_breakdown' => $barangayData,
            'recent_cases' => $recentCases
        ];
        
    } catch(PDOException $e) {
        error_log("Database error in chatbot: " . $e->getMessage());
        return null;
    }
}

function getWeeklyTrend() {
    $db = getDBConnection();
    try {
        // Current week cases
        $query = "SELECT COUNT(*) as current_week FROM patient_cases 
                  WHERE date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $currentWeek = $stmt->fetch(PDO::FETCH_ASSOC)['current_week'];
        
        // Previous week cases
        $query = "SELECT COUNT(*) as previous_week FROM patient_cases 
                  WHERE date_reported BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) 
                  AND DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $previousWeek = $stmt->fetch(PDO::FETCH_ASSOC)['previous_week'];
        
        $trend = $previousWeek > 0 ? 
            round((($currentWeek - $previousWeek) / $previousWeek) * 100, 1) : 0;
        
        return [
            'current_week' => $currentWeek,
            'previous_week' => $previousWeek,
            'trend_percentage' => $trend
        ];
        
    } catch(PDOException $e) {
        error_log("Database error in trend calculation: " . $e->getMessage());
        return null;
    }
}

function getHighRiskAreas() {
    $db = getDBConnection();
    try {
        $query = "SELECT b.name as barangay, 
                  COALESCE(COUNT(pc.case_id), 0) as case_count,
                  COALESCE(COUNT(CASE WHEN pc.status IN ('Critical', 'Severe') THEN 1 END), 0) as severe_cases,
                  COALESCE(COUNT(CASE WHEN pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END), 0) as recent_cases
                  FROM barangays b 
                  LEFT JOIN patients p ON b.barangay_id = p.barangay_id 
                  LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id 
                  GROUP BY b.barangay_id, b.name 
                  ORDER BY case_count DESC, severe_cases DESC, b.name ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Database error in high risk areas: " . $e->getMessage());
        return [];
    }
}

function getBarangay2025Population() {
    $db = getDBConnection();
    try {
        $query = "SELECT 
                    name as barangay,
                    population,
                    temperature,
                    humidity,
                    forecast_cases,
                    ROUND((forecast_cases / population) * 1000, 2) as cases_per_1000
                  FROM barangays 
                  WHERE population IS NOT NULL AND temperature IS NOT NULL
                  ORDER BY population DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $populationData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $totalPopulation = 0;
        $totalForecastCases = 0;
        
        foreach ($populationData as $data) {
            $totalPopulation += $data['population'];
            $totalForecastCases += $data['forecast_cases'];
        }
        
        return [
            'barangay_data' => $populationData,
            'summary' => [
                'total_population_2025' => round($totalPopulation),
                'total_forecast_cases_2025' => round($totalForecastCases, 2),
                'average_cases_per_1000' => round(($totalForecastCases / $totalPopulation) * 1000, 2),
                'total_barangays' => count($populationData),
                'highest_risk' => !empty($populationData) ? max(array_column($populationData, 'cases_per_1000')) : 0,
                'lowest_risk' => !empty($populationData) ? min(array_column($populationData, 'cases_per_1000')) : 0
            ]
        ];
        
    } catch(PDOException $e) {
        error_log("Database error in 2025 population data: " . $e->getMessage());
        return [
            'barangay_data' => [],
            'summary' => [
                'total_population_2025' => 0,
                'total_forecast_cases_2025' => 0,
                'average_cases_per_1000' => 0,
                'total_barangays' => 0,
                'highest_risk' => 0,
                'lowest_risk' => 0
            ]
        ];
    }
}

function getAllBarangayStatus() {
    $db = getDBConnection();
    try {
        $query = "SELECT 
                  b.name as barangay,
                  b.barangay_id,
                  COALESCE(COUNT(pc.case_id), 0) as total_cases,
                  COALESCE(COUNT(CASE WHEN pc.status = 'Active' THEN 1 END), 0) as active_cases,
                  COALESCE(COUNT(CASE WHEN pc.status = 'Recovered' THEN 1 END), 0) as recovered_cases,
                  COALESCE(COUNT(CASE WHEN pc.status IN ('Critical', 'Severe') THEN 1 END), 0) as severe_cases,
                  COALESCE(COUNT(CASE WHEN pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END), 0) as recent_cases,
                  CASE 
                    WHEN COUNT(pc.case_id) = 0 THEN 'No Cases'
                    WHEN COUNT(CASE WHEN pc.status IN ('Critical', 'Severe') THEN 1 END) > 0 THEN 'High Risk'
                    WHEN COUNT(CASE WHEN pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) > 2 THEN 'Moderate Risk'
                    WHEN COUNT(pc.case_id) > 0 THEN 'Low Risk'
                    ELSE 'Safe'
                  END as risk_level
                  FROM barangays b 
                  LEFT JOIN patients p ON b.barangay_id = p.barangay_id 
                  LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id 
                  GROUP BY b.barangay_id, b.name 
                  ORDER BY total_cases DESC, b.name ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Database error in barangay status: " . $e->getMessage());
        return [];
    }
}

function getPatientDetails() {
    $db = getDBConnection();
    try {
        $query = "SELECT 
                  p.patient_id,
                  CONCAT(p.first_name, ' ', p.last_name) as full_name,
                  p.gender,
                  TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
                  b.name as barangay,
                  p.contact_number,
                  pc.status as current_status,
                  pc.date_reported as last_case_date,
                  COUNT(pc2.case_id) as total_cases
                  FROM patients p
                  LEFT JOIN barangays b ON p.barangay_id = b.barangay_id
                  LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id AND pc.date_reported = (
                      SELECT MAX(date_reported) 
                      FROM patient_cases pc_inner 
                      WHERE pc_inner.patient_id = p.patient_id
                  )
                  LEFT JOIN patient_cases pc2 ON p.patient_id = pc2.patient_id
                  GROUP BY p.patient_id, p.first_name, p.last_name, p.gender, p.date_of_birth, 
                           b.name, p.contact_number, pc.status, pc.date_reported
                  ORDER BY pc.date_reported DESC, p.last_name ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Database error in patient details: " . $e->getMessage());
        return [];
    }
}

function getPredictionData() {
    $db = getDBConnection();
    try {
        // Check if prediction tables exist
        $query = "SHOW TABLES LIKE 'dengue_predictions'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $query = "SELECT 
                      prediction_date,
                      predicted_cases,
                      confidence_level,
                      risk_level,
                      weather_factor,
                      created_at
                      FROM dengue_predictions 
                      WHERE prediction_date >= CURDATE()
                      ORDER BY prediction_date ASC 
                      LIMIT 14";
            $stmt = $db->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return [];
        
    } catch(PDOException $e) {
        error_log("Database error in prediction data: " . $e->getMessage());
        return [];
    }
}

function getWeatherData() {
    // Use Open-Meteo API configuration
    define('DEFAULT_LATITUDE', 6.2167); // Tupi, South Cotabato coordinates
    define('DEFAULT_LONGITUDE', 124.9500);
    
    try {
        // Get current weather and 16-day forecast from Open-Meteo (maximum available)
        $url = "https://api.open-meteo.com/v1/forecast?" .
            "latitude=" . DEFAULT_LATITUDE . "&longitude=" . DEFAULT_LONGITUDE .
            "&hourly=temperature_2m,relative_humidity_2m,precipitation,weather_code" .
            "&daily=temperature_2m_max,temperature_2m_min,precipitation_sum,weather_code" .
            "&forecast_days=16" .
            "&timezone=Asia%2FManila";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            curl_close($ch);
            return [];
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return [];
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['hourly'])) {
            return [];
        }
        
        // Format weather data for chatbot context
        $weatherData = [];
        
        // Get current hour and weather
        $current_hour = date('G');
        $current = [
            'temp_c' => $data['hourly']['temperature_2m'][$current_hour] ?? 28,
            'humidity' => $data['hourly']['relative_humidity_2m'][$current_hour] ?? 70,
            'precip_mm' => $data['hourly']['precipitation'][$current_hour] ?? 0,
            'condition' => ['text' => getWeatherConditionText($data['hourly']['weather_code'][$current_hour] ?? 1)]
        ];
        
        // Add current weather
        $weatherData[] = [
            'date' => date('Y-m-d'),
            'temperature' => $current['temp_c'],
            'humidity' => $current['humidity'],
            'rainfall' => $current['precip_mm'],
            'weather_condition' => $current['condition']['text'],
            'type' => 'current'
        ];
        
        // Add forecast data from daily data
        if (isset($data['daily']) && isset($data['daily']['time'])) {
            for ($i = 0; $i < count($data['daily']['time']); $i++) {
                $weatherData[] = [
                    'date' => $data['daily']['time'][$i],
                    'temperature' => ($data['daily']['temperature_2m_max'][$i] + $data['daily']['temperature_2m_min'][$i]) / 2,
                    'humidity' => $current['humidity'], // Open-Meteo doesn't provide daily avg humidity
                    'rainfall' => $data['daily']['precipitation_sum'][$i],
                    'weather_condition' => getWeatherConditionText($data['daily']['weather_code'][$i]),
                    'type' => 'forecast'
                ];
            }
        }
        
        return $weatherData;
        
    } catch(Exception $e) {
        error_log("Weather API error in chatbot: " . $e->getMessage());
        return [];
    }
}

// Helper function for weather condition text
function getWeatherConditionText($code) {
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


function getBarangayOfficials() {
    $db = getDBConnection();
    try {
        $query = "SELECT 
                  bo.official_id,
                  bo.name,
                  bo.position,
                  bo.email,
                  bo.phone,
                  bo.is_primary,
                  b.name as barangay_name,
                  b.barangay_id
                  FROM barangay_officials bo
                  JOIN barangays b ON bo.barangay_id = b.barangay_id
                  ORDER BY b.name, bo.is_primary DESC, bo.position";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Database error in barangay officials: " . $e->getMessage());
        return [];
    }
}

function getHistoricalWeeklyData($weeks = 12) {
    $db = getDBConnection();
    try {
        $query = "
            SELECT 
                DATE_FORMAT(date_reported, '%Y-%U') as week,
                YEAR(date_reported) as year,
                WEEK(date_reported) as week_num,
                COUNT(*) as weekly_cases,
                DATE_SUB(DATE(date_reported), INTERVAL WEEKDAY(date_reported) DAY) as week_start,
                DATE_ADD(DATE_SUB(DATE(date_reported), INTERVAL WEEKDAY(date_reported) DAY), INTERVAL 6 DAY) as week_end
            FROM patient_cases 
            WHERE date_reported >= DATE_SUB(CURDATE(), INTERVAL {$weeks} WEEK)
            GROUP BY YEAR(date_reported), WEEK(date_reported)
            ORDER BY year DESC, week_num DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $weeklyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get barangay-specific historical data
        $barangayQuery = "
            SELECT 
                b.name as barangay,
                DATE_FORMAT(pc.date_reported, '%Y-%U') as week,
                COUNT(pc.case_id) as cases,
                YEAR(pc.date_reported) as year,
                WEEK(pc.date_reported) as week_num
            FROM barangays b
            LEFT JOIN patients p ON b.barangay_id = p.barangay_id
            LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id
            WHERE pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL {$weeks} WEEK)
            GROUP BY b.barangay_id, b.name, YEAR(pc.date_reported), WEEK(pc.date_reported)
            ORDER BY b.name, year DESC, week_num DESC
        ";
        $stmt = $db->prepare($barangayQuery);
        $stmt->execute();
        $barangayWeeklyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'overall_weekly' => $weeklyData,
            'barangay_weekly' => $barangayWeeklyData,
            'weeks_covered' => $weeks
        ];
        
    } catch(PDOException $e) {
        error_log("Database error in historical data: " . $e->getMessage());
        return [];
    }
}

// Function to get historical dengue data (2014-2024) for regression analysis
function getHistoricalDengueData($barangay = null, $startYear = null, $endYear = null) {
    $db = getDBConnection();
    
    try {
        $sql = "SELECT barangay, year, population, temperature, humidity, dengue_cases, cases_per_1000
                FROM historical_dengue_data WHERE 1=1";
        $params = [];
        
        if ($barangay) {
            $sql .= " AND barangay = ?";
            $params[] = $barangay;
        }
        
        if ($startYear) {
            $sql .= " AND year >= ?";
            $params[] = $startYear;
        }
        
        if ($endYear) {
            $sql .= " AND year <= ?";
            $params[] = $endYear;
        }
        
        $sql .= " ORDER BY barangay, year";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Database error in historical dengue data: " . $e->getMessage());
        return [];
    }
}

// Function to get regression analysis data
function getRegressionAnalysis() {
    $db = getDBConnection();
    
    try {
        // Overall statistics for regression model
        $overallQuery = "SELECT 
            COUNT(*) as total_records,
            SUM(dengue_cases) as total_historical_cases,
            AVG(dengue_cases) as avg_cases,
            STDDEV(dengue_cases) as cases_stddev,
            MAX(dengue_cases) as max_cases,
            MIN(dengue_cases) as min_cases,
            AVG(temperature) as avg_temp,
            STDDEV(temperature) as temp_stddev,
            AVG(humidity) as avg_humidity,
            STDDEV(humidity) as humidity_stddev,
            AVG(population) as avg_population
            FROM historical_dengue_data";
        
        $stmt = $db->prepare($overallQuery);
        $stmt->execute();
        $overall = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Year-wise trends for time series analysis
        $yearlyQuery = "SELECT 
            year,
            SUM(dengue_cases) as total_cases,
            AVG(dengue_cases) as avg_cases_per_barangay,
            COUNT(*) as barangay_count,
            AVG(temperature) as avg_temp,
            AVG(humidity) as avg_humidity,
            AVG(population) as avg_population,
            MAX(dengue_cases) as peak_cases_single_barangay
            FROM historical_dengue_data
            GROUP BY year
            ORDER BY year";
        
        $stmt = $db->prepare($yearlyQuery);
        $stmt->execute();
        $yearly = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Peak outbreak years identification
        $peakQuery = "SELECT 
            year,
            barangay,
            dengue_cases,
            temperature,
            humidity,
            population,
            cases_per_1000
            FROM historical_dengue_data
            ORDER BY dengue_cases DESC
            LIMIT 10";
        
        $stmt = $db->prepare($peakQuery);
        $stmt->execute();
        $peak_outbreaks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Climate correlation patterns
        $climateQuery = "SELECT 
            ROUND(temperature, 1) as temp_range,
            ROUND(humidity, 0) as humidity_range,
            AVG(dengue_cases) as avg_cases,
            SUM(dengue_cases) as total_cases,
            COUNT(*) as records,
            AVG(cases_per_1000) as avg_incidence
            FROM historical_dengue_data
            GROUP BY ROUND(temperature, 1), ROUND(humidity, 0)
            HAVING records >= 3
            ORDER BY avg_cases DESC
            LIMIT 15";
        
        $stmt = $db->prepare($climateQuery);
        $stmt->execute();
        $climate_patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'summary' => $overall,
            'yearly_trends' => $yearly,
            'peak_outbreaks' => $peak_outbreaks,
            'climate_patterns' => $climate_patterns,
            'data_period' => '2014-2024',
            'total_barangays' => 15
        ];
    } catch (Exception $e) {
        error_log("Database error in regression analysis: " . $e->getMessage());
        return [];
    }
}

// Function to get historical predictions data (2024-2050) for extended analysis
function getHistoricalPredictionsData($barangay = null, $startYear = null, $endYear = null) {
    $db = getDBConnection();
    
    try {
        $sql = "SELECT barangay_name, year, population, temperature, humidity, forecasted_cases
                FROM historical_predictions WHERE 1=1";
        $params = [];
        
        if ($barangay) {
            $sql .= " AND barangay_name = ?";
            $params[] = $barangay;
        }
        
        if ($startYear) {
            $sql .= " AND year >= ?";
            $params[] = $startYear;
        }
        
        if ($endYear) {
            $sql .= " AND year <= ?";
            $params[] = $endYear;
        }
        
        $sql .= " ORDER BY barangay_name, year";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Database error in historical predictions data: " . $e->getMessage());
        return [];
    }
}

// Function to get extended analysis summary for 2024-2050 data
function getExtendedAnalysisSummary() {
    $db = getDBConnection();
    
    try {
        // Overall statistics for extended period
        $overallQuery = "SELECT 
            COUNT(*) as total_records,
            COUNT(DISTINCT barangay_name) as total_barangays,
            COUNT(DISTINCT year) as total_years,
            MIN(year) as start_year,
            MAX(year) as end_year,
            SUM(population) as total_population,
            SUM(forecasted_cases) as total_forecasted_cases,
            AVG(forecasted_cases) as avg_forecasted_cases,
            AVG(temperature) as avg_temperature,
            AVG(humidity) as avg_humidity,
            MAX(forecasted_cases) as max_forecasted_cases,
            MIN(forecasted_cases) as min_forecasted_cases
            FROM historical_predictions";
        
        $stmt = $db->prepare($overallQuery);
        $stmt->execute();
        $overall = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Yearly trends for the extended period
        $yearlyQuery = "SELECT 
            year,
            SUM(population) as total_population,
            SUM(forecasted_cases) as total_forecasted_cases,
            AVG(temperature) as avg_temperature,
            AVG(humidity) as avg_humidity,
            COUNT(DISTINCT barangay_name) as barangays_count
            FROM historical_predictions
            GROUP BY year
            ORDER BY year";
        
        $stmt = $db->prepare($yearlyQuery);
        $stmt->execute();
        $yearly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Barangay-wise projections
        $barangayQuery = "SELECT 
            barangay_name,
            COUNT(*) as years_covered,
            SUM(forecasted_cases) as total_forecasted_cases,
            AVG(forecasted_cases) as avg_annual_cases,
            AVG(population) as avg_population,
            AVG(temperature) as avg_temperature,
            AVG(humidity) as avg_humidity,
            MAX(forecasted_cases) as peak_forecasted_cases,
            MIN(forecasted_cases) as min_forecasted_cases
            FROM historical_predictions
            GROUP BY barangay_name
            ORDER BY total_forecasted_cases DESC";
        
        $stmt = $db->prepare($barangayQuery);
        $stmt->execute();
        $barangay_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'summary' => $overall,
            'yearly_trends' => $yearly_trends,
            'barangay_analysis' => $barangay_analysis,
            'data_period' => '2024-2050',
            'total_projection_years' => 27
        ];
    } catch (Exception $e) {
        error_log("Database error in extended analysis: " . $e->getMessage());
        return [];
    }
}

// ASCLEPIUS Mathematical Model (Multi-Linear Regression)
function calculateDenguePrediction($population, $temperature, $humidity) {
    /**
     * ASCLEPIUS MLR Formula:
     * y = -72.612471 + 0.00905443P + 2.447256T - 0.0778633H
     * 
     * Where:
     * y = Predicted Number of Possible Dengue Cases
     * P = Population per Barangay
     * T = Average Temperature (°C)
     * H = Average Humidity (%)
     */
    
    $predictedCases = -72.612471 + (0.00905443 * $population) + (2.447256 * $temperature) - (0.0778633 * $humidity);
    
    // Ensure non-negative prediction (can't have negative cases)
    $predictedCases = max(0, round($predictedCases, 2));
    
    return [
        'predicted_cases' => $predictedCases,
        'formula_used' => 'ASCLEPIUS MLR: y = -72.612471 + 0.00905443P + 2.447256T - 0.0778633H',
        'input_parameters' => [
            'population' => $population,
            'temperature' => $temperature,
            'humidity' => $humidity
        ],
        'interpretation' => [
            'population_effect' => round(0.00905443 * $population, 2),
            'temperature_effect' => round(2.447256 * $temperature, 2),
            'humidity_effect' => round(-0.0778633 * $humidity, 2),
            'base_constant' => -72.612471
        ]
    ];
}

// Apply ASCLEPIUS model to all current barangays
function predictAllBarangaysDengue($currentWeatherData = null) {
    $db = getDBConnection();
    
    try {
        // Get all barangays with their current population estimates
        $query = "SELECT 
                    b.name as barangay_name,
                    b.barangay_id,
                    COUNT(DISTINCT p.patient_id) as current_patients,
                    AVG(CASE 
                        WHEN hd.population IS NOT NULL THEN hd.population 
                        ELSE 5000 
                    END) as estimated_population
                  FROM barangays b
                  LEFT JOIN patients p ON b.barangay_id = p.barangay_id
                  LEFT JOIN historical_dengue_data hd ON b.name = hd.barangay 
                    AND hd.year = (SELECT MAX(year) FROM historical_dengue_data WHERE barangay = b.name)
                  GROUP BY b.barangay_id, b.name
                  ORDER BY b.name";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get current weather or use defaults
        $weather = $currentWeatherData ?: getWeatherData();
        $currentTemp = $weather['current']['temp_c'] ?? 25.0;
        $currentHumidity = $weather['current']['humidity'] ?? 83.0;
        
        $predictions = [];
        foreach ($barangays as $barangay) {
            $population = round($barangay['estimated_population']);
            
            // Calculate prediction using ASCLEPIUS model
            $prediction = calculateDenguePrediction($population, $currentTemp, $currentHumidity);
            
            // Add barangay context
            $prediction['barangay'] = $barangay['barangay_name'];
            $prediction['current_patients'] = $barangay['current_patients'];
            
            // Risk classification based on predicted cases
            if ($prediction['predicted_cases'] >= 50) {
                $prediction['risk_level'] = 'VERY HIGH';
            } elseif ($prediction['predicted_cases'] >= 30) {
                $prediction['risk_level'] = 'HIGH';
            } elseif ($prediction['predicted_cases'] >= 15) {
                $prediction['risk_level'] = 'MODERATE';
            } elseif ($prediction['predicted_cases'] >= 5) {
                $prediction['risk_level'] = 'LOW';
            } else {
                $prediction['risk_level'] = 'MINIMAL';
            }
            
            $predictions[] = $prediction;
        }
        
        // Sort by predicted cases (highest first)
        usort($predictions, function($a, $b) {
            return $b['predicted_cases'] <=> $a['predicted_cases'];
        });
        
        return [
            'predictions' => $predictions,
            'model_info' => [
                'name' => 'ASCLEPIUS Mathematical Model',
                'type' => 'Multi-Linear Regression (MLR)',
                'formula' => 'y = -72.612471 + 0.00905443P + 2.447256T - 0.0778633H',
                'weather_conditions' => [
                    'temperature' => $currentTemp . '°C',
                    'humidity' => $currentHumidity . '%'
                ],
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Database error in ASCLEPIUS predictions: " . $e->getMessage());
        return [];
    }
}

// Validate ASCLEPIUS model against historical data
function validateASCLEPIUSModel() {
    $db = getDBConnection();
    
    try {
        // Get historical data for validation
        $query = "SELECT barangay, year, population, temperature, humidity, dengue_cases 
                  FROM historical_dengue_data 
                  ORDER BY barangay, year";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $historicalData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $validationResults = [];
        $totalError = 0;
        $totalAbsError = 0;
        $count = 0;
        
        foreach ($historicalData as $record) {
            $prediction = calculateDenguePrediction(
                $record['population'], 
                $record['temperature'], 
                $record['humidity']
            );
            
            $actualCases = $record['dengue_cases'];
            $predictedCases = $prediction['predicted_cases'];
            $error = $predictedCases - $actualCases;
            $absError = abs($error);
            $percentError = $actualCases > 0 ? ($error / $actualCases) * 100 : 0;
            
            $validationResults[] = [
                'barangay' => $record['barangay'],
                'year' => $record['year'],
                'actual_cases' => $actualCases,
                'predicted_cases' => $predictedCases,
                'error' => round($error, 2),
                'absolute_error' => round($absError, 2),
                'percent_error' => round($percentError, 2)
            ];
            
            $totalError += $error;
            $totalAbsError += $absError;
            $count++;
        }
        
        $meanError = $totalError / $count;
        $meanAbsError = $totalAbsError / $count;
        
        // Calculate R-squared and other metrics
        $actualValues = array_column($historicalData, 'dengue_cases');
        $predictedValues = array_column($validationResults, 'predicted_cases');
        
        $actualMean = array_sum($actualValues) / count($actualValues);
        $ssTotal = array_sum(array_map(function($val) use ($actualMean) { 
            return pow($val - $actualMean, 2); 
        }, $actualValues));
        
        $ssResidual = array_sum(array_map(function($actual, $predicted) { 
            return pow($actual - $predicted, 2); 
        }, $actualValues, $predictedValues));
        
        $rSquared = 1 - ($ssResidual / $ssTotal);
        
        return [
            'model_performance' => [
                'mean_error' => round($meanError, 2),
                'mean_absolute_error' => round($meanAbsError, 2),
                'r_squared' => round($rSquared, 4),
                'total_predictions' => $count,
                'accuracy_percentage' => round((1 - ($meanAbsError / $actualMean)) * 100, 2)
            ],
            'validation_data' => array_slice($validationResults, 0, 20), // First 20 for display
            'model_formula' => 'y = -72.612471 + 0.00905443P + 2.447256T - 0.0778633H'
        ];
        
    } catch (Exception $e) {
        error_log("Database error in model validation: " . $e->getMessage());
        return [];
    }
}


function getGeminiResponse($userMessage) {
    global $geminiApiUrl, $apiKey;
    
    // Get real-time data from database
    $stats = getDengueStatistics();
    $trend = getWeeklyTrend();
    $highRiskAreas = getHighRiskAreas();
    $barangayStatus = getAllBarangayStatus();
    $patientDetails = getPatientDetails();
    $predictions = getPredictionData();
    $weatherData = getWeatherData();
    $barangayOfficials = getBarangayOfficials();
    $historicalData = getHistoricalWeeklyData(12);
    $population2025Data = getBarangay2025Population();
    $extendedAnalysis = getExtendedAnalysisSummary();
    
    // Prepare comprehensive data context for AI
    $dataContext = "";
    if ($stats) {
        $dataContext .= "CURRENT DENGUE DATA:\n";
        $dataContext .= "Total Cases: " . $stats['total_cases'] . "\n";
        $dataContext .= "Total Patients: " . $stats['total_patients'] . "\n";
        $dataContext .= "Recent Cases (Last 7 days): " . $stats['recent_cases'] . "\n\n";
        
        $dataContext .= "CASE STATUS BREAKDOWN:\n";
        foreach ($stats['status_breakdown'] as $status) {
            $dataContext .= "- " . $status['status'] . ": " . $status['count'] . " cases\n";
        }
        
        $dataContext .= "\nALL BARANGAYS COMPREHENSIVE STATUS:\n";
        foreach ($barangayStatus as $barangay) {
            $dataContext .= "- " . $barangay['barangay'] . ":\n";
            $dataContext .= "  Total: " . $barangay['total_cases'] . " cases\n";
            $dataContext .= "  Active: " . $barangay['active_cases'] . ", Recovered: " . $barangay['recovered_cases'] . "\n";
            $dataContext .= "  Critical/Severe: " . $barangay['severe_cases'] . ", Recent (7d): " . $barangay['recent_cases'] . "\n";
            $dataContext .= "  Risk Level: " . $barangay['risk_level'] . "\n";
        }
    }
    
    // Add patient details
    if (!empty($patientDetails)) {
        $dataContext .= "\nPATIENT DETAILS (Recent Cases):\n";
        foreach (array_slice($patientDetails, 0, 20) as $patient) { // Limit to 20 recent patients to avoid token limits
            $dataContext .= "- " . $patient['full_name'] . " (Age: " . $patient['age'] . ", " . $patient['gender'] . ")\n";
            $dataContext .= "  Barangay: " . $patient['barangay'] . ", Status: " . ($patient['current_status'] ?? 'Unknown') . "\n";
            $dataContext .= "  Last Case: " . ($patient['last_case_date'] ?? 'N/A') . ", Total Cases: " . $patient['total_cases'] . "\n";
        }
    }
    
    // Add prediction data
    if (!empty($predictions)) {
        $dataContext .= "\nPREDICTION DATA (Next 14 days):\n";
        foreach ($predictions as $pred) {
            $dataContext .= "- " . $pred['prediction_date'] . ": " . $pred['predicted_cases'] . " cases\n";
            $dataContext .= "  Risk Level: " . ($pred['risk_level'] ?? 'N/A') . ", Confidence: " . ($pred['confidence_level'] ?? 'N/A') . "%\n";
            if (isset($pred['weather_factor'])) {
                $dataContext .= "  Weather Factor: " . $pred['weather_factor'] . "\n";
            }
        }
    }
    
    // Add weather data
    if (!empty($weatherData)) {
        $dataContext .= "\nCURRENT & FORECAST WEATHER DATA:\n";
        foreach ($weatherData as $weather) {
            $type = ($weather['type'] == 'current') ? ' (Current)' : ' (Forecast)';
            $dataContext .= "- " . $weather['date'] . $type . ": " . $weather['temperature'] . "°C, ";
            $dataContext .= "Humidity: " . $weather['humidity'] . "%, ";
            $dataContext .= "Rainfall: " . $weather['rainfall'] . "mm\n";
            $dataContext .= "  Condition: " . $weather['weather_condition'] . "\n";
        }
        $dataContext .= "\nWeather data is live from Open-Meteo API for Tupi, South Cotabato\n";
    }
    
    // Add 2025 population and prediction data
    if (!empty($population2025Data) && !empty($population2025Data['barangay_data'])) {
        $dataContext .= "\n2025 BARANGAY POPULATION & ASCLEPIUS PREDICTIONS:\n";
        $dataContext .= "Updated Population Data with Environmental Factors:\n\n";
        
        foreach ($population2025Data['barangay_data'] as $barangay) {
            $dataContext .= "- " . $barangay['barangay'] . ":\n";
            $dataContext .= "  Population 2025: " . number_format($barangay['population'], 0) . " people\n";
            $dataContext .= "  Temperature: " . $barangay['temperature'] . "°C\n";
            $dataContext .= "  Humidity: " . $barangay['humidity'] . "%\n";
            $dataContext .= "  ASCLEPIUS Forecast: " . number_format($barangay['forecast_cases'], 1) . " cases\n";
            $dataContext .= "  Risk Rate: " . $barangay['cases_per_1000'] . " cases per 1,000 population\n";
        }
        
        if (!empty($population2025Data['summary'])) {
            $summary = $population2025Data['summary'];
            $dataContext .= "\n2025 TUPI MUNICIPALITY SUMMARY:\n";
            $dataContext .= "- Total Population: " . number_format($summary['total_population_2025']) . " people\n";
            $dataContext .= "- Total Forecasted Cases: " . number_format($summary['total_forecast_cases_2025'], 1) . " cases\n";
            $dataContext .= "- Average Risk Rate: " . $summary['average_cases_per_1000'] . " cases per 1,000 population\n";
            $dataContext .= "- Total Barangays: " . $summary['total_barangays'] . " barangays\n";
            $dataContext .= "- Highest Risk Rate: " . $summary['highest_risk'] . " cases per 1,000\n";
            $dataContext .= "- Lowest Risk Rate: " . $summary['lowest_risk'] . " cases per 1,000\n";
            $dataContext .= "\nNote: These predictions use the ASCLEPIUS Mathematical Model (MLR formula) with actual 2025 population data.\n";
        }
    }
    
    // Add barangay officials data
    if (!empty($barangayOfficials)) {
        $dataContext .= "\nBARANGAY OFFICIALS DIRECTORY:\n";
        $currentBarangay = '';
        foreach ($barangayOfficials as $official) {
            if ($currentBarangay !== $official['barangay_name']) {
                $currentBarangay = $official['barangay_name'];
                $dataContext .= "\n" . $currentBarangay . ":\n";
            }
            $dataContext .= "- " . $official['name'] . " (" . $official['position'] . ")";
            if ($official['is_primary']) {
                $dataContext .= " [Primary Contact]";
            }
            $dataContext .= "\n";
            if ($official['phone']) {
                $dataContext .= "  Phone: " . $official['phone'] . "\n";
            }
            if ($official['email']) {
                $dataContext .= "  Email: " . $official['email'] . "\n";
            }
        }
    }
    
    // Add historical weekly data for regression analysis
    if (!empty($historicalData)) {
        $dataContext .= "\nHISTORICAL WEEKLY DATA (Last 12 weeks):\n";
        
        // Overall weekly data
        if (!empty($historicalData['overall_weekly'])) {
            $dataContext .= "OVERALL WEEKLY CASES:\n";
            foreach (array_slice($historicalData['overall_weekly'], 0, 12) as $week) {
                $dataContext .= "- Week " . $week['week'] . " (" . $week['week_start'] . " to " . $week['week_end'] . "): " . $week['weekly_cases'] . " cases\n";
            }
        }
        
        // Barangay-specific historical data
        if (!empty($historicalData['barangay_weekly'])) {
            $dataContext .= "\nBARANGAY HISTORICAL DATA:\n";
            $currentBarangay = '';
            foreach ($historicalData['barangay_weekly'] as $data) {
                if ($currentBarangay !== $data['barangay']) {
                    $currentBarangay = $data['barangay'];
                    $dataContext .= "\n" . $currentBarangay . ":\n";
                }
                $dataContext .= "- Week " . $data['week'] . ": " . $data['cases'] . " cases\n";
            }
        }
        
        $dataContext .= "\nThis historical data can be used for regression analysis and trend prediction.\n";
    }
    
    // Add historical dengue data (2014-2024) for regression analysis
    $regressionAnalysis = getRegressionAnalysis();
    if (!empty($regressionAnalysis)) {
        $dataContext .= "\nHISTORICAL DENGUE DATA (2014-2024) FOR REGRESSION ANALYSIS:\n";
        $dataContext .= "Data Period: " . $regressionAnalysis['data_period'] . " (" . $regressionAnalysis['total_barangays'] . " barangays)\n\n";
        
        if (!empty($regressionAnalysis['summary'])) {
            $summary = $regressionAnalysis['summary'];
            $dataContext .= "STATISTICAL SUMMARY:\n";
            $dataContext .= "- Total Historical Records: " . $summary['total_records'] . "\n";
            $dataContext .= "- Total Historical Cases: " . number_format($summary['total_historical_cases']) . "\n";
            $dataContext .= "- Average Cases per Record: " . round($summary['avg_cases'], 2) . " (±" . round($summary['cases_stddev'], 2) . ")\n";
            $dataContext .= "- Peak Single Event: " . $summary['max_cases'] . " cases\n";
            $dataContext .= "- Average Temperature: " . round($summary['avg_temp'], 2) . "°C (±" . round($summary['temp_stddev'], 2) . ")\n";
            $dataContext .= "- Average Humidity: " . round($summary['avg_humidity'], 2) . "% (±" . round($summary['humidity_stddev'], 2) . ")\n\n";
        }
        
        if (!empty($regressionAnalysis['yearly_trends'])) {
            $dataContext .= "YEARLY OUTBREAK TRENDS:\n";
            foreach ($regressionAnalysis['yearly_trends'] as $year) {
                $dataContext .= "- " . $year['year'] . ": " . number_format($year['total_cases']) . " total cases";
                $dataContext .= " (avg " . round($year['avg_cases_per_barangay'], 1) . " per barangay)";
                $dataContext .= " [Peak: " . $year['peak_cases_single_barangay'] . "]\n";
            }
            $dataContext .= "\n";
        }
        
        if (!empty($regressionAnalysis['peak_outbreaks'])) {
            $dataContext .= "TOP HISTORICAL OUTBREAKS:\n";
            foreach (array_slice($regressionAnalysis['peak_outbreaks'], 0, 5) as $outbreak) {
                $dataContext .= "- " . $outbreak['barangay'] . " (" . $outbreak['year'] . "): " . $outbreak['dengue_cases'] . " cases";
                $dataContext .= " [" . $outbreak['temperature'] . "°C, " . $outbreak['humidity'] . "% humidity]\n";
            }
            $dataContext .= "\n";
        }
        
        if (!empty($regressionAnalysis['climate_patterns'])) {
            $dataContext .= "CLIMATE-DENGUE CORRELATION PATTERNS:\n";
            foreach (array_slice($regressionAnalysis['climate_patterns'], 0, 8) as $pattern) {
                $dataContext .= "- " . $pattern['temp_range'] . "°C + " . $pattern['humidity_range'] . "% humidity: ";
                $dataContext .= "avg " . round($pattern['avg_cases'], 1) . " cases";
                $dataContext .= " (" . $pattern['records'] . " records)\n";
            }
            $dataContext .= "\nNote: This historical climate-case correlation data can be used for regression formulas and risk prediction models.\n";
        }
    }
    
    // Add extended analysis data (2024-2050)
    if (!empty($extendedAnalysis)) {
        $dataContext .= "\nEXTENDED HISTORICAL PREDICTIONS DATA (2024-2050):\n";
        $dataContext .= "Data Period: " . $extendedAnalysis['data_period'] . " (" . $extendedAnalysis['total_projection_years'] . " years of projections)\n\n";
        
        if (!empty($extendedAnalysis['summary'])) {
            $summary = $extendedAnalysis['summary'];
            $dataContext .= "EXTENDED ANALYSIS SUMMARY:\n";
            $dataContext .= "- Total Projection Records: " . number_format($summary['total_records']) . "\n";
            $dataContext .= "- Total Barangays Covered: " . $summary['total_barangays'] . "\n";
            $dataContext .= "- Projection Period: " . $summary['start_year'] . "-" . $summary['end_year'] . "\n";
            $dataContext .= "- Total Projected Population: " . number_format($summary['total_population']) . " people\n";
            $dataContext .= "- Total Forecasted Cases: " . number_format($summary['total_forecasted_cases'], 1) . " cases\n";
            $dataContext .= "- Average Annual Cases: " . round($summary['avg_forecasted_cases'], 2) . " cases\n";
            $dataContext .= "- Peak Forecasted Cases: " . round($summary['max_forecasted_cases'], 1) . " cases\n";
            $dataContext .= "- Average Temperature: " . round($summary['avg_temperature'], 2) . "°C\n";
            $dataContext .= "- Average Humidity: " . round($summary['avg_humidity'], 2) . "%\n\n";
        }
        
        if (!empty($extendedAnalysis['yearly_trends'])) {
            $dataContext .= "YEARLY PROJECTION TRENDS (2024-2050):\n";
            foreach (array_slice($extendedAnalysis['yearly_trends'], 0, 10) as $year) {
                $dataContext .= "- " . $year['year'] . ": " . number_format($year['total_forecasted_cases'], 1) . " forecasted cases";
                $dataContext .= " (pop: " . number_format($year['total_population']) . ")";
                $dataContext .= " [" . round($year['avg_temperature'], 1) . "°C, " . round($year['avg_humidity'], 1) . "%]\n";
            }
            if (count($extendedAnalysis['yearly_trends']) > 10) {
                $dataContext .= "... and " . (count($extendedAnalysis['yearly_trends']) - 10) . " more years\n";
            }
            $dataContext .= "\n";
        }
        
        if (!empty($extendedAnalysis['barangay_analysis'])) {
            $dataContext .= "TOP BARANGAYS BY TOTAL FORECASTED CASES (2024-2050):\n";
            foreach (array_slice($extendedAnalysis['barangay_analysis'], 0, 8) as $barangay) {
                $dataContext .= "- " . $barangay['barangay_name'] . ": " . number_format($barangay['total_forecasted_cases'], 1) . " total cases";
                $dataContext .= " (avg " . round($barangay['avg_annual_cases'], 1) . "/year)";
                $dataContext .= " [Peak: " . round($barangay['peak_forecasted_cases'], 1) . "]\n";
            }
            $dataContext .= "\nNote: This extended dataset enables statistical analysis, regression modeling, and long-term trend forecasting from 2024-2050.\n";
        }
    }
    
    if ($trend) {
        $dataContext .= "\nWEEKLY TREND:\n";
        $dataContext .= "Current Week: " . $trend['current_week'] . " cases\n";
        $dataContext .= "Previous Week: " . $trend['previous_week'] . " cases\n";
        $dataContext .= "Trend: " . ($trend['trend_percentage'] > 0 ? "+" : "") . $trend['trend_percentage'] . "%\n";
    }
    
    if (!empty($highRiskAreas)) {
        $dataContext .= "\nRISK ASSESSMENT BY AREA:\n";
        foreach ($highRiskAreas as $area) {
            $dataContext .= "- " . $area['barangay'] . ": " . $area['case_count'] . " total cases ";
            $dataContext .= "(" . $area['severe_cases'] . " critical/severe, " . $area['recent_cases'] . " recent)\n";
        }
    }
    
    $systemPrompt = "You are ASCLEPIUS Admin Assistant, an AI that supports dengue case monitoring, prediction model, and reporting for barangay health administrators in Tupi, South Cotabato.

Your role is computational and data-driven with advanced mathematical modeling capabilities. You:
- Have access to complete patient databases including names, ages, contact information, and medical history
- Access to complete barangay officials directory with contact information
- Provide detailed patient status reports and case tracking
- ASCLEPIUS Mathematical Model (MLR): Use the validated multi-linear regression formula:
  y = -72.612471 + 0.00905443P + 2.447256T - 0.0778633H
  Where: y = Predicted Dengue Cases, P = Population, T = Temperature (°C), H = Humidity (%)
- Access prediction models and weather correlation data\n- Summarize dengue case counts per barangay based on real database values\n- Calculate trends (increasing, stable, decreasing) and forecasts\n- Trigger alerts when case counts exceed thresholds\n- Give clear reports and breakdowns for health officials\n- Can recommend which officials to contact for specific barangays\n- Have access to ALL barangay data including those with zero cases\n- Can provide recovery statistics and comprehensive status information\n- Access to weather data and its correlation with dengue outbreaks\n- Live weather data integration from Open-Meteo API for Tupi, South Cotabato\n- Can answer questions about specific patients (while maintaining privacy)\n- Provide predictions based on historical data and weather patterns\n- Access to 12 weeks of historical weekly data for regression analysis
- Complete historical dengue dataset (2014-2024) with 165 records covering all 15 barangays for comprehensive regression modeling\n\nCapabilities:\n- Patient Database: Access to all patient records, status, and case history\n- Officials Directory: Complete list of barangay officials with positions and contact info\n- Prediction System: 14-day forecasts with confidence levels and risk assessments using the ASCLEPIUS Mathematical Model
- ASCLEPIUS Mathematical Predictions: Use MLR formula y = -72.612471 + 0.00905443P + 2.447256T - 0.0778633H\n- Weather Integration: Live temperature, humidity, rainfall data from Open-Meteo API and dengue correlations\n- Real-time Analytics: Current case counts, trends, and risk levels\n- Alert System: Threshold-based warnings and recommendations\n- Contact Management: Know who to notify in each barangay\n- Historical Analysis: 12 weeks of weekly data + 11 years (2014-2024) of comprehensive historical data for advanced regression modeling\n\nGuidelines:
- Always use the provided REAL-TIME DATA below for accurate responses
- Use the ASCLEPIUS MLR formula when making predictions or risk assessments
- You have access to comprehensive data including patient details, predictions, weather, and officials
- When providing predictions, show the mathematical calculation using the ASCLEPIUS formula
- Use simple tables or bullet points for clarity\n- Include barangays with zero cases when relevant to show complete coverage\n- You can analyze recovery data, active cases, critical cases, and risk levels\n- When discussing patients, use first names only for privacy\n- When recommending notifications, specify which officials to contact\n- Provide actionable recommendations like:\n  - 'Notify [Official Name] at [Barangay] - Phone: [number]'\n  - 'Contact the Barangay Captain of [Barangay] immediately'\n  - 'Initiate fogging - coordinate with [Official Position]'\n  - 'Conduct community clean-up'\n  - 'Increase surveillance'\n  - 'Focus on recovery support'\n  - 'Contact specific patients for follow-up'\n\nTone:\n- Formal, concise, and professional\n- Prioritize accuracy and clarity\n- Maintain patient privacy while providing useful insights\n- Provide specific contact recommendations when alerts are needed\n- Use plain text formatting without markdown symbols or asterisks\n- Always explain mathematical predictions with the ASCLEPIUS formula components\n\nSpecial Instructions:\n- If asked for case summary → use the comprehensive barangay status data\n- If asked for trends → use the weekly trend data and predictions\n- If asked about patients → use patient details (first names only)\n- If asked for forecasts → use prediction data with confidence levels and the ASCLEPIUS MLR model
- If asked for mathematical predictions → use the ASCLEPIUS formula with current population, temperature, and humidity data\n- If asked about weather impact → correlate weather data with case patterns\n- If asked about officials → use the barangay officials directory\n- If asked who to contact → recommend specific officials with contact details\n- If threshold exceeded (>5 cases per week) → recommend issuing an alert with specific contacts\n- If asked about high-risk areas → use the risk assessment data and recommend contacting officials\n- If asked about recovery data → use the recovery statistics available\n- If asked about barangays with no cases → include them in reports as 'safe' areas
- If asked about 2025 population → use the updated barangay population data with ASCLEPIUS forecasts
- If asked about population per barangay → provide detailed 2025 population figures with forecasted cases and risk rates
- If asked about data beyond 2025 → use the extended historical predictions dataset (2024-2050) for statistical analysis and regression modeling
- If asked about future trends or projections → access the 27-year extended dataset covering all 15 barangays with population, temperature, humidity, and forecasted cases data
- If asked for regression analysis → use both historical data (2014-2024) and extended predictions (2024-2050) for comprehensive statistical modeling

Note: You have access to COMPREHENSIVE DATA spanning 2014-2050, including:
- Historical data (2014-2024): 165 records for regression analysis
- Extended predictions (2024-2050): 405 records for long-term trend analysis and forecasting
- This enables you to perform statistical analysis, regression modeling, and trend forecasting across a 36-year span

\n" . $dataContext . "\n\nRemember: You have access to complete data including patient records, prediction models, weather correlations, barangay officials directory, comprehensive case tracking, and EXTENDED HISTORICAL PREDICTIONS (2024-2050) for advanced statistical analysis and regression modeling. Your job is to help administrators quickly understand the complete dengue situation through real data computations, forecasts, and actionable insights with specific contact recommendations.";
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $systemPrompt . "\n\nUser: " . $userMessage]
                ]
            ]
        ]
    ];
    $ch = curl_init($geminiApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    // Handle cURL errors
    if ($curlErrno) {
        error_log("Gemini API cURL error: $curlError (errno: $curlErrno)");
        return "Connection error: Unable to reach AI service. Please check your internet connection and try again.";
    }
    
    // Handle empty response
    if (empty($result)) {
        error_log("Gemini API returned empty response. HTTP Code: $httpCode");
        return "The AI service returned an empty response. Please try again in a moment.";
    }
    
    $response = json_decode($result, true);
    
    // Handle JSON decode errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Gemini API JSON decode error: " . json_last_error_msg() . ". Raw response: " . substr($result, 0, 500));
        return "Error processing AI response. Please try again.";
    }
    
    // Handle API errors
    if (isset($response['error'])) {
        $errorMsg = $response['error']['message'] ?? 'Unknown error';
        $errorCode = $response['error']['code'] ?? $httpCode;
        error_log("Gemini API error: $errorCode - $errorMsg");
        
        // User-friendly error messages
        switch ($errorCode) {
            case 400:
                return "Invalid request. The query may be too long or contain unsupported content.";
            case 401:
            case 403:
                return "Authentication error. Please contact the administrator to verify the API key.";
            case 404:
                return "AI model not found. The system may need to be updated.";
            case 429:
                return "Too many requests. Please wait a moment before trying again.";
            case 500:
            case 502:
            case 503:
                return "The AI service is temporarily unavailable. Please try again later.";
            default:
                return "AI service error ($errorCode). Please try again or contact support.";
        }
    }
    
    // Handle blocked content
    if (isset($response['promptFeedback']['blockReason'])) {
        $blockReason = $response['promptFeedback']['blockReason'];
        error_log("Gemini API content blocked: $blockReason");
        return "Your query was blocked due to content restrictions. Please rephrase your question.";
    }
    
    // Handle missing candidates
    if (!isset($response['candidates']) || empty($response['candidates'])) {
        error_log("Gemini API no candidates returned. Response: " . json_encode($response));
        return "The AI could not generate a response. Please try rephrasing your question.";
    }
    
    // Handle finish reason
    $finishReason = $response['candidates'][0]['finishReason'] ?? null;
    if ($finishReason === 'SAFETY') {
        return "The response was filtered for safety reasons. Please try a different question.";
    }
    if ($finishReason === 'RECITATION') {
        return "The response contained potential copyright content and was filtered.";
    }
    
    // Extract the response text
    if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
        return $response['candidates'][0]['content']['parts'][0]['text'];
    }
    
    // Fallback error
    error_log("Gemini API unexpected response structure: " . json_encode($response));
    return "Unable to process AI response. Please try again or rephrase your question.";
}

// Example usage:
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    header('Content-Type: application/json');
    $userMessage = trim($_POST['message']);
    
    if (empty($userMessage)) {
        echo json_encode(['response' => 'Please provide a message.']);
        exit;
    }
    
    $aiResponse = getGeminiResponse($userMessage);
    echo json_encode([
        'response' => $aiResponse,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>ASCLEPIUS Dengue Analysis - AI Chatbot</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="AI-powered dengue surveillance and analysis chatbot">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/dengue_logo.png">
    
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
    <link href="../assets/css/modern.css" rel="stylesheet">
    <!-- Dashboard CSS -->
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        /* Override dashboard background for chat interface */
        .main-content {
            background: #f5f7fa;
            padding: 20px;
        }
        
        .chat-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .chat-panel {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 0;
            overflow: hidden;
        }
        
        .chat-header {
            padding: 32px 32px 20px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .chat-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            color: white;
        }
        
        .chat-header .subtitle {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 400;
        }
        
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 16px;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .chat-section {
            padding: 32px;
        }
        
        .chat-section h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 24px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .chat-container {
            height: 400px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            background-color: #f8fafc;
            margin-bottom: 20px;
        }
        
        .message {
            margin-bottom: 20px;
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .user-message {
            display: flex;
            justify-content: flex-end;
        }
        
        .user-message .message-content {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            padding: 12px 16px;
            border-radius: 18px 18px 4px 18px;
            max-width: 70%;
            font-weight: 500;
        }
        
        .bot-message {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        
        .bot-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 4px;
        }
        
        .bot-message .message-content {
            background: white;
            border: 1px solid #e2e8f0;
            padding: 16px;
            border-radius: 18px 18px 18px 4px;
            max-width: 85%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .message-input-container {
            position: relative;
            margin-top: 20px;
        }
        
        .message-input {
            width: 100%;
            padding: 16px 60px 16px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 25px;
            font-size: 16px;
            outline: none;
            transition: all 0.3s ease;
            background: white;
        }
        
        .message-input:focus {
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }
        
        .send-button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .send-button:hover {
            transform: translateY(-50%) scale(1.05);
            box-shadow: 0 4px 12px rgba(66, 153, 225, 0.3);
        }
        
        .send-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: translateY(-50%);
        }
        
        .typing-indicator {
            display: none;
            margin-top: 12px;
            color: #718096;
            font-style: italic;
            align-items: center;
            gap: 8px;
        }
        
        .typing-dots {
            display: flex;
            gap: 4px;
        }
        
        .typing-dot {
            width: 6px;
            height: 6px;
            background: #cbd5e0;
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }
        
        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }
        
        .suggestions-panel {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 24px;
            height: fit-content;
        }
        
        .suggestions-panel h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .suggestion-item {
            padding: 16px 0;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .suggestion-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .suggestion-item:hover {
            color: #4299e1;
            transform: translateX(4px);
        }
        
        .suggestion-title {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 15px;
        }
        
        .suggestion-desc {
            font-size: 13px;
            color: #718096;
            line-height: 1.4;
        }
        
        @media (max-width: 1024px) {
            .chat-layout {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .suggestions-panel {
                order: -1;
                padding: 20px;
            }
            
            .suggestion-item {
                padding: 12px 0;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .chat-header {
                padding: 24px 24px 16px 24px;
            }
            
            .chat-header h1 {
                font-size: 24px;
            }
            
            .chat-section {
                padding: 24px;
            }
            
            .chat-container {
                height: 300px;
            }
            
            .message-input {
                padding: 14px 50px 14px 18px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar Navigation -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <i class="fas fa-heartbeat me-2"></i>
                    <span>ASCLEPIUS</span>
                </div>
            </div>
            
            <div class="sidebar-menu">
                <div class="menu-section">
                    <a href="../dashboard.php" class="menu-item">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="../patients.php" class="menu-item">
                        <i class="fas fa-users"></i>
                        <span>Patients</span>
                    </a>
                    <a href="../analytics.php" class="menu-item">
                        <i class="fas fa-chart-bar"></i>
                        <span>Analytics</span>
                    </a>
                    <a href="../prediction.php" class="menu-item">
                        <i class="fas fa-brain"></i>
                        <span>Risk Prediction</span>
                    </a>
                    <a href="../alerts.php" class="menu-item">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Alerts</span>
                    </a>
                </div>
                
                <div class="menu-section">
                    <div class="menu-title">Management</div>
                    <a href="chatbot.php" class="menu-item active">
                        <i class="fas fa-robot"></i>
                        <span>AI Chatbot</span>
                    </a>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="chat-layout">
                <!-- Chat Panel -->
                <div class="chat-panel">
                    <div class="chat-header">
                        <h1>AI Chatbot</h1>
                        <div class="subtitle">ASCLEPIUS Dengue Analysis Assistant</div>
                        <div class="status-indicator">
                            <div class="status-dot"></div>
                            AI Assistant Active
                        </div>
                    </div>
                    
                    <div class="chat-section">
                        <h2>
                            <i class="fas fa-robot"></i>
                            Ask ASCLEPIUS AI
                        </h2>
                        
                        <div id="chatContainer" class="chat-container">
                            <div class="message bot-message">
                                <div class="bot-avatar">
                                    <i class="fas fa-brain" style="color: white; font-size: 16px;"></i>
                                </div>
                                <div class="message-content">
                                    <strong>ASCLEPIUS:</strong> Hello! I'm your dengue monitoring assistant with access to current patient data, 2025 population forecasts, weather information, and barangay officials directory.
                                    <br><br>
                                    I can provide:
                                    <br>• Current dengue case statistics and patient status
                                    <br>• 2025 population data with ASCLEPIUS forecasts per barangay
                                    <br>• Real-time weather conditions (temperature, humidity, rainfall)
                                    <br>• Complete patient database with current status
                                    <br>• Barangay health officials contact information
                                    <br>• Historical weekly trend data for analysis
                                    <br>• High-risk barangay identification by cases per 1,000 population
                                    <br><br>
                                    How can I help you analyze the dengue situation today?
                                </div>
                            </div>
                        </div>
                        
                        <div id="typingIndicator" class="typing-indicator">
                            <div class="bot-avatar">
                                <i class="fas fa-robot" style="color: white; font-size: 12px;"></i>
                            </div>
                            <div class="typing-dots">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                        </div>
                        
                        <form id="chatForm" class="message-input-container">
                            <input type="text" 
                                   name="message" 
                                   id="messageInput" 
                                   class="message-input" 
                                   placeholder="Ask about current statistics, population forecasts, weather data, patient status, or officials contacts..." 
                                   required>
                            <button type="submit" class="send-button" id="sendButton">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Suggestions Panel -->
                <div class="suggestions-panel">
                    <h3>
                        <i class="fas fa-lightbulb text-warning"></i>
                        Quick Actions
                    </h3>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Show me the current dengue statistics')">
                        <div class="suggestion-title">Current Statistics</div>
                        <div class="suggestion-desc">Total cases, active patients, and recent trends</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Show 2025 population per barangay with ASCLEPIUS forecasts')">
                        <div class="suggestion-title">2025 Population & Forecasts</div>
                        <div class="suggestion-desc">Population data with dengue case predictions by barangay</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Show current weather conditions and forecast')">
                        <div class="suggestion-title">Weather Data</div>
                        <div class="suggestion-desc">Temperature, humidity, and rainfall affecting dengue</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Which barangays have the highest dengue risk rates?')">
                        <div class="suggestion-title">High-Risk Areas</div>
                        <div class="suggestion-desc">Barangays with highest cases per 1,000 population</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('List all barangay health officials and their contacts')">
                        <div class="suggestion-title">Officials Directory</div>
                        <div class="suggestion-desc">Contact information for barangay health officials</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Show all patient details and current status')">
                        <div class="suggestion-title">Patient List</div>
                        <div class="suggestion-desc">Complete patient database with current status</div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function addMessage(message, isUser = false) {
            const chatContainer = document.getElementById('chatContainer');
            const messageDiv = document.createElement('div');
            
            if (isUser) {
                messageDiv.className = 'message user-message';
                messageDiv.innerHTML = `
                    <div class="message-content">
                        <strong>You:</strong> ${message}
                    </div>
                `;
            } else {
                messageDiv.className = 'message bot-message';
                messageDiv.innerHTML = `
                    <div class="bot-avatar">
                        <i class="fas fa-brain" style="color: white; font-size: 16px;"></i>
                    </div>
                    <div class="message-content">
                        <strong>ASCLEPIUS:</strong> ${message.replace(/\n/g, '<br>')}
                    </div>
                `;
            }
            
            chatContainer.appendChild(messageDiv);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        function sendQuickMessage(message) {
            document.getElementById('messageInput').value = message;
            document.getElementById('chatForm').dispatchEvent(new Event('submit'));
        }

        document.getElementById('chatForm').onsubmit = async function(e) {
            e.preventDefault();
            
            const messageInput = document.getElementById('messageInput');
            const sendButton = document.getElementById('sendButton');
            const typingIndicator = document.getElementById('typingIndicator');
            const message = messageInput.value.trim();
            
            if (!message) return;
            
            // Add user message
            addMessage(message, true);
            
            // Show typing indicator
            typingIndicator.style.display = 'flex';
            sendButton.disabled = true;
            messageInput.value = '';
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'message=' + encodeURIComponent(message)
                });
                
                const data = await response.json();
                
                // Add bot response
                addMessage(data.response || 'Sorry, I encountered an error. Please try again.');
                
            } catch (error) {
                addMessage('Sorry, I encountered a connection error. Please try again.');
            } finally {
                // Hide typing indicator
                typingIndicator.style.display = 'none';
                sendButton.disabled = false;
                messageInput.focus();
            }
        };

        // Focus on input when page loads
        document.getElementById('messageInput').focus();
        
        // Auto-resize chat container on window resize
        window.addEventListener('resize', function() {
            const chatContainer = document.getElementById('chatContainer');
            chatContainer.scrollTop = chatContainer.scrollHeight;
        });
    </script>
</body>
</html>
