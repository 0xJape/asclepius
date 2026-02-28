<?php
require_once 'includes/config.php';

try {
    $db = getDBConnection();
    
    // Check if email_log table exists
    try {
        $stmt = $db->query('DESCRIBE email_log');
        echo "✅ email_log table already exists\n";
    } catch (Exception $e) {
        echo "⚠️ email_log table does not exist, creating it...\n";
        
        // Create email_log table
        $createTable = "
        CREATE TABLE email_log (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            recipient_email VARCHAR(255) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            status ENUM('sent', 'failed', 'pending') NOT NULL,
            error_message TEXT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recipient (recipient_email),
            INDEX idx_status (status),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $db->exec($createTable);
        echo "✅ email_log table created successfully\n";
    }
    
    echo "\n📧 Email logging system is ready!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
