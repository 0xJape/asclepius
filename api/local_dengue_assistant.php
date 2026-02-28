<?php
// Local Dengue Assistant - works without internet
header('Content-Type: application/json');
require_once '../includes/config.php';

// Get JSON POST body
$input = file_get_contents('php://input');
if (!$input) {
    echo json_encode(["error" => "No input received."]);
    exit;
}

$data = json_decode($input, true);
$message = strtolower(trim($data['message'] ?? ''));

// Simple rule-based responses for dengue monitoring
function getDengueResponse($message) {
    $db = getDBConnection();
    
    // Case summary commands
    if (strpos($message, 'case summary') !== false || strpos($message, 'total cases') !== false) {
        try {
            $query = "SELECT b.name, COUNT(pc.case_id) as cases 
                     FROM barangays b 
                     LEFT JOIN patients p ON b.barangay_id = p.barangay_id 
                     LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id 
                     WHERE pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                     GROUP BY b.barangay_id, b.name 
                     ORDER BY cases DESC";
            $stmt = $db->query($query);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = "**DENGUE CASE SUMMARY (Last 30 Days)**\n\n";
            $total = 0;
            foreach ($results as $row) {
                if ($row['cases'] > 0) {
                    $response .= "• {$row['name']}: {$row['cases']} cases\n";
                    $total += $row['cases'];
                }
            }
            $response .= "\n**Total: {$total} cases**\n";
            
            if ($total > 10) {
                $response .= "\n⚠️ **ALERT**: High case count detected.\n**Recommendation**: Notify Barangay Health Workers, initiate community clean-up.";
            }
            
            return $response;
        } catch (Exception $e) {
            return "Error retrieving case data: " . $e->getMessage();
        }
    }
    
    // Trend analysis
    if (strpos($message, 'trend') !== false || strpos($message, 'increasing') !== false) {
        try {
            $query = "SELECT 
                        COUNT(CASE WHEN pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as this_week,
                        COUNT(CASE WHEN pc.date_reported BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as last_week
                      FROM patient_cases pc";
            $stmt = $db->query($query);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $trend = "stable";
            $change = $result['this_week'] - $result['last_week'];
            if ($change > 2) $trend = "increasing";
            if ($change < -2) $trend = "decreasing";
            
            $response = "**DENGUE TREND ANALYSIS**\n\n";
            $response .= "• This week: {$result['this_week']} cases\n";
            $response .= "• Last week: {$result['last_week']} cases\n";
            $response .= "• Trend: **" . strtoupper($trend) . "**\n";
            
            if ($trend === "increasing") {
                $response .= "\n⚠️ **ALERT**: Cases are increasing.\n**Recommendation**: Initiate fogging operations, conduct health education campaigns.";
            }
            
            return $response;
        } catch (Exception $e) {
            return "Error analyzing trends: " . $e->getMessage();
        }
    }
    
    // High-risk areas
    if (strpos($message, 'high-risk') !== false || strpos($message, 'hotspot') !== false) {
        try {
            $query = "SELECT b.name, COUNT(pc.case_id) as cases,
                            COUNT(CASE WHEN pc.status IN ('Critical', 'Severe') THEN 1 END) as severe_cases
                     FROM barangays b 
                     JOIN patients p ON b.barangay_id = p.barangay_id 
                     JOIN patient_cases pc ON p.patient_id = pc.patient_id 
                     WHERE pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                     GROUP BY b.barangay_id, b.name 
                     HAVING cases >= 3
                     ORDER BY cases DESC, severe_cases DESC
                     LIMIT 5";
            $stmt = $db->query($query);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = "**HIGH-RISK BARANGAYS (Last 30 Days)**\n\n";
            if (empty($results)) {
                $response .= "No high-risk areas detected.\n";
            } else {
                foreach ($results as $row) {
                    $response .= "• **{$row['name']}**: {$row['cases']} cases ({$row['severe_cases']} severe)\n";
                }
                $response .= "\n**Recommendation**: Focus prevention efforts on these areas.";
            }
            
            return $response;
        } catch (Exception $e) {
            return "Error identifying high-risk areas: " . $e->getMessage();
        }
    }
    
    // Help command
    if (strpos($message, 'help') !== false) {
        return "**ASCLEPIUS DENGUE ASSISTANT**\n\nI can help with:\n\n• **case summary** - Get total cases by barangay\n• **trend analysis** - Compare this week vs last week\n• **high-risk areas** - Identify dengue hotspots\n• **poblacion data** - Get specific barangay info\n\nJust type your request naturally!";
    }
    
    // Specific barangay queries
    foreach (['poblacion', 'acmonan', 'kablon', 'bunao', 'cebuano', 'linan', 'simbo'] as $barangay) {
        if (strpos($message, $barangay) !== false) {
            try {
                $query = "SELECT COUNT(pc.case_id) as cases,
                                COUNT(CASE WHEN pc.status = 'Critical' THEN 1 END) as critical,
                                COUNT(CASE WHEN pc.status = 'Severe' THEN 1 END) as severe,
                                COUNT(CASE WHEN pc.status = 'Recovered' THEN 1 END) as recovered
                         FROM barangays b 
                         JOIN patients p ON b.barangay_id = p.barangay_id 
                         JOIN patient_cases pc ON p.patient_id = pc.patient_id 
                         WHERE b.name LIKE '%{$barangay}%' 
                         AND pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                $stmt = $db->query($query);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $response = "**" . strtoupper($barangay) . " DENGUE DATA (Last 30 Days)**\n\n";
                $response .= "• Total cases: {$result['cases']}\n";
                $response .= "• Critical: {$result['critical']}\n";
                $response .= "• Severe: {$result['severe']}\n";
                $response .= "• Recovered: {$result['recovered']}\n";
                
                if ($result['cases'] > 5) {
                    $response .= "\n⚠️ **ALERT**: High case count in this barangay.";
                }
                
                return $response;
            } catch (Exception $e) {
                return "Error retrieving {$barangay} data: " . $e->getMessage();
            }
        }
    }
    
    // Default response
    return "I'm ASCLEPIUS, your dengue monitoring assistant. I can provide case summaries, trend analysis, and identify high-risk areas. Try asking about 'case summary', 'trends', or specific barangays like 'poblacion'.";
}

$response = getDengueResponse($message);

echo json_encode([
    "candidates" => [
        [
            "content" => [
                "parts" => [
                    ["text" => $response]
                ]
            ]
        ]
    ]
]);
?>
