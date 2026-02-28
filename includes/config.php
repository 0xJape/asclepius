<?php
// Database configuration
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = 'gayojalelprince21';
$DB_NAME = 'asclpe_db';

// Establish database connection
function getDBConnection() {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;
    try {
        $conn = new PDO(
            "mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4",
            $DB_USER,
            $DB_PASS
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch(PDOException $e) {
        error_log("Connection failed: " . $e->getMessage());
        die("Connection failed. Please try again later.");
    }
}

// Dashboard Statistics Function
function getDashboardStats() {
    $db = getDBConnection();
    $stats = array();
    
    try {
        // Get total cases
        $query = "SELECT COUNT(*) as total FROM patient_cases";
        $stmt = $db->query($query);
        $stats['total_cases'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get cases in last 30 days for trend
        $query = "SELECT COUNT(*) as monthly FROM patient_cases 
                 WHERE date_reported >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $stmt = $db->query($query);
        $monthlyCount = $stmt->fetch(PDO::FETCH_ASSOC)['monthly'];

        // Get cases in previous 30 days for comparison
        $query = "SELECT COUNT(*) as prev_monthly FROM patient_cases 
                 WHERE date_reported BETWEEN DATE_SUB(CURDATE(), INTERVAL 60 DAY) 
                 AND DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $stmt = $db->query($query);
        $prevMonthlyCount = $stmt->fetch(PDO::FETCH_ASSOC)['prev_monthly'];

        // Calculate trend percentage
        $stats['case_trend'] = $prevMonthlyCount > 0 ? 
            round((($monthlyCount - $prevMonthlyCount) / $prevMonthlyCount) * 100) : 0;

        // Get weekly cases
        $query = "SELECT COUNT(*) as weekly FROM patient_cases 
                 WHERE date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $stmt = $db->query($query);
        $stats['weekly_cases'] = $stmt->fetch(PDO::FETCH_ASSOC)['weekly'];

        // Get previous week's cases for comparison
        $query = "SELECT COUNT(*) as prev_weekly FROM patient_cases 
                 WHERE date_reported BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) 
                 AND DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $stmt = $db->query($query);
        $prevWeeklyCount = $stmt->fetch(PDO::FETCH_ASSOC)['prev_weekly'];

        // Calculate weekly trend
        $stats['weekly_trend'] = $prevWeeklyCount > 0 ? 
            round((($stats['weekly_cases'] - $prevWeeklyCount) / $prevWeeklyCount) * 100) : 0;

        // Determine risk level based on weekly cases and trend
        if ($stats['weekly_cases'] > 50 || $stats['weekly_trend'] > 50) {
            $stats['risk_level'] = 'HIGH';
            $stats['risk_color'] = 'danger';
        } elseif ($stats['weekly_cases'] > 25 || $stats['weekly_trend'] > 25) {
            $stats['risk_level'] = 'MEDIUM';
            $stats['risk_color'] = 'warning';
        } else {
            $stats['risk_level'] = 'LOW';
            $stats['risk_color'] = 'success';
        }

        // Get active alerts count
        $stats['active_alerts'] = getActiveAlertsCount();
        $stats['high_priority'] = $stats['risk_level'] === 'HIGH';

        // Mock weather data (you can integrate with a weather API later)
        $stats['temperature'] = 32.5;
        $stats['humidity'] = 75;

    } catch(PDOException $e) {
        error_log("Error fetching dashboard stats: " . $e->getMessage());
        return array();
    }

    return $stats;
}

// Get Active Alerts
function getActiveAlerts() {
    $db = getDBConnection();
    
    try {
        $query = "
            SELECT 
                b.barangay_id,
                b.name as barangay,
                COUNT(pc.case_id) as case_count,
                MAX(pc.status) as highest_severity,
                MAX(pc.date_reported) as latest_case
            FROM barangays b
            LEFT JOIN patients p ON b.barangay_id = p.barangay_id
            LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id
            WHERE pc.date_reported >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
            GROUP BY b.barangay_id, b.name
            HAVING case_count >= 3
            ORDER BY case_count DESC, latest_case DESC
            LIMIT 5
        ";

        $stmt = $db->query($query);
        $alerts = ['priority' => []];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $risk_level = 'MEDIUM';
            $risk_color = 'warning';
            
            if ($row['case_count'] >= 8) {
                $risk_level = 'HIGH';
                $risk_color = 'danger';
            } elseif ($row['case_count'] >= 5) {
                $risk_level = 'MEDIUM';
                $risk_color = 'warning';
            } else {
                $risk_level = 'LOW';
                $risk_color = 'info';
            }

            // Calculate time ago
            if ($row['latest_case']) {
                $latest = new DateTime($row['latest_case']);
                $now = new DateTime();
                $interval = $latest->diff($now);
                
                if ($interval->days > 0) {
                    $time_ago = $interval->days . ' day' . ($interval->days > 1 ? 's' : '') . ' ago';
                } elseif ($interval->h > 0) {
                    $time_ago = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
                } else {
                    $time_ago = 'Today';
                }
            } else {
                $time_ago = 'Unknown';
            }

            $alerts['priority'][] = [
                'id' => $row['barangay_id'],
                'barangay' => $row['barangay'],
                'risk_level' => $risk_level,
                'risk_color' => $risk_color,
                'message' => sprintf(
                    '%d cases reported in the last 7 days. Status: %s', 
                    $row['case_count'],
                    $row['highest_severity'] ?? 'Unknown'
                ),
                'time_ago' => $time_ago
            ];
        }

        return $alerts;
    } catch(PDOException $e) {
        error_log("Error getting active alerts: " . $e->getMessage());
        return ['priority' => []];
    }
}

// Get Active Alerts Count
function getActiveAlertsCount() {
    $db = getDBConnection();
    
    try {
        $query = "SELECT COUNT(DISTINCT b.barangay_id) as alert_count
                 FROM patient_cases pc
                 JOIN patients p ON pc.patient_id = p.patient_id
                 JOIN barangays b ON p.barangay_id = b.barangay_id
                 WHERE pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 GROUP BY b.barangay_id
                 HAVING COUNT(*) >= 5";
                 
        $stmt = $db->query($query);
        return $stmt->rowCount();
        
    } catch(PDOException $e) {
        error_log("Error counting active alerts: " . $e->getMessage());
        return 0;
    }
}

// Get Recent Cases
function getRecentCases() {
    $db = getDBConnection();
    
    try {
        $query = "SELECT 
                    pc.case_id as id,
                    CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
                    b.name as barangay,
                    pc.date_reported as date,
                    pc.status
                 FROM patient_cases pc
                 JOIN patients p ON pc.patient_id = p.patient_id
                 JOIN barangays b ON p.barangay_id = b.barangay_id
                 ORDER BY pc.date_reported DESC
                 LIMIT 10";
                 
        $stmt = $db->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log("Error fetching recent cases: " . $e->getMessage());
        return array();
    }
}

// Get Barangay Cases for Map
function getBarangayCases($days = 30) {
    $db = getDBConnection();
    
    try {
        $query = "
            SELECT 
                b.barangay_id,
                b.name,
                b.latitude,
                b.longitude,
                b.population,
                COUNT(CASE 
                    WHEN pc.date_reported >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY) 
                    THEN pc.case_id 
                    ELSE NULL 
                END) as case_count
            FROM barangays b
            LEFT JOIN patients p ON b.barangay_id = p.barangay_id
            LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id
            GROUP BY b.barangay_id, b.name, b.latitude, b.longitude, b.population
            ORDER BY case_count DESC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$days]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: Log the results
        error_log("getBarangayCases results for $days days: " . print_r($results, true));
        
        return $results;
        
    } catch(PDOException $e) {
        error_log("Error getting barangay cases: " . $e->getMessage());
        return [];
    }
}

// Utility function to format time ago
function getTimeAgo($date) {
    $timestamp = strtotime($date);
    $difference = time() - $timestamp;
    
    if ($difference < 60) {
        return "Just now";
    } elseif ($difference < 3600) {
        return round($difference/60) . " minutes ago";
    } elseif ($difference < 86400) {
        return round($difference/3600) . " hours ago";
    } elseif ($difference < 604800) {
        return round($difference/86400) . " days ago";
    } else {
        return date("M j, Y", $timestamp);
    }
}
