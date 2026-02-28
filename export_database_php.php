<?php
/**
 * PHP Database Export Script
 * Alternative export method that works around mysqldump authentication issues
 */

require_once 'includes/config.php';

function exportDatabasePHP() {
    $timestamp = date('Y-m-d_H-i-s');
    $exportDir = __DIR__ . '/database_exports';
    
    // Create export directory if it doesn't exist
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }
    
    $sqlFile = $exportDir . "/asclepius_database_export_{$timestamp}.sql";
    $zipFile = $exportDir . "/asclepius_complete_export_{$timestamp}.zip";
    
    echo "=== ASCLEPIUS DATABASE EXPORT (PHP Method) ===\n\n";
    echo "Export started at: " . date('Y-m-d H:i:s') . "\n";
    echo "Exporting to: $sqlFile\n\n";
    
    try {
        $db = getDBConnection();
        $dbInfo = $db->query("SELECT DATABASE() as db_name")->fetch();
        $dbName = $dbInfo['db_name'];
        
        echo "Database: $dbName\n";
        
        // Start building SQL content
        $sql = generateSQLHeader($dbName);
        
        // Get all tables
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Found " . count($tables) . " tables to export\n\n";
        
        foreach ($tables as $table) {
            echo "Exporting table: $table... ";
            $sql .= exportTable($db, $table);
            echo "✓\n";
        }
        
        $sql .= "\n-- Export completed\nCOMMIT;\n";
        
        // Write SQL file
        file_put_contents($sqlFile, $sql);
        echo "\n✓ SQL dump created successfully (" . formatBytes(filesize($sqlFile)) . ")\n\n";
        
        // Create information file
        $infoFile = $exportDir . "/export_info_{$timestamp}.txt";
        createExportInfo($infoFile, $dbName, $tables, $db);
        echo "✓ Export information file created\n\n";
        
        // Create complete package
        echo "Creating complete export package...\n";
        createCompletePackage($zipFile, $sqlFile, $infoFile, $timestamp);
        echo "✓ Complete package created: $zipFile\n\n";
        
        // Show summary
        showExportSummary($sqlFile, $zipFile, $dbName);
        
        return true;
        
    } catch (Exception $e) {
        echo "❌ Export failed: " . $e->getMessage() . "\n";
        return false;
    }
}

function generateSQLHeader($dbName) {
    $header = "-- Asclepius Dengue Surveillance System Database Export\n";
    $header .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
    $header .= "-- Source Database: $dbName\n";
    $header .= "-- Compatible with MySQL 5.7+ and MariaDB 10.1+\n";
    $header .= "-- \n";
    $header .= "-- INSTALLATION INSTRUCTIONS:\n";
    $header .= "-- 1. Create database: CREATE DATABASE asclepius_db CHARACTER SET utf8 COLLATE utf8_unicode_ci;\n";
    $header .= "-- 2. Use database: USE asclepius_db;\n";
    $header .= "-- 3. Import this file: SOURCE /path/to/this/file.sql;\n";
    $header .= "-- \n\n";
    
    $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $header .= "SET AUTOCOMMIT = 0;\n";
    $header .= "START TRANSACTION;\n";
    $header .= "SET time_zone = \"+00:00\";\n";
    $header .= "SET NAMES utf8;\n\n";
    
    $header .= "-- Disable foreign key checks during import\n";
    $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    
    return $header;
}

function exportTable($db, $tableName) {
    $sql = "-- \n";
    $sql .= "-- Table structure for table `$tableName`\n";
    $sql .= "-- \n\n";
    
    // Drop table if exists
    $sql .= "DROP TABLE IF EXISTS `$tableName`;\n";
    
    // Get CREATE TABLE statement
    $createTableResult = $db->query("SHOW CREATE TABLE `$tableName`")->fetch();
    $createTableSQL = $createTableResult['Create Table'];
    
    // Fix collation issues in CREATE TABLE
    $createTableSQL = str_replace('utf8mb4_0900_ai_ci', 'utf8_unicode_ci', $createTableSQL);
    $createTableSQL = str_replace('utf8mb4_unicode_ci', 'utf8_unicode_ci', $createTableSQL);
    $createTableSQL = str_replace('utf8mb4', 'utf8', $createTableSQL);
    $createTableSQL = str_replace('DEFAULT CHARSET=utf8mb4', 'DEFAULT CHARSET=utf8', $createTableSQL);
    
    $sql .= $createTableSQL . ";\n\n";
    
    // Get table data
    $sql .= "-- \n";
    $sql .= "-- Dumping data for table `$tableName`\n";
    $sql .= "-- \n\n";
    
    $stmt = $db->query("SELECT * FROM `$tableName`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($rows)) {
        // Get column names
        $columns = array_keys($rows[0]);
        $columnList = '`' . implode('`, `', $columns) . '`';
        
        $sql .= "LOCK TABLES `$tableName` WRITE;\n";
        $sql .= "INSERT INTO `$tableName` ($columnList) VALUES\n";
        
        $valueStrings = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = "'" . addslashes($value) . "'";
                }
            }
            $valueStrings[] = '(' . implode(', ', $values) . ')';
        }
        
        $sql .= implode(",\n", $valueStrings) . ";\n";
        $sql .= "UNLOCK TABLES;\n\n";
    } else {
        $sql .= "-- No data to export for table `$tableName`\n\n";
    }
    
    return $sql;
}

function createExportInfo($infoFile, $dbName, $tables, $db) {
    $info = "=== ASCLEPIUS DATABASE EXPORT INFORMATION ===\n\n";
    $info .= "Export Date: " . date('Y-m-d H:i:s') . "\n";
    $info .= "Database Name: $dbName\n";
    $info .= "Export Method: PHP (Manual)\n";
    $info .= "Source System: " . php_uname() . "\n";
    $info .= "PHP Version: " . PHP_VERSION . "\n\n";
    
    $info .= "=== DATABASE STRUCTURE ===\n\n";
    
    foreach ($tables as $table) {
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM `$table`");
            $count = $stmt->fetchColumn();
            $info .= "Table: $table ($count records)\n";
            
            // Get column info
            $columns = $db->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $col) {
                $info .= "  - {$col['Field']}: {$col['Type']}\n";
            }
            $info .= "\n";
        } catch (Exception $e) {
            $info .= "Table: $table (Error getting info: " . $e->getMessage() . ")\n\n";
        }
    }
    
    $info .= "=== QUICK SETUP GUIDE ===\n\n";
    $info .= "1. ON TARGET MACHINE:\n";
    $info .= "   - Install XAMPP/WAMP/LAMP\n";
    $info .= "   - Start Apache and MySQL\n\n";
    
    $info .= "2. DATABASE IMPORT:\n";
    $info .= "   Method A - phpMyAdmin:\n";
    $info .= "   - Open http://localhost/phpmyadmin\n";
    $info .= "   - Create new database 'asclepius_db'\n";
    $info .= "   - Select database, go to Import tab\n";
    $info .= "   - Choose the .sql file and click Go\n\n";
    
    $info .= "   Method B - Command Line:\n";
    $info .= "   - mysql -u root -p -e \"CREATE DATABASE asclepius_db CHARACTER SET utf8 COLLATE utf8_unicode_ci;\"\n";
    $info .= "   - mysql -u root -p asclepius_db < asclepius_database.sql\n\n";
    
    $info .= "3. APPLICATION SETUP:\n";
    $info .= "   - Extract application files to htdocs/asclepius/\n";
    $info .= "   - Edit includes/config.php:\n";
    $info .= "     \$db_name = 'asclepius_db';\n";
    $info .= "     \$db_user = 'root';\n";
    $info .= "     \$db_pass = ''; // or your MySQL password\n";
    $info .= "   - Access http://localhost/asclepius/\n\n";
    
    $info .= "=== TROUBLESHOOTING ===\n\n";
    $info .= "Collation Error #1273:\n";
    $info .= "- This export fixes common collation issues\n";
    $info .= "- If still occurring, try MySQL 8.0+ or MariaDB 10.4+\n\n";
    
    $info .= "Connection Issues:\n";
    $info .= "- Check includes/config.php database settings\n";
    $info .= "- Ensure MySQL service is running\n";
    $info .= "- Verify database name and credentials\n\n";
    
    $info .= "Permission Issues:\n";
    $info .= "- Ensure web server has read access to files\n";
    $info .= "- Check file permissions (755 for directories, 644 for files)\n\n";
    
    file_put_contents($infoFile, $info);
}

function createCompletePackage($zipFile, $sqlFile, $infoFile, $timestamp) {
    $zip = new ZipArchive();
    
    if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
        throw new Exception("Cannot create ZIP file: $zipFile");
    }
    
    // Add database files
    $zip->addFile($sqlFile, 'database/asclepius_database.sql');
    $zip->addFile($infoFile, 'database/README.txt');
    
    // Add critical application files
    $criticalFiles = [
        'includes/config.php',
        'dashboard.php',
        'login.php',
        'patients.php',
        'analytics.php',
        'alerts.php',
        'prediction.php',
        'automatic_alert_monitor.php'
    ];
    
    foreach ($criticalFiles as $file) {
        if (file_exists($file)) {
            $zip->addFile($file, 'application/' . $file);
        }
    }
    
    // Add entire includes directory
    $includesDir = 'includes';
    if (is_dir($includesDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($includesDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = substr($file->getRealPath(), strlen(__DIR__) + 1);
                $zip->addFile($file->getRealPath(), 'application/' . str_replace('\\', '/', $relativePath));
            }
        }
    }
    
    // Add assets directory
    $assetsDir = 'assets';
    if (is_dir($assetsDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($assetsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = substr($file->getRealPath(), strlen(__DIR__) + 1);
                $zip->addFile($file->getRealPath(), 'application/' . str_replace('\\', '/', $relativePath));
            }
        }
    }
    
    // Add setup guide
    $setupGuide = "=== ASCLEPIUS SYSTEM - COMPLETE SETUP GUIDE ===\n\n";
    $setupGuide .= "This package contains everything needed to set up the Asclepius Dengue Surveillance System\n";
    $setupGuide .= "on a new laptop or server.\n\n";
    
    $setupGuide .= "CONTENTS:\n";
    $setupGuide .= "- database/: SQL dump and documentation\n";
    $setupGuide .= "- application/: PHP application files\n\n";
    
    $setupGuide .= "REQUIREMENTS:\n";
    $setupGuide .= "- Windows 10/11 (or Linux/macOS with modifications)\n";
    $setupGuide .= "- XAMPP 8.0+ (includes PHP 8.0+, MySQL/MariaDB, Apache)\n";
    $setupGuide .= "- 1GB free disk space\n";
    $setupGuide .= "- Internet connection (for weather API)\n\n";
    
    $setupGuide .= "INSTALLATION STEPS:\n\n";
    $setupGuide .= "1. INSTALL XAMPP:\n";
    $setupGuide .= "   - Download from https://www.apachefriends.org/\n";
    $setupGuide .= "   - Install to C:\\xampp (default)\n";
    $setupGuide .= "   - Start Apache and MySQL services\n\n";
    
    $setupGuide .= "2. EXTRACT FILES:\n";
    $setupGuide .= "   - Extract this ZIP to C:\\xampp\\htdocs\\asclepius\\\n";
    $setupGuide .= "   - You should have: C:\\xampp\\htdocs\\asclepius\\database\\ and C:\\xampp\\htdocs\\asclepius\\application\\\n\n";
    
    $setupGuide .= "3. IMPORT DATABASE:\n";
    $setupGuide .= "   - Open http://localhost/phpmyadmin\n";
    $setupGuide .= "   - Click 'New' to create database\n";
    $setupGuide .= "   - Name: asclepius_db\n";
    $setupGuide .= "   - Collation: utf8_unicode_ci\n";
    $setupGuide .= "   - Click 'Import' tab\n";
    $setupGuide .= "   - Choose database/asclepius_database.sql\n";
    $setupGuide .= "   - Click 'Go'\n\n";
    
    $setupGuide .= "4. CONFIGURE APPLICATION:\n";
    $setupGuide .= "   - Copy all files from application/ to C:\\xampp\\htdocs\\asclepius\\\n";
    $setupGuide .= "   - Edit includes/config.php if needed:\n";
    $setupGuide .= "     \$db_host = 'localhost';\n";
    $setupGuide .= "     \$db_name = 'asclepius_db';\n";
    $setupGuide .= "     \$db_user = 'root';\n";
    $setupGuide .= "     \$db_pass = ''; // Leave empty for XAMPP default\n\n";
    
    $setupGuide .= "5. TEST INSTALLATION:\n";
    $setupGuide .= "   - Open http://localhost/asclepius/\n";
    $setupGuide .= "   - You should see the login page\n";
    $setupGuide .= "   - Login with existing credentials\n";
    $setupGuide .= "   - Test all features: Dashboard, Patients, Analytics, Alerts, Prediction\n\n";
    
    $setupGuide .= "6. OPTIONAL - SETUP EMAIL ALERTS:\n";
    $setupGuide .= "   - Configure SMTP settings in includes/smtp_config.php\n";
    $setupGuide .= "   - Test email functionality in Alerts section\n";
    $setupGuide .= "   - Set up Windows Task Scheduler for automatic monitoring\n\n";
    
    $setupGuide .= "TROUBLESHOOTING:\n";
    $setupGuide .= "- Database connection errors: Check includes/config.php\n";
    $setupGuide .= "- 404 errors: Ensure files are in htdocs/asclepius/\n";
    $setupGuide .= "- Permission errors: Run XAMPP as administrator\n";
    $setupGuide .= "- Collation errors: Use MySQL 8.0+ or MariaDB 10.4+\n\n";
    
    $setupGuide .= "SUPPORT:\n";
    $setupGuide .= "See database/README.txt for detailed documentation\n\n";
    
    $zip->addFromString('SETUP_GUIDE.txt', $setupGuide);
    
    $zip->close();
}

function showExportSummary($sqlFile, $zipFile, $dbName) {
    echo "=== EXPORT COMPLETED SUCCESSFULLY ===\n\n";
    
    echo "📁 Files created:\n";
    echo "   SQL Dump: " . basename($sqlFile) . " (" . formatBytes(filesize($sqlFile)) . ")\n";
    echo "   Complete Package: " . basename($zipFile) . " (" . formatBytes(filesize($zipFile)) . ")\n\n";
    
    echo "📋 What's included:\n";
    echo "   ✓ Complete database structure and data\n";
    echo "   ✓ Application source code\n";
    echo "   ✓ Configuration files\n";
    echo "   ✓ Setup instructions\n";
    echo "   ✓ Troubleshooting guide\n\n";
    
    echo "🚀 Next steps:\n";
    echo "   1. Copy " . basename($zipFile) . " to your target laptop\n";
    echo "   2. Follow SETUP_GUIDE.txt in the ZIP file\n";
    echo "   3. Import the database using phpMyAdmin\n";
    echo "   4. Test the application\n\n";
    
    echo "💡 Tips:\n";
    echo "   - The export fixes collation issues automatically\n";
    echo "   - Compatible with MySQL 5.7+ and MariaDB 10.1+\n";
    echo "   - All sensitive data is preserved\n\n";
    
    echo "Location: " . dirname($zipFile) . "\n";
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// Run the export
if (exportDatabasePHP()) {
    echo "\n🎉 Database export completed successfully!\n";
    echo "Ready for transfer to another laptop.\n";
} else {
    echo "\n❌ Export failed!\n";
    exit(1);
}
?>