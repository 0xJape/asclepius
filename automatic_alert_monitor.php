<?php
/**
 * Automatic Alert Monitor
 * Checks dengue case counts against configured thresholds and sends automatic email alerts
 * Should be run via Windows Task Scheduler every hour
 */

// Prevent direct web access
if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    die("Direct access not allowed. This script should be run via command line only.");
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/smtp_config.php';

// Create alert history table if not exists
function createAlertHistoryTable() {
    try {
        $db = getDBConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS alert_history (
                alert_id INT PRIMARY KEY AUTO_INCREMENT,
                barangay_id INT NOT NULL,
                alert_type ENUM('7_day_threshold', '24_hour_threshold', 'severe_threshold') NOT NULL,
                case_count INT NOT NULL,
                threshold_value INT NOT NULL,
                officials_notified INT DEFAULT 0,
                alert_sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_barangay_type (barangay_id, alert_type),
                INDEX idx_sent_at (alert_sent_at),
                FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id) ON DELETE CASCADE
            )
        ");
        return true;
    } catch (PDOException $e) {
        logMessage("ERROR: Failed to create alert_history table: " . $e->getMessage());
        return false;
    }
}

// Get alert settings
function getAlertSettings() {
    try {
        $db = getDBConnection();
        $stmt = $db->query("SELECT setting_name, setting_value FROM alert_settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_name']] = $row['setting_value'];
        }
        
        // Return defaults if no settings found
        if (empty($settings)) {
            return [
                'case_threshold_7days' => '5',
                'case_threshold_24hours' => '3',
                'severe_case_threshold' => '2',
                'auto_email_enabled' => '1',
                'email_frequency_hours' => '6'
            ];
        }
        
        return $settings;
    } catch (PDOException $e) {
        logMessage("ERROR: Failed to get alert settings: " . $e->getMessage());
        return null;
    }
}

// Check if alert was recently sent to prevent spam
function wasRecentAlertSent($barangayId, $alertType, $frequencyHours) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT alert_id FROM alert_history 
            WHERE barangay_id = ? AND alert_type = ? 
            AND alert_sent_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            LIMIT 1
        ");
        $stmt->execute([$barangayId, $alertType, $frequencyHours]);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        logMessage("ERROR: Failed to check recent alerts: " . $e->getMessage());
        return true; // Assume alert was sent to prevent spam
    }
}

// Get barangay officials for a specific barangay
function getBarangayOfficials($barangayId) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT bo.*, b.name as barangay_name
            FROM barangay_officials bo
            JOIN barangays b ON bo.barangay_id = b.barangay_id
            WHERE bo.barangay_id = ?
            ORDER BY bo.position
        ");
        $stmt->execute([$barangayId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logMessage("ERROR: Failed to get barangay officials: " . $e->getMessage());
        return [];
    }
}

// Check 7-day case threshold
function check7DayThreshold($threshold, $frequencyHours) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT 
                b.barangay_id,
                b.name as barangay_name,
                COUNT(pc.case_id) as case_count
            FROM barangays b
            LEFT JOIN patients p ON b.barangay_id = p.barangay_id
            LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id
            WHERE pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY b.barangay_id, b.name
            HAVING case_count >= ?
        ");
        $stmt->execute([$threshold]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $alertsSent = 0;
        foreach ($results as $result) {
            if (!wasRecentAlertSent($result['barangay_id'], '7_day_threshold', $frequencyHours)) {
                $alertsSent += sendThresholdAlert($result, '7_day_threshold', $threshold, $result['case_count']);
            }
        }
        
        return $alertsSent;
    } catch (PDOException $e) {
        logMessage("ERROR: Failed to check 7-day threshold: " . $e->getMessage());
        return 0;
    }
}

// Check 24-hour case threshold
function check24HourThreshold($threshold, $frequencyHours) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT 
                b.barangay_id,
                b.name as barangay_name,
                COUNT(pc.case_id) as case_count
            FROM barangays b
            LEFT JOIN patients p ON b.barangay_id = p.barangay_id
            LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id
            WHERE pc.date_reported >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY b.barangay_id, b.name
            HAVING case_count >= ?
        ");
        $stmt->execute([$threshold]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $alertsSent = 0;
        foreach ($results as $result) {
            if (!wasRecentAlertSent($result['barangay_id'], '24_hour_threshold', $frequencyHours)) {
                $alertsSent += sendThresholdAlert($result, '24_hour_threshold', $threshold, $result['case_count']);
            }
        }
        
        return $alertsSent;
    } catch (PDOException $e) {
        logMessage("ERROR: Failed to check 24-hour threshold: " . $e->getMessage());
        return 0;
    }
}

// Check severe case threshold
function checkSevereThreshold($threshold, $frequencyHours) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT 
                b.barangay_id,
                b.name as barangay_name,
                COUNT(pc.case_id) as case_count
            FROM barangays b
            LEFT JOIN patients p ON b.barangay_id = p.barangay_id
            LEFT JOIN patient_cases pc ON p.patient_id = pc.patient_id
            WHERE pc.status IN ('Critical', 'Severe')
            AND pc.date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY b.barangay_id, b.name
            HAVING case_count >= ?
        ");
        $stmt->execute([$threshold]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $alertsSent = 0;
        foreach ($results as $result) {
            if (!wasRecentAlertSent($result['barangay_id'], 'severe_threshold', $frequencyHours)) {
                $alertsSent += sendThresholdAlert($result, 'severe_threshold', $threshold, $result['case_count']);
            }
        }
        
        return $alertsSent;
    } catch (PDOException $e) {
        logMessage("ERROR: Failed to check severe threshold: " . $e->getMessage());
        return 0;
    }
}

// Send threshold alert to barangay officials
function sendThresholdAlert($barangayData, $alertType, $threshold, $caseCount) {
    $officials = getBarangayOfficials($barangayData['barangay_id']);
    
    if (empty($officials)) {
        logMessage("WARNING: No officials found for barangay: " . $barangayData['barangay_name']);
        return 0;
    }
    
    // Generate alert message based on type
    $message = generateAlertMessage($alertType, $barangayData['barangay_name'], $caseCount, $threshold);
    $urgency = determineUrgency($alertType, $caseCount, $threshold);
    
    $emailsSent = 0;
    foreach ($officials as $official) {
        if (!empty($official['email'])) {
            $result = sendAutomaticEmail($official, $message, $urgency, $alertType);
            if ($result['success']) {
                $emailsSent++;
            }
        }
    }
    
    // Log the alert in history
    logAlertHistory($barangayData['barangay_id'], $alertType, $caseCount, $threshold, $emailsSent);
    
    logMessage("INFO: Sent $emailsSent automatic alerts for {$barangayData['barangay_name']} ($alertType: $caseCount cases)");
    
    return $emailsSent > 0 ? 1 : 0;
}

// Generate alert message based on type
function generateAlertMessage($alertType, $barangayName, $caseCount, $threshold) {
    switch ($alertType) {
        case '7_day_threshold':
            return "AUTOMATIC ALERT: Dengue case threshold exceeded in $barangayName\n\n" .
                   "⚠️ THRESHOLD BREACH DETECTED\n" .
                   "• Cases in last 7 days: $caseCount\n" .
                   "• Alert threshold: $threshold cases\n" .
                   "• Status: ABOVE NORMAL LEVELS\n\n" .
                   "IMMEDIATE ACTIONS REQUIRED:\n" .
                   "1. Conduct immediate search and destroy operations\n" .
                   "2. Intensify community education on dengue prevention\n" .
                   "3. Monitor residents for early symptoms\n" .
                   "4. Coordinate with barangay health workers\n" .
                   "5. Report any additional cases immediately\n\n" .
                   "This is an automated alert triggered by the surveillance system.";
                   
        case '24_hour_threshold':
            return "URGENT AUTOMATIC ALERT: Rapid dengue case increase in $barangayName\n\n" .
                   "🚨 RAPID CASE INCREASE DETECTED\n" .
                   "• Cases in last 24 hours: $caseCount\n" .
                   "• Alert threshold: $threshold cases\n" .
                   "• Status: POTENTIAL OUTBREAK\n\n" .
                   "IMMEDIATE EMERGENCY RESPONSE REQUIRED:\n" .
                   "1. Activate emergency dengue response protocol\n" .
                   "2. Contact health department immediately\n" .
                   "3. Implement intensive mosquito control measures\n" .
                   "4. Conduct active case finding in the area\n" .
                   "5. Set up temporary monitoring station if needed\n\n" .
                   "This rapid increase requires immediate attention.";
                   
        case 'severe_threshold':
            return "CRITICAL AUTOMATIC ALERT: Severe dengue cases detected in $barangayName\n\n" .
                   "🚨 SEVERE CASES THRESHOLD EXCEEDED\n" .
                   "• Severe/Critical cases: $caseCount\n" .
                   "• Alert threshold: $threshold cases\n" .
                   "• Status: HIGH SEVERITY SITUATION\n\n" .
                   "CRITICAL ACTIONS REQUIRED:\n" .
                   "1. Ensure all severe cases are hospitalized\n" .
                   "2. Contact medical facilities about capacity\n" .
                   "3. Implement maximum intensity vector control\n" .
                   "4. Conduct emergency community health education\n" .
                   "5. Coordinate with provincial health office\n\n" .
                   "Multiple severe cases indicate a serious outbreak situation.";
                   
        default:
            return "Automatic dengue alert for $barangayName. Case count: $caseCount (threshold: $threshold)";
    }
}

// Determine urgency level based on alert type and case count
function determineUrgency($alertType, $caseCount, $threshold) {
    $ratio = $caseCount / $threshold;
    
    switch ($alertType) {
        case '24_hour_threshold':
        case 'severe_threshold':
            return 'CRITICAL';
            
        case '7_day_threshold':
            if ($ratio >= 2) return 'HIGH';
            if ($ratio >= 1.5) return 'MEDIUM';
            return 'NORMAL';
            
        default:
            return 'MEDIUM';
    }
}

// Send automatic email (reuse existing email functions)
function sendAutomaticEmail($official, $message, $urgency, $type) {
    try {
        // Load the SimpleSMTP2GO class if available
        if (file_exists(__DIR__ . '/includes/simple_smtp2go.php')) {
            require_once __DIR__ . '/includes/simple_smtp2go.php';
        }
        
        // Try SimpleSMTP2GO first, fallback to basic email
        if (class_exists('SimpleSMTP2GO')) {
            return sendSimpleAutomaticAlert($official, $message, $urgency, $type);
        } else {
            return sendBasicAutomaticEmail($official, $message, $urgency, $type);
        }
    } catch (Exception $e) {
        logMessage("ERROR: Failed to send automatic email: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Send via SimpleSMTP2GO
function sendSimpleAutomaticAlert($official, $message, $urgency, $type) {
    try {
        $client = new SimpleSMTP2GO(SMTP2GO_API_KEY, SMTP2GO_API_REGION);
        
        $subject = "[AUTO-ALERT - $urgency] Dengue Threshold Exceeded - " . $official['barangay_name'];
        $htmlBody = generateAutomaticEmailHTML($official, $message, $urgency, $type);
        $textBody = $message;
        
        $result = $client->sendEmail(
            SMTP2GO_FROM_EMAIL,
            SMTP2GO_FROM_NAME,
            $official['email'],
            $official['name'],
            $subject,
            $htmlBody,
            $textBody
        );
        
        return $result;
    } catch (Exception $e) {
        logMessage("ERROR: SimpleSMTP2GO failed: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Fallback email function
function sendBasicAutomaticEmail($official, $message, $urgency, $type) {
    $subject = "[AUTO-ALERT - $urgency] Dengue Threshold Exceeded - " . $official['barangay_name'];
    $body = $message;
    
    $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . SMTP_REPLY_TO . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    $success = mail($official['email'], $subject, $body, $headers);
    
    return [
        'success' => $success,
        'message' => $success ? 'Email sent successfully' : 'Failed to send email'
    ];
}

// Generate HTML email for automatic alerts
function generateAutomaticEmailHTML($official, $message, $urgency, $type) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; }
            .alert-badge { background: #ff6b6b; color: white; padding: 8px 16px; border-radius: 20px; display: inline-block; font-weight: bold; margin-bottom: 15px; }
            .info-box { background: white; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #007bff; }
            .footer { text-align: center; margin-top: 20px; color: #6c757d; font-size: 12px; }
            .automatic-notice { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🤖 AUTOMATIC DENGUE ALERT</h1>
                <p>Automated Surveillance System Notification</p>
            </div>
            
            <div class='content'>
                <div class='alert-badge'>$urgency PRIORITY - AUTOMATED</div>
                
                <div class='automatic-notice'>
                    <strong>⚙️ AUTOMATIC DETECTION:</strong> This alert was automatically generated by the dengue surveillance system based on real-time case monitoring.
                </div>
                
                <h2>Alert for " . htmlspecialchars($official['barangay_name']) . "</h2>
                
                <div class='info-box'>
                    <strong>Recipient:</strong> " . htmlspecialchars($official['name']) . "<br>
                    <strong>Position:</strong> " . htmlspecialchars($official['position']) . "<br>
                    <strong>Barangay:</strong> " . htmlspecialchars($official['barangay_name']) . "<br>
                    <strong>Alert Type:</strong> Automatic Threshold Alert<br>
                    <strong>Detection Time:</strong> " . date('F j, Y - g:i A') . "
                </div>
                
                <div class='info-box'>
                    <h3>📊 THRESHOLD BREACH DETECTED</h3>
                    <p>" . nl2br(htmlspecialchars($message)) . "</p>
                </div>
                
                <div class='info-box'>
                    <h4>📞 Emergency Contacts:</h4>
                    <p>
                        📞 Health Department Hotline: <strong>" . EMERGENCY_HOTLINE . "</strong><br>
                        📧 Email: <strong>" . EMERGENCY_EMAIL . "</strong><br>
                        🏥 Emergency Services: <strong>911</strong>
                    </p>
                </div>
            </div>
            
            <div class='footer'>
                <p><strong>🤖 AUTOMATED SYSTEM ALERT</strong><br>
                This message was automatically generated by the ASCLEPIUS Dengue Surveillance System.<br>
                System monitors case data 24/7 and sends alerts when thresholds are exceeded.<br>
                Do not reply to this email. Contact the Health Department directly for assistance.</p>
                <p><small>Powered by Advanced Dengue Monitoring Platform</small></p>
            </div>
        </div>
    </body>
    </html>
    ";
}

// Log alert to history table
function logAlertHistory($barangayId, $alertType, $caseCount, $threshold, $officialsNotified) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            INSERT INTO alert_history (barangay_id, alert_type, case_count, threshold_value, officials_notified)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$barangayId, $alertType, $caseCount, $threshold, $officialsNotified]);
        return true;
    } catch (PDOException $e) {
        logMessage("ERROR: Failed to log alert history: " . $e->getMessage());
        return false;
    }
}

// Logging function
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/logs/automatic_alerts.log';
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // Append to log file
    file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    // Also output to console for debugging
    echo "[$timestamp] $message" . PHP_EOL;
}

// Main execution function
function runAutomaticMonitoring() {
    logMessage("INFO: Starting automatic alert monitoring...");
    
    // Create alert history table
    if (!createAlertHistoryTable()) {
        logMessage("ERROR: Failed to create alert history table");
        return;
    }
    
    // Get alert settings
    $settings = getAlertSettings();
    if (!$settings) {
        logMessage("ERROR: Could not retrieve alert settings");
        return;
    }
    
    // Check if automatic emails are enabled
    if ($settings['auto_email_enabled'] != '1') {
        logMessage("INFO: Automatic email alerts are disabled in settings");
        return;
    }
    
    $frequencyHours = (int)$settings['email_frequency_hours'];
    $totalAlerts = 0;
    
    // Check 7-day threshold
    $alerts7Day = check7DayThreshold((int)$settings['case_threshold_7days'], $frequencyHours);
    $totalAlerts += $alerts7Day;
    logMessage("INFO: 7-day threshold check completed: $alerts7Day alerts sent");
    
    // Check 24-hour threshold
    $alerts24Hour = check24HourThreshold((int)$settings['case_threshold_24hours'], $frequencyHours);
    $totalAlerts += $alerts24Hour;
    logMessage("INFO: 24-hour threshold check completed: $alerts24Hour alerts sent");
    
    // Check severe case threshold
    $alertsSevere = checkSevereThreshold((int)$settings['severe_case_threshold'], $frequencyHours);
    $totalAlerts += $alertsSevere;
    logMessage("INFO: Severe case threshold check completed: $alertsSevere alerts sent");
    
    logMessage("INFO: Automatic monitoring completed. Total alerts sent: $totalAlerts");
}

// Run the monitoring
runAutomaticMonitoring();
?>