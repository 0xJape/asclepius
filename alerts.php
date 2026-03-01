<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/smtp_config.php';
require_once 'includes/simple_smtp2go.php';

// Verify user is logged in
checkAuth();

// Get dashboard stats for sidebar
$statsData = getDashboardStats();

// Handle form submissions
if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'get_official':
                // AJAX request to get official data
                $official = getOfficialById($_POST['official_id']);
                header('Content-Type: application/json');
                if ($official) {
                    echo json_encode(['success' => true, 'official' => $official]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Official not found']);
                }
                exit;
            
            case 'send_manual_alert':
                $result = sendManualAlert($_POST);
                if ($result['success']) {
                    $_SESSION['success'] = $result['message'];
                } else {
                    $_SESSION['error'] = $result['message'];
                }
                break;
            
            case 'update_threshold':
                $result = updateAlertThreshold($_POST);
                if ($result['success']) {
                    $_SESSION['success'] = $result['message'];
                } else {
                    $_SESSION['error'] = $result['message'];
                }
                break;
            
            case 'add_official':
                $result = addBarangayOfficial($_POST);
                if ($result['success']) {
                    $_SESSION['success'] = $result['message'];
                } else {
                    $_SESSION['error'] = $result['message'];
                }
                break;
                
            case 'edit_official':
                $result = editBarangayOfficial($_POST);
                if ($result['success']) {
                    $_SESSION['success'] = $result['message'];
                } else {
                    $_SESSION['error'] = $result['message'];
                }
                break;
                
            case 'delete_official':
                $result = deleteBarangayOfficial($_POST['official_id']);
                if ($result['success']) {
                    $_SESSION['success'] = $result['message'];
                } else {
                    $_SESSION['error'] = $result['message'];
                }
                break;
        }
        header('Location: alerts.php');
        exit;
    }
}

// Get alert settings
function getAlertSettings() {
    $db = getDBConnection();
    try {
        $stmt = $db->query("SELECT * FROM alert_settings ORDER BY setting_name");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_name']] = $row['setting_value'];
        }
        
        // Default values if not set
        $defaults = [
            'case_threshold_7days' => '5',
            'case_threshold_24hours' => '3',
            'severe_case_threshold' => '2',
            'auto_email_enabled' => '1',
            'email_frequency_hours' => '6'
        ];
        
        return array_merge($defaults, $settings);
    } catch (PDOException $e) {
        return [
            'case_threshold_7days' => '5',
            'case_threshold_24hours' => '3',
            'severe_case_threshold' => '2',
            'auto_email_enabled' => '1',
            'email_frequency_hours' => '6'
        ];
    }
}

// Get barangay officials
function getBarangayOfficials() {
    $db = getDBConnection();
    try {
        $stmt = $db->query("
            SELECT bo.*, b.name as barangay_name
            FROM barangay_officials bo
            JOIN barangays b ON bo.barangay_id = b.barangay_id
            ORDER BY b.name, bo.position
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Send manual alert
function sendManualAlert($data) {
    $barangay_id = $data['barangay_id'];
    $message = $data['message'];
    $urgency = $data['urgency'];
    
    try {
        $db = getDBConnection();
        
        // Get barangay officials
        $stmt = $db->prepare("
            SELECT bo.*, b.name as barangay_name
            FROM barangay_officials bo
            JOIN barangays b ON bo.barangay_id = b.barangay_id
            WHERE bo.barangay_id = ? AND bo.email IS NOT NULL
        ");
        $stmt->execute([$barangay_id]);
        $officials = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($officials)) {
            return ['success' => false, 'message' => 'No email contacts found for this barangay'];
        }
        
        $sent_count = 0;
        foreach ($officials as $official) {
            if (sendEmailAlert($official, $message, $urgency, 'manual')) {
                $sent_count++;
            }
        }
        
        // Log the alert
        logAlert($barangay_id, 'manual', $sent_count, $message);
        
        return [
            'success' => true, 
            'message' => "Manual alert sent to $sent_count recipient(s)"
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error sending alert: ' . $e->getMessage()];
    }
}

// Update alert threshold
function updateAlertThreshold($data) {
    try {
        $db = getDBConnection();
        
        // Create alert_settings table if it doesn't exist
        $db->exec("
            CREATE TABLE IF NOT EXISTS alert_settings (
                setting_id INT PRIMARY KEY AUTO_INCREMENT,
                setting_name VARCHAR(50) UNIQUE NOT NULL,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        
        $settings = [
            'case_threshold_7days' => $data['case_threshold_7days'],
            'case_threshold_24hours' => $data['case_threshold_24hours'],
            'severe_case_threshold' => $data['severe_case_threshold'],
            'auto_email_enabled' => isset($data['auto_email_enabled']) ? '1' : '0',
            'email_frequency_hours' => $data['email_frequency_hours']
        ];
        
        foreach ($settings as $name => $value) {
            $stmt = $db->prepare("
                INSERT INTO alert_settings (setting_name, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$name, $value, $value]);
        }
        
        return ['success' => true, 'message' => 'Alert thresholds updated successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error updating settings: ' . $e->getMessage()];
    }
}

// Add barangay official
function addBarangayOfficial($data) {
    try {
        $db = getDBConnection();
        
        // Create barangay_officials table if it doesn't exist
        $db->exec("
            CREATE TABLE IF NOT EXISTS barangay_officials (
                official_id INT PRIMARY KEY AUTO_INCREMENT,
                barangay_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                position VARCHAR(50) NOT NULL,
                email VARCHAR(100),
                phone VARCHAR(20),
                is_primary BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id) ON DELETE CASCADE
            )
        ");
        
        $stmt = $db->prepare("
            INSERT INTO barangay_officials (barangay_id, name, position, email, phone, is_primary) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $is_primary = isset($data['is_primary']) ? 1 : 0;
        $stmt->execute([
            $data['barangay_id'],
            $data['name'],
            $data['position'],
            $data['email'],
            $data['phone'],
            $is_primary
        ]);
        
        return ['success' => true, 'message' => 'Barangay official added successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error adding official: ' . $e->getMessage()];
    }
}

// Edit barangay official
function editBarangayOfficial($data) {
    try {
        $db = getDBConnection();
        
        $is_primary = isset($data['is_primary']) ? 1 : 0;
        
        $stmt = $db->prepare("
            UPDATE barangay_officials 
            SET barangay_id = ?, name = ?, position = ?, email = ?, phone = ?, is_primary = ?
            WHERE official_id = ?
        ");
        
        $stmt->execute([
            $data['barangay_id'],
            $data['name'],
            $data['position'],
            $data['email'],
            $data['phone'],
            $is_primary,
            $data['official_id']
        ]);
        
        return ['success' => true, 'message' => 'Barangay official updated successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error updating official: ' . $e->getMessage()];
    }
}

// Delete barangay official
function deleteBarangayOfficial($official_id) {
    try {
        $db = getDBConnection();
        
        $stmt = $db->prepare("DELETE FROM barangay_officials WHERE official_id = ?");
        $stmt->execute([$official_id]);
        
        if ($stmt->rowCount() > 0) {
            return ['success' => true, 'message' => 'Barangay official deleted successfully'];
        } else {
            return ['success' => false, 'message' => 'Official not found'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error deleting official: ' . $e->getMessage()];
    }
}

// Get single official for editing
function getOfficialById($official_id) {
    try {
        $db = getDBConnection();
        
        $stmt = $db->prepare("
            SELECT bo.*, b.name as barangay_name 
            FROM barangay_officials bo
            JOIN barangays b ON bo.barangay_id = b.barangay_id
            WHERE bo.official_id = ?
        ");
        
        $stmt->execute([$official_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return null;
    }
}

// Send email alert using SimpleSMTP2GO (dependency-free)
function sendEmailAlert($official, $message, $urgency, $type) {
    try {
        // First try SimpleSMTP2GO API
        if (class_exists('SimpleSMTP2GO')) {
            return sendSimpleDengueAlert($official, $message, $urgency, $type);
        } else {
            error_log("SimpleSMTP2GO class not available, using fallback email method");
            return sendBasicEmail($official, $message, $urgency, $type);
        }
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return sendBasicEmail($official, $message, $urgency, $type);
    }
}

// Fallback email function using basic mail()
function sendBasicEmail($official, $message, $urgency, $type) {
    $subject = "[DENGUE ALERT - $urgency] " . $official['barangay_name'];
    $body = generateEmailText($official, $message, $urgency, $type);
    
    $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . SMTP_REPLY_TO . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    $success = mail($official['email'], $subject, $body, $headers);
    
    if ($success) {
        error_log("Basic Mail: Email sent successfully to {$official['email']}");
    } else {
        error_log("Basic Mail: Failed to send email to {$official['email']}");
    }
    
    return $success;
}

// Generate HTML email content
function generateEmailHTML($official, $message, $urgency, $type) {
    $urgency_color = match($urgency) {
        'CRITICAL' => '#dc3545',
        'HIGH' => '#fd7e14',
        'MEDIUM' => '#ffc107',
        'LOW' => '#28a745',
        default => '#6c757d'
    };
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .alert-badge { background: $urgency_color; color: white; padding: 8px 16px; border-radius: 20px; font-weight: bold; display: inline-block; margin: 10px 0; }
            .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 8px 8px; }
            .info-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid $urgency_color; border-radius: 4px; }
            .footer { text-align: center; margin-top: 20px; color: #6c757d; font-size: 12px; }
            .action-required { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1><i>🦟</i> DENGUE ALERT SYSTEM</h1>
                <p>Official Health Department Notification</p>
            </div>
            
            <div class='content'>
                <div class='alert-badge'>$urgency PRIORITY</div>
                
                <h2>Alert for " . htmlspecialchars($official['barangay_name']) . "</h2>
                
                <div class='info-box'>
                    <strong>Recipient:</strong> " . htmlspecialchars($official['name']) . "<br>
                    <strong>Position:</strong> " . htmlspecialchars($official['position']) . "<br>
                    <strong>Barangay:</strong> " . htmlspecialchars($official['barangay_name']) . "<br>
                    <strong>Alert Type:</strong> " . ucfirst($type) . "<br>
                    <strong>Time:</strong> " . date('F j, Y - g:i A') . "
                </div>
                
                <div class='action-required'>
                    <h3>🚨 IMMEDIATE ACTION REQUIRED</h3>
                    <p>" . nl2br(htmlspecialchars($message)) . "</p>
                </div>
                
                <div class='info-box'>
                    <h4>Recommended Actions:</h4>
                    <ul>
                        <li>Coordinate with local health workers immediately</li>
                        <li>Implement mosquito control measures</li>
                        <li>Educate residents about dengue prevention</li>
                        <li>Report back to health department within 24 hours</li>
                        <li>Monitor and document any new cases</li>
                    </ul>
                </div>
                
                <div class='info-box'>
                    <h4>Emergency Contacts:</h4>
                    <p>
                        📞 Health Department Hotline: <strong>123-456-7890</strong><br>
                        📧 Email: <strong>emergency@health.gov.ph</strong><br>
                        🏥 Emergency Services: <strong>911</strong>
                    </p>
                </div>
            </div>
            
            <div class='footer'>
                <p>This is an automated message from the Dengue Early Warning System.<br>
                Please do not reply to this email. For questions, contact the Health Department directly.</p>
                <p><small>System powered by Advanced Dengue Monitoring Platform</small></p>
            </div>
        </div>
    </body>
    </html>
    ";
}

// Generate plain text email content
function generateEmailText($official, $message, $urgency, $type) {
    return "
===========================================
🦟 DENGUE ALERT SYSTEM - OFFICIAL NOTICE
===========================================

URGENCY LEVEL: $urgency
ALERT TYPE: " . ucfirst($type) . "
DATE/TIME: " . date('F j, Y - g:i A') . "

-------------------------------------------
RECIPIENT INFORMATION
-------------------------------------------
Name: " . $official['name'] . "
Position: " . $official['position'] . "
Barangay: " . $official['barangay_name'] . "

-------------------------------------------
⚠️  ALERT MESSAGE
-------------------------------------------
$message

-------------------------------------------
🚨 IMMEDIATE ACTIONS REQUIRED
-------------------------------------------
1. Coordinate with local health workers immediately
2. Implement mosquito control measures in affected areas
3. Educate residents about dengue prevention methods
4. Report back to health department within 24 hours
5. Monitor and document any new suspected cases

-------------------------------------------
📞 EMERGENCY CONTACTS
-------------------------------------------
Health Department Hotline: 123-456-7890
Email: emergency@health.gov.ph
Emergency Services: 911

-------------------------------------------
IMPORTANT NOTICE
-------------------------------------------
This is an automated alert from the Dengue Early Warning System.
Please take immediate action to prevent disease spread.
Do not reply to this email. Contact the Health Department directly for assistance.

System powered by Advanced Dengue Monitoring Platform
===========================================
    ";
}

// Log alert
function logAlert($barangay_id, $type, $recipient_count, $message) {
    try {
        $db = getDBConnection();
        
        // Create alert_log table if it doesn't exist
        $db->exec("
            CREATE TABLE IF NOT EXISTS alert_log (
                log_id INT PRIMARY KEY AUTO_INCREMENT,
                barangay_id INT NOT NULL,
                alert_type VARCHAR(20) NOT NULL,
                recipient_count INT DEFAULT 0,
                message TEXT,
                alert_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id) ON DELETE CASCADE
            )
        ");
        
        $stmt = $db->prepare("
            INSERT INTO alert_log (barangay_id, alert_type, recipient_count, message) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$barangay_id, $type, $recipient_count, $message]);
        
    } catch (Exception $e) {
        error_log("Error logging alert: " . $e->getMessage());
    }
}

// Send email using SimpleSMTP2GO (our custom implementation)
function sendSimpleDengueAlert($official, $message, $urgency, $type) {
    try {
        // Initialize SimpleSMTP2GO using the same approach as working test
        $client = new SimpleSMTP2GO(SMTP2GO_API_KEY, SMTP2GO_API_REGION);
        
        // Use the SMTP config constants like the working implementation
        $fromEmail = SMTP2GO_FROM_EMAIL;
        $fromName = SMTP2GO_FROM_NAME;
        
        // Prepare email content
        $subject = "[DENGUE ALERT - $urgency] " . $official['barangay_name'];
        $htmlBody = generateEmailHTML($official, $message, $urgency, $type);
        $textBody = generateEmailText($official, $message, $urgency, $type);
        
        // Send email using the same method as working test
        $result = $client->sendEmail(
            $fromEmail,
            $fromName,
            $official['email'],
            $official['name'],
            $subject,
            $htmlBody,
            $textBody
        );
        
        if ($result['success']) {
            error_log("SMTP2GO: Email sent successfully to {$official['email']}");
            return true;
        } else {
            $error = $result['error'] ?? 'Unknown error';
            error_log("SMTP2GO: Failed to send email to {$official['email']} - " . $error);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("SMTP2GO Exception: " . $e->getMessage());
        return false;
    }
}

$alertSettings = getAlertSettings();
$currentAlerts = getActiveAlerts(); // Use the same function as dashboard
$barangayOfficials = getBarangayOfficials();

// Get barangays for dropdowns
$db = getDBConnection();
$stmt = $db->query("SELECT barangay_id, name FROM barangays ORDER BY name");
$barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Alert Management - Dengue Early Warning System</title>
    
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
        .alert-card {
            border-left: 4px solid;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .alert-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .alert-card.critical {
            border-left-color: #dc3545;
            background: linear-gradient(135deg, #fff5f5 0%, #fff 100%);
        }
        
        .alert-card.high {
            border-left-color: #fd7e14;
            background: linear-gradient(135deg, #fff8f5 0%, #fff 100%);
        }
        
        .alert-card.medium {
            border-left-color: #ffc107;
            background: linear-gradient(135deg, #fffbf5 0%, #fff 100%);
        }
        
        .alert-card.low {
            border-left-color: #28a745;
            background: linear-gradient(135deg, #f5fff7 0%, #fff 100%);
        }
        
        .alert-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .case-stats {
            background: rgba(248, 249, 250, 0.5);
            border-radius: 8px;
            padding: 12px;
        }
        
        .case-stats .border-end:last-child {
            border-right: none !important;
        }
        
        .alert-message {
            background: rgba(255, 255, 255, 0.7);
            border-radius: 6px;
            padding: 10px;
            border-left: 3px solid #e9ecef;
        }
        
        .settings-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
        }
        
        .official-card {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            transition: box-shadow 0.3s ease;
            min-height: 200px;
        }
        
        .official-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .official-card .btn-group {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }
        
        .official-card .btn-sm {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .case-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .trend-arrow {
            font-size: 0.875rem;
        }
        
        .blinking-dot {
            animation: blink 1.5s infinite;
        }
        
        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0.3; }
        }
        
        /* Enhanced button styles */
        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }
        
        .btn.loading .fas {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
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
                <a href="analytics.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Analytics</span>
                </a>
                <a href="prediction.php" class="menu-item">
                    <i class="fas fa-brain"></i>
                    <span>Risk Prediction</span>
                </a>
                <a href="alerts.php" class="menu-item active">
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
                    <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
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
                <h1 class="page-title mb-0">Alert Management</h1>
                <small class="text-muted">Monitor dengue outbreak alerts and notify barangay officials</small>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#manualAlertModal">
                    <i class="fas fa-paper-plane me-1"></i> Send Manual Alert
                </button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#settingsModal">
                    <i class="fas fa-cogs me-1"></i> Alert Settings
                </button>
            </div>
        </header>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Current Alert Summary -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6 text-primary mb-2">
                            <?php 
                            $totalPatients = 0;
                            try {
                                $stmt = $db->query("SELECT COUNT(*) as count FROM patients");
                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                $totalPatients = $result['count'];
                            } catch (Exception $e) {
                                error_log("Error getting total patients: " . $e->getMessage());
                            }
                            echo $totalPatients;
                            ?>
                        </div>
                        <h6 class="text-muted mb-0">Total Patients</h6>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6 text-warning mb-2">
                            <?php 
                            $recentCases = 0;
                            try {
                                $stmt = $db->query("
                                    SELECT COUNT(*) as count 
                                    FROM patient_cases 
                                    WHERE date_reported >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                                ");
                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                $recentCases = $result['count'];
                            } catch (Exception $e) {
                                error_log("Error getting recent cases: " . $e->getMessage());
                            }
                            echo $recentCases;
                            ?>
                        </div>
                        <h6 class="text-muted mb-0">Cases (7 Days)</h6>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6 text-danger mb-2">
                            <?php 
                            $severeCases = 0;
                            try {
                                $stmt = $db->query("
                                    SELECT COUNT(*) as count 
                                    FROM patient_cases 
                                    WHERE status IN ('Severe', 'Critical') 
                                    AND date_reported >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                ");
                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                $severeCases = $result['count'];
                            } catch (Exception $e) {
                                error_log("Error getting severe cases: " . $e->getMessage());
                            }
                            echo $severeCases;
                            ?>
                        </div>
                        <h6 class="text-muted mb-0">Severe Cases</h6>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6 text-success mb-2">
                            <?php 
                            $totalAlerts = 0;
                            if (isset($currentAlerts['priority'])) {
                                $totalAlerts = count($currentAlerts['priority']);
                            }
                            echo $totalAlerts;
                            ?>
                        </div>
                        <h6 class="text-muted mb-0">Active Alerts</h6>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Alerts -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Barangays Under Risk
                </h5>
                <small class="text-muted">Auto-refresh every 5 minutes</small>
            </div>
            <div class="card-body">
                <?php if (isset($currentAlerts['priority']) && count($currentAlerts['priority']) > 0): ?>
                <div class="row g-3">
                    <?php foreach ($currentAlerts['priority'] as $alert): ?>
                    <?php
                    // Get detailed case statistics for this barangay
                    $barangayStats = [];
                    try {
                        // Get total cases for this barangay
                        $stmt = $db->prepare("
                            SELECT COUNT(*) as total_cases
                            FROM patient_cases pc
                            JOIN patients p ON pc.patient_id = p.patient_id
                            WHERE p.barangay_id = ?
                        ");
                        $stmt->execute([$alert['id']]);
                        $barangayStats['total_cases'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_cases'];
                        
                        // Get 7-day cases for this barangay
                        $stmt = $db->prepare("
                            SELECT COUNT(*) as recent_cases
                            FROM patient_cases pc
                            JOIN patients p ON pc.patient_id = p.patient_id
                            WHERE p.barangay_id = ? 
                            AND pc.date_reported >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        ");
                        $stmt->execute([$alert['id']]);
                        $barangayStats['recent_cases'] = $stmt->fetch(PDO::FETCH_ASSOC)['recent_cases'];
                        
                        // Get severe/critical cases for this barangay
                        $stmt = $db->prepare("
                            SELECT COUNT(*) as severe_cases
                            FROM patient_cases pc
                            JOIN patients p ON pc.patient_id = p.patient_id
                            WHERE p.barangay_id = ? 
                            AND pc.status IN ('Severe', 'Critical')
                            AND pc.date_reported >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        ");
                        $stmt->execute([$alert['id']]);
                        $barangayStats['severe_cases'] = $stmt->fetch(PDO::FETCH_ASSOC)['severe_cases'];
                        
                    } catch (Exception $e) {
                        error_log("Error getting barangay stats: " . $e->getMessage());
                        $barangayStats = [
                            'total_cases' => 0,
                            'recent_cases' => 0,
                            'severe_cases' => 0
                        ];
                    }
                    ?>
                    <div class="col-lg-6 col-xl-4">
                        <div class="card alert-card <?php echo strtolower($alert['risk_level']); ?> h-100">
                            <div class="card-body">
                                <!-- Header with status -->
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="alert-icon bg-<?php echo $alert['risk_color']; ?> bg-opacity-10 text-<?php echo $alert['risk_color']; ?> rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                            <?php if ($alert['risk_level'] === 'CRITICAL'): ?>
                                            <i class="fas fa-exclamation-circle blinking-dot"></i>
                                            <?php elseif ($alert['risk_level'] === 'HIGH'): ?>
                                            <i class="fas fa-exclamation-triangle blinking-dot"></i>
                                            <?php else: ?>
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($alert['barangay']); ?></h6>
                                            <small class="text-muted"><?php echo $alert['time_ago']; ?></small>
                                        </div>
                                    </div>
                                    <span class="badge bg-<?php echo $alert['risk_color']; ?> text-white alert-badge">
                                        <?php echo $alert['risk_level']; ?>
                                    </span>
                                </div>
                                
                                <!-- Alert Message -->
                                <div class="alert-message mb-3">
                                    <p class="mb-0 text-dark" style="font-size: 0.9rem; line-height: 1.4;">
                                        <?php echo htmlspecialchars($alert['message']); ?>
                                    </p>
                                </div>
                                
                                <!-- Case Statistics -->
                                <div class="case-stats mb-3">
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div class="border-end">
                                                <div class="h6 mb-0 text-danger">
                                                    <?php echo $barangayStats['total_cases']; ?>
                                                </div>
                                                <small class="text-muted">Total Cases</small>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="border-end">
                                                <div class="h6 mb-0 text-warning">
                                                    <?php echo $barangayStats['recent_cases']; ?>
                                                </div>
                                                <small class="text-muted">7 Days</small>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="h6 mb-0 text-primary">
                                                <?php echo $barangayStats['severe_cases']; ?>
                                            </div>
                                            <small class="text-muted">Severe</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="d-grid gap-2">
                                    <button class="btn btn-<?php echo $alert['risk_color']; ?> btn-sm" 
                                            onclick="sendQuickAlert(<?php echo $alert['id']; ?>, '<?php echo htmlspecialchars($alert['barangay'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-paper-plane me-1"></i> Send Alert
                                    </button>
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-outline-secondary btn-sm flex-fill" 
                                                onclick="viewDetails(<?php echo $alert['id']; ?>)">
                                            <i class="fas fa-eye me-1"></i> Details
                                        </button>
                                        <button class="btn btn-outline-info btn-sm flex-fill" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#manualAlertModal"
                                                onclick="setTargetBarangay(<?php echo $alert['id']; ?>, '<?php echo htmlspecialchars($alert['barangay'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-edit me-1"></i> Custom
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Risk Level Indicator -->
                            <div class="card-footer bg-<?php echo $alert['risk_color']; ?> bg-opacity-10 border-0 py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-<?php echo $alert['risk_color']; ?> fw-medium">
                                        <i class="fas fa-shield-alt me-1"></i>
                                        Risk Level: <?php echo $alert['risk_level']; ?>
                                    </small>
                                    <?php if ($alert['risk_level'] === 'CRITICAL'): ?>
                                    <small class="text-danger">
                                        <i class="fas fa-clock blinking-dot"></i> Urgent
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-shield-alt fa-4x text-success mb-3"></i>
                    <h4 class="text-success mb-2">No Active Alerts</h4>
                    <p class="text-muted mb-0">All barangays are within normal dengue case thresholds.</p>
                    <small class="text-muted">Last checked: <?php echo date('F j, Y - g:i A'); ?></small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Barangay Officials Management -->
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-users me-2"></i>
                    Barangay Officials
                </h5>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addOfficialModal">
                    <i class="fas fa-plus me-1"></i> Add Official
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($barangayOfficials)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-user-plus fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Officials Registered</h5>
                    <p class="text-muted">Add barangay officials to receive automated dengue alerts.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOfficialModal">
                        <i class="fas fa-plus me-1"></i> Add First Official
                    </button>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($barangayOfficials as $official): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="official-card p-3">
                            <div class="d-flex align-items-start justify-content-between mb-2">
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($official['name']); ?></h6>
                                <?php if ($official['is_primary']): ?>
                                <span class="badge bg-primary badge-sm">Primary</span>
                                <?php endif; ?>
                            </div>
                            
                            <p class="text-muted mb-2"><?php echo htmlspecialchars($official['position']); ?></p>
                            <p class="fw-medium mb-2"><?php echo htmlspecialchars($official['barangay_name']); ?></p>
                            
                            <div class="mb-2">
                                <small class="text-muted d-block">Email</small>
                                <span class="fw-medium"><?php echo htmlspecialchars($official['email'] ?: 'Not provided'); ?></span>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted d-block">Phone</small>
                                <span class="fw-medium"><?php echo htmlspecialchars($official['phone'] ?: 'Not provided'); ?></span>
                            </div>
                            
                            <div class="d-flex flex-wrap gap-1">
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="editOfficial(<?php echo $official['official_id']; ?>)"
                                        title="Edit Official">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" 
                                        onclick="deleteOfficial(<?php echo $official['official_id']; ?>, '<?php echo htmlspecialchars($official['name'], ENT_QUOTES); ?>')"
                                        title="Delete Official">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php if ($official['email']): ?>
                                <button class="btn btn-sm btn-success" 
                                        onclick="testEmail('<?php echo htmlspecialchars($official['email']); ?>')"
                                        title="Send Test Email">
                                    <i class="fas fa-envelope"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Manual Alert Modal -->
<div class="modal fade" id="manualAlertModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Send Manual Alert</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="send_manual_alert">
                    
                    <div class="mb-3">
                        <label class="form-label">Barangay</label>
                        <select class="form-select" name="barangay_id" required>
                            <option value="">Select Barangay</option>
                            <?php foreach ($barangays as $barangay): ?>
                            <option value="<?php echo $barangay['barangay_id']; ?>">
                                <?php echo htmlspecialchars($barangay['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Urgency Level</label>
                        <select class="form-select" name="urgency" required>
                            <option value="CRITICAL">Critical - Immediate Action Required</option>
                            <option value="HIGH">High - Action Needed Today</option>
                            <option value="MEDIUM">Medium - Action Needed This Week</option>
                            <option value="LOW">Low - Informational</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Alert Message</label>
                        <textarea class="form-control" name="message" rows="5" required
                                placeholder="Enter your alert message for the barangay officials..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-paper-plane me-1"></i> Send Alert
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Alert Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_threshold">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">7-Day Case Threshold</label>
                            <input type="number" class="form-control" name="case_threshold_7days" 
                                   value="<?php echo $alertSettings['case_threshold_7days']; ?>" min="1" max="50" required>
                            <div class="form-text">Alert when cases in 7 days exceed this number</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">24-Hour Case Threshold</label>
                            <input type="number" class="form-control" name="case_threshold_24hours" 
                                   value="<?php echo $alertSettings['case_threshold_24hours']; ?>" min="1" max="20" required>
                            <div class="form-text">Alert when cases in 24 hours exceed this number</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Severe Case Threshold</label>
                            <input type="number" class="form-control" name="severe_case_threshold" 
                                   value="<?php echo $alertSettings['severe_case_threshold']; ?>" min="1" max="10" required>
                            <div class="form-text">Alert when severe/critical cases exceed this number</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Email Frequency (hours)</label>
                            <input type="number" class="form-control" name="email_frequency_hours" 
                                   value="<?php echo $alertSettings['email_frequency_hours']; ?>" min="1" max="24" required>
                            <div class="form-text">Minimum hours between automated emails</div>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="auto_email_enabled" 
                                       <?php echo $alertSettings['auto_email_enabled'] ? 'checked' : ''; ?>>
                                <label class="form-check-label">
                                    Enable Automated Email Alerts
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Official Modal -->
<div class="modal fade" id="addOfficialModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Barangay Official</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_official">
                    
                    <div class="mb-3">
                        <label class="form-label">Barangay</label>
                        <select class="form-select" name="barangay_id" required>
                            <option value="">Select Barangay</option>
                            <?php foreach ($barangays as $barangay): ?>
                            <option value="<?php echo $barangay['barangay_id']; ?>">
                                <?php echo htmlspecialchars($barangay['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Position</label>
                        <select class="form-select" name="position" required>
                            <option value="">Select Position</option>
                            <option value="Barangay Captain">Barangay Captain</option>
                            <option value="Barangay Secretary">Barangay Secretary</option>
                            <option value="Barangay Treasurer">Barangay Treasurer</option>
                            <option value="Barangay Health Worker">Barangay Health Worker</option>
                            <option value="Kagawad">Kagawad</option>
                            <option value="SK Chairman">SK Chairman</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email">
                        <div class="form-text">Required for automated alerts</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" name="phone">
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_primary">
                        <label class="form-check-label">
                            Primary Contact for this Barangay
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i> Add Official
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Official Modal -->
<div class="modal fade" id="editOfficialModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Barangay Official</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editOfficialForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_official">
                    <input type="hidden" name="official_id" id="edit_official_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Barangay</label>
                        <select class="form-select" name="barangay_id" id="edit_barangay_id" required>
                            <option value="">Select Barangay</option>
                            <?php foreach ($barangays as $barangay): ?>
                            <option value="<?php echo $barangay['barangay_id']; ?>">
                                <?php echo htmlspecialchars($barangay['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Position</label>
                        <select class="form-select" name="position" id="edit_position" required>
                            <option value="">Select Position</option>
                            <option value="Barangay Captain">Barangay Captain</option>
                            <option value="Barangay Secretary">Barangay Secretary</option>
                            <option value="Barangay Health Worker">Barangay Health Worker</option>
                            <option value="Kagawad">Kagawad</option>
                            <option value="SK Chairman">SK Chairman</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email" id="edit_email">
                        <div class="form-text">Required for automated alerts</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" name="phone" id="edit_phone">
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_primary" id="edit_is_primary">
                        <label class="form-check-label">
                            Primary Contact for this Barangay
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Auto-refresh alerts every 2 minutes for real-time detection (same as dashboard)
setInterval(function() {
    location.reload();
}, 120000);

// Real-time update function (matching dashboard functionality)
function updateAlertsDisplay() {
    fetch('alerts.php?ajax=1')
        .then(response => response.json())
        .then(data => {
            if (data.alerts) {
                // Update alert counts
                document.querySelector('.display-6.text-danger').textContent = data.critical_count || 0;
                document.querySelector('.display-6.text-warning').textContent = data.high_count || 0;
                document.querySelector('.display-6.text-info').textContent = data.medium_count || 0;
                document.querySelector('.display-6.text-success').textContent = data.total_count || 0;
                
                // Could update the alert list here if needed
                console.log('Alerts updated:', data);
            }
        })
        .catch(error => {
            console.error('Error updating alerts:', error);
        });
}

// Update alerts every minute for real-time monitoring
setInterval(updateAlertsDisplay, 60000);

// Quick alert function with actual form submission
function sendQuickAlert(barangayId, barangayName) {
    if (confirm(`Send quick dengue alert to ${barangayName} officials?`)) {
        // Show loading state
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending...';
        btn.disabled = true;
        btn.classList.add('loading');
        
        // Create and submit form for quick alert
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        // Add form fields
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'send_manual_alert';
        
        const barangayInput = document.createElement('input');
        barangayInput.type = 'hidden';
        barangayInput.name = 'barangay_id';
        barangayInput.value = barangayId;
        
        const messageInput = document.createElement('input');
        messageInput.type = 'hidden';
        messageInput.name = 'message';
        messageInput.value = `URGENT: Dengue outbreak alert for ${barangayName}. Immediate action required to implement mosquito control measures and monitor residents for symptoms. Please coordinate with local health workers and report back within 24 hours.`;
        
        const urgencyInput = document.createElement('input');
        urgencyInput.type = 'hidden';
        urgencyInput.name = 'urgency';
        urgencyInput.value = 'HIGH';
        
        // Append inputs to form
        form.appendChild(actionInput);
        form.appendChild(barangayInput);
        form.appendChild(messageInput);
        form.appendChild(urgencyInput);
        
        // Submit form
        document.body.appendChild(form);
        form.submit();
    }
}

// Set target barangay for manual alert modal
function setTargetBarangay(barangayId, barangayName) {
    // Set the barangay dropdown in the modal
    const barangaySelect = document.querySelector('#manualAlertModal select[name="barangay_id"]');
    if (barangaySelect) {
        barangaySelect.value = barangayId;
    }
    
    // Update modal title
    const modalTitle = document.querySelector('#manualAlertModal .modal-title');
    if (modalTitle) {
        modalTitle.textContent = `Send Alert to ${barangayName}`;
    }
    
    // Pre-fill with urgent message
    const messageTextarea = document.querySelector('#manualAlertModal textarea[name="message"]');
    if (messageTextarea && !messageTextarea.value.trim()) {
        messageTextarea.value = `URGENT DENGUE ALERT for ${barangayName}

Recent surveillance has detected an increase in dengue cases in your barangay that requires immediate attention.

IMMEDIATE ACTIONS REQUIRED:
1. Coordinate with barangay health workers to verify and monitor cases
2. Implement intensive mosquito control measures (search and destroy operations)
3. Conduct community education on dengue prevention and symptoms
4. Report any additional suspected cases to the health department immediately
5. Ensure proper case management for existing patients

Please acknowledge receipt of this alert and provide status update within 24 hours.

For assistance or questions, contact the Municipal Health Office.`;
    }
    
    // Set urgency to HIGH for quick alerts
    const urgencySelect = document.querySelector('#manualAlertModal select[name="urgency"]');
    if (urgencySelect) {
        urgencySelect.value = 'HIGH';
    }
}

function viewDetails(barangayId) {
    // Navigate to detailed view
    window.location.href = `analytics.php?barangay=${barangayId}`;
}

function editOfficial(officialId) {
    // Show loading state
    const editButton = event.target.closest('button');
    const originalContent = editButton.innerHTML;
    editButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    editButton.disabled = true;
    
    // Fetch official data and populate edit modal
    fetch('alerts.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_official&official_id=${officialId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.official) {
            const official = data.official;
            
            // Populate edit form
            document.getElementById('edit_official_id').value = official.official_id;
            document.getElementById('edit_barangay_id').value = official.barangay_id;
            document.getElementById('edit_name').value = official.name;
            document.getElementById('edit_position').value = official.position;
            document.getElementById('edit_email').value = official.email || '';
            document.getElementById('edit_phone').value = official.phone || '';
            document.getElementById('edit_is_primary').checked = official.is_primary == 1;
            
            // Show modal
            new bootstrap.Modal(document.getElementById('editOfficialModal')).show();
        } else {
            alert('Error loading official data: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error loading official data. Please try again.');
    })
    .finally(() => {
        // Restore button state
        editButton.innerHTML = originalContent;
        editButton.disabled = false;
    });
}

function deleteOfficial(officialId, officialName) {
    // Create a Bootstrap confirmation modal
    const confirmModal = `
        <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Delete</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete <strong>${officialName}</strong>?</p>
                        <p class="text-danger small">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" onclick="confirmDelete(${officialId})">
                            <i class="fas fa-trash me-1"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if present
    const existingModal = document.getElementById('deleteConfirmModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', confirmModal);
    
    // Show modal
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}

function confirmDelete(officialId) {
    // Hide modal
    bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal')).hide();
    
    // Create and submit delete form
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'delete_official';
    
    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'official_id';
    idInput.value = officialId;
    
    form.appendChild(actionInput);
    form.appendChild(idInput);
    document.body.appendChild(form);
    
    form.submit();
}

function testEmail(email) {
    if (confirm(`Send test email to ${email}?`)) {
        // Show loading
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Testing...';
        btn.disabled = true;
        
        // Create test email form
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'send_manual_alert';
        
        const emailInput = document.createElement('input');
        emailInput.type = 'hidden';
        emailInput.name = 'test_email';
        emailInput.value = email;
        
        const messageInput = document.createElement('input');
        messageInput.type = 'hidden';
        messageInput.name = 'message';
        messageInput.value = 'This is a test email from the Dengue Early Warning System. If you receive this message, email notifications are working correctly.';
        
        const urgencyInput = document.createElement('input');
        urgencyInput.type = 'hidden';
        urgencyInput.name = 'urgency';
        urgencyInput.value = 'LOW';
        
        form.appendChild(actionInput);
        form.appendChild(emailInput);
        form.appendChild(messageInput);
        form.appendChild(urgencyInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Enhanced form validation for manual alerts
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Add visual indicator for real-time monitoring
    const header = document.querySelector('.content-header h1');
    if (header) {
        const indicator = document.createElement('span');
        indicator.className = 'badge bg-success ms-2';
        indicator.innerHTML = '<i class="fas fa-circle" style="animation: blink 2s infinite;"></i> LIVE';
        indicator.style.fontSize = '0.6rem';
        header.appendChild(indicator);
    }
    
    // Enhanced manual alert form validation
    const manualAlertForm = document.querySelector('#manualAlertModal form');
    if (manualAlertForm) {
        manualAlertForm.addEventListener('submit', function(e) {
            const message = this.querySelector('textarea[name="message"]').value.trim();
            const barangay = this.querySelector('select[name="barangay_id"]').value;
            const urgency = this.querySelector('select[name="urgency"]').value;
            
            if (!message || !barangay || !urgency) {
                e.preventDefault();
                alert('Please fill in all required fields');
                return false;
            }
            
            if (message.length < 10) {
                e.preventDefault();
                alert('Alert message must be at least 10 characters long');
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending Alert...';
            submitBtn.disabled = true;
            
            return true;
        });
    }
    
    // Auto-resize textarea in manual alert modal
    const alertTextarea = document.querySelector('#manualAlertModal textarea[name="message"]');
    if (alertTextarea) {
        alertTextarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    }
    
    // Character counter for alert message
    if (alertTextarea) {
        const counterDiv = document.createElement('div');
        counterDiv.className = 'form-text text-end';
        counterDiv.id = 'messageCounter';
        alertTextarea.parentNode.appendChild(counterDiv);
        
        function updateCounter() {
            const length = alertTextarea.value.length;
            counterDiv.textContent = `${length}/1000 characters`;
            if (length > 800) {
                counterDiv.className = 'form-text text-end text-warning';
            } else if (length > 950) {
                counterDiv.className = 'form-text text-end text-danger';
            } else {
                counterDiv.className = 'form-text text-end text-muted';
            }
        }
        
        alertTextarea.addEventListener('input', updateCounter);
        updateCounter();
    }
});

// Enhanced card hover effects
document.addEventListener('DOMContentLoaded', function() {
    const alertCards = document.querySelectorAll('.alert-card');
    alertCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
});
</script>

</body>
</html>
