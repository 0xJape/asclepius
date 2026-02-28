<?php
/**
 * SMTP2GO Email Configuration
 * Official SMTP2GO PHP API Integration
 * 
 * To set up SMTP2GO:
 * 1. Sign up at https://www.smtp2go.com/
 * 2. Verify your sender email domain
 * 3. Get your API KEY from the dashboard (not SMTP credentials)
 * 4. Update the values below with your actual credentials
 * 
 * Installation:
 * composer require smtp2go-oss/smtp2go-php
 * 
 * OR download manually from: https://github.com/smtp2go-oss/smtp2go-php
 */

// SMTP2GO API Configuration
define('SMTP2GO_API_KEY', 'api-911859CEF0AF4026B781CBC26CDC6541'); // Replace with your actual API key from SMTP2GO dashboard
define('SMTP2GO_API_REGION', 'us'); // Options: 'us', 'eu' - choose based on your location
define('SMTP2GO_FROM_EMAIL', 'tupi.dengue.alert@hotmail.com');
define('SMTP2GO_FROM_NAME', 'Dengue Early Warning System');
define('SMTP2GO_REPLY_TO', 'tupi.dengue.alert@hotmail.com');

// Fallback SMTP Configuration (for basic mail() function)
define('SMTP_FROM_EMAIL', 'tupi.dengue.alert@hotmail.com');
define('SMTP_FROM_NAME', 'Dengue Early Warning System');
define('SMTP_REPLY_TO', 'tupi.dengue.alert@hotmail.com');

// Email Template Settings
define('EMERGENCY_HOTLINE', '123-456-7890');
define('EMERGENCY_EMAIL', 'tupi@health.gov.ph');
define('HEALTH_DEPT_NAME', 'Tupi Local Health Department');

// Debug Settings
define('SMTP_DEBUG', false); // Set to true for debugging
define('LOG_EMAIL_ERRORS', true);
define('SMTP2GO_MAX_ATTEMPTS', 3); // Number of retry attempts
define('SMTP2GO_TIMEOUT_INCREMENT', 5); // Seconds to increase timeout per attempt

// Test Email Configuration
define('TEST_EMAIL_ENABLED', true);
define('TEST_EMAIL_ADDRESS', 'test@yourdomain.com');

/**
 * SMTP2GO API Functions
 */

// Validate SMTP2GO Configuration
function validateSMTP2GOConfig() {
    $errors = [];
    
    if (!defined('SMTP2GO_API_KEY') || SMTP2GO_API_KEY === 'api-YOURAPIKEY') {
        $errors[] = 'SMTP2GO API Key not configured';
    }
    
    if (!defined('SMTP2GO_FROM_EMAIL') || empty(SMTP2GO_FROM_EMAIL)) {
        $errors[] = 'From email address not configured';
    }
    
    if (!defined('SMTP2GO_FROM_NAME') || empty(SMTP2GO_FROM_NAME)) {
        $errors[] = 'From name not configured';
    }
    
    if (!filter_var(SMTP2GO_FROM_EMAIL, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid from email address format';
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

// Check if SMTP2GO PHP library is available
function isSMTP2GOAvailable() {
    return class_exists('SMTP2GO\ApiClient') && 
           class_exists('SMTP2GO\Service\Mail\Send') && 
           class_exists('SMTP2GO\Types\Mail\Address');
}

// Test SMTP2GO API Connection
function testSMTP2GOConnection($testEmail = null) {
    $testEmail = $testEmail ?: (defined('TEST_EMAIL_ADDRESS') ? TEST_EMAIL_ADDRESS : null);
    
    if (!$testEmail) {
        return ['success' => false, 'message' => 'No test email address configured'];
    }
    
    if (!isSMTP2GOAvailable()) {
        return ['success' => false, 'message' => 'SMTP2GO PHP library not available'];
    }
    
    $config = validateSMTP2GOConfig();
    if (!$config['valid']) {
        return ['success' => false, 'message' => 'Configuration errors: ' . implode(', ', $config['errors'])];
    }
    
    try {
        // Create test official data
        $testOfficial = [
            'name' => 'Test Recipient',
            'position' => 'System Administrator',
            'email' => $testEmail,
            'barangay_name' => 'Test Location'
        ];
        
        // Send actual test email using the alerts.php function
        $testMessage = 'This is a test email from the Dengue Early Warning System. If you receive this, SMTP2GO is configured correctly!';
        $testUrgency = 'LOW';
        $testType = 'test';
        
        // Only test if sendEmailAlert function is available (from alerts.php)
        if (function_exists('sendEmailAlert')) {
            $result = sendEmailAlert($testOfficial, $testMessage, $testUrgency, $testType);
            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Test email sent successfully to ' . $testEmail
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to send test email'
                ];
            }
        } else {
            return ['success' => true, 'message' => 'SMTP2GO configuration is valid and library is available (email function not loaded)'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error sending test email: ' . $e->getMessage()];
    }
}

// Log email delivery attempts
function logEmailDelivery($recipient, $subject, $status, $error = null, $method = 'smtp2go') {
    if (!LOG_EMAIL_ERRORS) return;
    
    $timestamp = date('Y-m-d H:i:s');
    
    // Log to file
    $logEntry = [
        'timestamp' => $timestamp,
        'recipient' => $recipient,
        'subject' => $subject,
        'status' => $status,
        'method' => $method,
        'error' => $error
    ];
    
    $logLine = json_encode($logEntry) . "\n";
    file_put_contents(__DIR__ . '/../logs/email_delivery.log', $logLine, FILE_APPEND | LOCK_EX);
    
    // Also log to error log
    $errorLogEntry = "[$timestamp] Email to $recipient: $status";
    if ($error) {
        $errorLogEntry .= " - Error: $error";
    }
    error_log($errorLogEntry);
    
    // Log to database for better tracking
    try {
        $db = getDBConnection();
        
        // Create table if it doesn't exist
        $db->exec("
            CREATE TABLE IF NOT EXISTS email_log (
                id INT PRIMARY KEY AUTO_INCREMENT,
                recipient VARCHAR(255) NOT NULL,
                subject VARCHAR(500),
                status ENUM('sent', 'failed') NOT NULL,
                method VARCHAR(50) DEFAULT 'smtp2go',
                error_message TEXT,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_recipient (recipient),
                INDEX idx_sent_at (sent_at),
                INDEX idx_status (status)
            )
        ");
        
        $stmt = $db->prepare("
            INSERT INTO email_log (recipient, subject, status, method, error_message, sent_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([$recipient, $subject, $status, $method, $error]);
        
    } catch (Exception $e) {
        error_log("Failed to log email delivery to database: " . $e->getMessage());
    }
}

// Send email using SMTP2GO API (referenced from alerts.php)
function sendSMTP2GOEmail($official, $message, $urgency, $type) {
    // This function is now defined in alerts.php
    // This is just a placeholder to avoid undefined function errors
    return false;
}

// Create logs directory if it doesn't exist
if (!file_exists(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}

/**
 * Installation Instructions for SMTP2GO PHP API:
 * 
 * METHOD 1 - Using Composer (Recommended):
 * 1. Install Composer: https://getcomposer.org/
 * 2. Run in your project directory: composer require smtp2go-oss/smtp2go-php
 * 3. Uncomment the autoload line in alerts.php: require_once 'vendor/autoload.php';
 * 4. Uncomment the use statements in alerts.php
 * 
 * METHOD 2 - Manual Installation:
 * 1. Download from: https://github.com/smtp2go-oss/smtp2go-php
 * 2. Extract to your project folder (e.g., /lib/smtp2go-php/)
 * 3. Uncomment the manual include lines in alerts.php
 * 4. Update the paths to match your folder structure
 * 
 * SMTP2GO Setup:
 * 1. Create account at https://www.smtp2go.com/
 * 2. Add and verify your sending domain
 * 3. Generate an API KEY (not SMTP credentials) from the dashboard
 * 4. Update SMTP2GO_API_KEY above with your actual API key
 * 5. Test with: testSMTP2GOConnection('your-email@domain.com')
 * 
 * API Key Location:
 * - Login to SMTP2GO dashboard
 * - Go to Settings > API Keys
 * - Create new API key or copy existing one
 * - Replace 'api-YOURAPIKEY' above with your actual key
 */

?>
