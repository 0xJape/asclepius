<?php
// API endpoint for chatbot data access
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/config.php';

// Function to get historical dengue data for regression analysis
function getHistoricalData($barangay = null, $startYear = null, $endYear = null) {
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
        return ['error' => 'Failed to fetch historical data: ' . $e->getMessage()];
    }
}

// Function to get statistical analysis of historical data
function getHistoricalAnalysis() {
    $db = getDBConnection();
    
    try {
        // Overall statistics
        $overallQuery = "SELECT 
            COUNT(*) as total_records,
            SUM(dengue_cases) as total_cases,
            AVG(dengue_cases) as avg_cases,
            MAX(dengue_cases) as max_cases,
            MIN(dengue_cases) as min_cases,
            AVG(temperature) as avg_temp,
            AVG(humidity) as avg_humidity
            FROM historical_dengue_data";
        
        $stmt = $db->prepare($overallQuery);
        $stmt->execute();
        $overall = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Year-wise trends
        $yearlyQuery = "SELECT 
            year,
            SUM(dengue_cases) as total_cases,
            AVG(dengue_cases) as avg_cases,
            COUNT(*) as barangay_count,
            AVG(temperature) as avg_temp,
            AVG(humidity) as avg_humidity
            FROM historical_dengue_data
            GROUP BY year
            ORDER BY year";
        
        $stmt = $db->prepare($yearlyQuery);
        $stmt->execute();
        $yearly = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Barangay-wise analysis
        $barangayQuery = "SELECT 
            barangay,
            SUM(dengue_cases) as total_cases,
            AVG(dengue_cases) as avg_cases,
            MAX(dengue_cases) as peak_cases,
            AVG(cases_per_1000) as avg_incidence,
            COUNT(*) as years_data
            FROM historical_dengue_data
            GROUP BY barangay
            ORDER BY total_cases DESC";
        
        $stmt = $db->prepare($barangayQuery);
        $stmt->execute();
        $barangay = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Correlation analysis (climate vs cases)
        $correlationQuery = "SELECT 
            ROUND(AVG(temperature), 2) as temp,
            ROUND(AVG(humidity), 2) as humidity,
            SUM(dengue_cases) as cases,
            COUNT(*) as records
            FROM historical_dengue_data
            GROUP BY ROUND(temperature, 0), ROUND(humidity, 0)
            HAVING records >= 3
            ORDER BY cases DESC
            LIMIT 20";
        
        $stmt = $db->prepare($correlationQuery);
        $stmt->execute();
        $correlation = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'overall' => $overall,
            'yearly_trends' => $yearly,
            'barangay_analysis' => $barangay,
            'climate_correlation' => $correlation
        ];
    } catch (Exception $e) {
        return ['error' => 'Failed to analyze historical data: ' . $e->getMessage()];
    }
}

function getChatbotData() {
    $db = getDBConnection();
    
    try {
        // Get comprehensive patient data
        $patientQuery = "SELECT 
                        COUNT(*) as total_patients,
                        COUNT(CASE WHEN pc.status = 'Active' THEN 1 END) as active_patients,
                        COUNT(CASE WHEN pc.status = 'Recovered' THEN 1 END) as recovered_patients,
                        COUNT(CASE WHEN pc.status IN ('Critical', 'Severe') THEN 1 END) as critical_patients
                        FROM patients p
                        LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id AND pc.date_reported = (
                            SELECT MAX(date_reported) 
                            FROM patient_cases pc_inner 
                            WHERE pc_inner.patient_id = p.patient_id
                        )";
        $stmt = $db->prepare($patientQuery);
        $stmt->execute();
        $patientStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get case statistics
        $caseQuery = "SELECT 
                      COUNT(*) as total_cases,
                      COUNT(CASE WHEN date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as recent_cases,
                      COUNT(CASE WHEN date_reported >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as monthly_cases
                      FROM patient_cases";
        $stmt = $db->prepare($caseQuery);
        $stmt->execute();
        $caseStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get barangay breakdown
        $barangayQuery = "SELECT 
                         b.name as barangay,
                         COUNT(pc.case_id) as case_count,
                         COUNT(CASE WHEN pc.status = 'Active' THEN 1 END) as active_cases,
                         COUNT(CASE WHEN pc.status IN ('Critical', 'Severe') THEN 1 END) as critical_cases
                         FROM barangays b
                         LEFT JOIN patients p ON b.barangay_id = p.barangay_id
                         LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id
                         GROUP BY b.barangay_id, b.name
                         ORDER BY case_count DESC";
        $stmt = $db->prepare($barangayQuery);
        $stmt->execute();
        $barangayStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent patients (limited for privacy)
        $recentPatientsQuery = "SELECT 
                               p.first_name,
                               b.name as barangay,
                               pc.status,
                               pc.date_reported,
                               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
                               p.gender
                               FROM patients p
                               JOIN barangays b ON p.barangay_id = b.barangay_id
                               JOIN patient_cases pc ON p.patient_id = pc.patient_id
                               WHERE pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                               ORDER BY pc.date_reported DESC
                               LIMIT 10";
        $stmt = $db->prepare($recentPatientsQuery);
        $stmt->execute();
        $recentPatients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check for prediction data
        $predictions = [];
        $predictionQuery = "SHOW TABLES LIKE 'dengue_predictions'";
        $stmt = $db->prepare($predictionQuery);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $predQuery = "SELECT prediction_date, predicted_cases, confidence_level, risk_level 
                         FROM dengue_predictions 
                         WHERE prediction_date >= CURDATE() 
                         ORDER BY prediction_date ASC 
                         LIMIT 7";
            $stmt = $db->prepare($predQuery);
            $stmt->execute();
            $predictions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get live weather data from WeatherAPI.com (same as prediction.php)
        $weather = [];
        try {
            define('WEATHER_API_KEY', '0b35fd682d954b8cac703400252108');
            define('DEFAULT_LOCATION', 'Tupi,South Cotabato');
            
            $url = "http://api.weatherapi.com/v1/forecast.json?key=" . WEATHER_API_KEY . 
                   "&q=" . urlencode(DEFAULT_LOCATION) . "&days=7&aqi=no&alerts=no";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            
            if (!curl_errno($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $data = json_decode($response, true);
                
                if ($data && isset($data['forecast'])) {
                    // Current weather
                    $current = $data['current'];
                    $weather[] = [
                        'date' => date('Y-m-d'),
                        'temperature' => $current['temp_c'],
                        'humidity' => $current['humidity'],
                        'rainfall' => $current['precip_mm'],
                        'weather_condition' => $current['condition']['text'],
                        'type' => 'current'
                    ];
                    
                    // Forecast data
                    foreach ($data['forecast']['forecastday'] as $forecast) {
                        $day = $forecast['day'];
                        $weather[] = [
                            'date' => $forecast['date'],
                            'temperature' => $day['avgtemp_c'],
                            'humidity' => $day['avghumidity'],
                            'rainfall' => $day['totalprecip_mm'],
                            'weather_condition' => $day['condition']['text'],
                            'type' => 'forecast'
                        ];
                    }
                }
            }
            curl_close($ch);
        } catch(Exception $e) {
            error_log("Weather API error in chatbot endpoint: " . $e->getMessage());
        }
        
        // Get barangay officials
        $officials = [];
        $officialsQuery = "SELECT 
                          bo.name,
                          bo.position,
                          bo.email,
                          bo.phone,
                          bo.is_primary,
                          b.name as barangay_name
                          FROM barangay_officials bo
                          JOIN barangays b ON bo.barangay_id = b.barangay_id
                          ORDER BY b.name, bo.is_primary DESC, bo.position";
        $stmt = $db->prepare($officialsQuery);
        $stmt->execute();
        $officials = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get historical weekly data for regression analysis
        $historical = [];
        $historicalQuery = "
            SELECT 
                DATE_FORMAT(date_reported, '%Y-%U') as week,
                YEAR(date_reported) as year,
                WEEK(date_reported) as week_num,
                COUNT(*) as weekly_cases,
                DATE_SUB(DATE(date_reported), INTERVAL WEEKDAY(date_reported) DAY) as week_start,
                DATE_ADD(DATE_SUB(DATE(date_reported), INTERVAL WEEKDAY(date_reported) DAY), INTERVAL 6 DAY) as week_end
            FROM patient_cases 
            WHERE date_reported >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
            GROUP BY YEAR(date_reported), WEEK(date_reported)
            ORDER BY year DESC, week_num DESC
        ";
        $stmt = $db->prepare($historicalQuery);
        $stmt->execute();
        $historical = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'data' => [
                'patient_stats' => $patientStats,
                'case_stats' => $caseStats,
                'barangay_stats' => $barangayStats,
                'recent_patients' => $recentPatients,
                'predictions' => $predictions,
                'weather' => $weather,
                'officials' => $officials,
                'historical_data' => $historical,
                'last_updated' => date('Y-m-d H:i:s')
            ]
        ];
        
    } catch(PDOException $e) {
        error_log("Chatbot API error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Database error occurred'
        ];
    }
}

// Handle the request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(getChatbotData());
} else {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
