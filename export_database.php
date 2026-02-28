<?php
/**
 * Database Export Script
 * Creates a compatible SQL dump for transferring to other laptops
 * Handles collation issues and ensures compatibility across different MySQL/MariaDB versions
 */

require_once 'includes/config.php';

function exportDatabase() {
    $timestamp = date('Y-m-d_H-i-s');
    $exportDir = __DIR__ . '/database_exports';
    
    // Create export directory if it doesn't exist
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }
    
    $sqlFile = $exportDir . "/asclepius_database_export_{$timestamp}.sql";
    $zipFile = $exportDir . "/asclepius_complete_export_{$timestamp}.zip";
    
    echo "=== ASCLEPIUS DATABASE EXPORT ===\n\n";
    echo "Export started at: " . date('Y-m-d H:i:s') . "\n";
    echo "Exporting to: $sqlFile\n\n";
    
    try {
        // Get database connection info
        $db = getDBConnection();
        $dbInfo = $db->query("SELECT DATABASE() as db_name")->fetch();
        $dbName = $dbInfo['db_name'];
        
        echo "Database: $dbName\n";
        
        // Use mysqldump with compatibility options
        $mysqldumpPath = 'c:\xampp\mysql\bin\mysqldump.exe';
        
        // Build mysqldump command with compatibility options
        $command = "\"$mysqldumpPath\" " .
                   "--user=root " .
                   "--password= " .
                   "--host=localhost " .
                   "--port=3306 " .
                   "--single-transaction " .
                   "--routines " .
                   "--triggers " .
                   "--complete-insert " .
                   "--extended-insert " .
                   "--add-drop-table " .
                   "--add-locks " .
                   "--disable-keys " .
                   "--set-charset " .
                   "--default-character-set=utf8 " .
                   "--compatible=mysql40 " .
                   "\"$dbName\" > \"$sqlFile\"";
        
        echo "Running mysqldump...\n";
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("mysqldump failed with return code: $returnCode\nOutput: " . implode("\n", $output));
        }
        
        if (!file_exists($sqlFile) || filesize($sqlFile) == 0) {
            throw new Exception("SQL file was not created or is empty");
        }
        
        echo "✓ SQL dump created successfully (" . formatBytes(filesize($sqlFile)) . ")\n\n";
        
        // Post-process the SQL file to fix collation issues
        echo "Post-processing SQL file for compatibility...\n";
        fixCollationIssues($sqlFile);
        echo "✓ Collation issues fixed\n\n";
        
        // Create information file
        $infoFile = $exportDir . "/export_info_{$timestamp}.txt";
        createExportInfo($infoFile, $dbName);
        echo "✓ Export information file created\n\n";
        
        // Create complete package with application files
        echo "Creating complete export package...\n";
        createCompletePackage($zipFile, $sqlFile, $infoFile, $timestamp);
        echo "✓ Complete package created: $zipFile\n\n";
        
        // Show summary
        showExportSummary($sqlFile, $zipFile, $dbName);
        
    } catch (Exception $e) {
        echo "❌ Export failed: " . $e->getMessage() . "\n";
        return false;
    }
    
    return true;
}

function fixCollationIssues($sqlFile) {
    $content = file_get_contents($sqlFile);
    
    // Replace problematic collations with compatible ones
    $collationReplacements = [
        'utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_ci',
        'utf8mb4_0900_as_cs' => 'utf8mb4_unicode_ci',
        'utf8_0900_ai_ci' => 'utf8_unicode_ci',
        'utf8_0900_as_cs' => 'utf8_unicode_ci'
    ];
    
    foreach ($collationReplacements as $old => $new) {
        $content = str_replace($old, $new, $content);
    }
    
    // Ensure consistent character set
    $content = str_replace('DEFAULT CHARSET=utf8mb4', 'DEFAULT CHARSET=utf8', $content);
    
    // Add compatibility header
    $header = "-- Asclepius Dengue Surveillance System Database Export\n";
    $header .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
    $header .= "-- Compatible with MySQL 5.7+ and MariaDB 10.1+\n";
    $header .= "-- \n";
    $header .= "-- BEFORE IMPORTING:\n";
    $header .= "-- 1. Create database: CREATE DATABASE asclepius_db CHARACTER SET utf8 COLLATE utf8_unicode_ci;\n";
    $header .= "-- 2. Use database: USE asclepius_db;\n";
    $header .= "-- 3. Import this file: SOURCE /path/to/this/file.sql;\n\n";
    $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $header .= "SET AUTOCOMMIT = 0;\n";
    $header .= "START TRANSACTION;\n";
    $header .= "SET time_zone = \"+00:00\";\n\n";
    
    $content = $header . $content;
    
    // Add closing transaction
    $content .= "\n\nCOMMIT;\n";
    
    file_put_contents($sqlFile, $content);
}

function createExportInfo($infoFile, $dbName) {
    $db = getDBConnection();
    
    // Get table information
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $info = "=== ASCLEPIUS DATABASE EXPORT INFORMATION ===\n\n";
    $info .= "Export Date: " . date('Y-m-d H:i:s') . "\n";
    $info .= "Database Name: $dbName\n";
    $info .= "Source System: " . php_uname() . "\n";
    $info .= "PHP Version: " . PHP_VERSION . "\n\n";
    
    $info .= "=== DATABASE STRUCTURE ===\n\n";
    
    foreach ($tables as $table) {
        $stmt = $db->query("SELECT COUNT(*) FROM `$table`");
        $count = $stmt->fetchColumn();
        $info .= "Table: $table ($count records)\n";
        
        // Get column info
        $columns = $db->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            $info .= "  - {$col['Field']}: {$col['Type']}\n";
        }
        $info .= "\n";
    }
    
    $info .= "=== INSTALLATION INSTRUCTIONS ===\n\n";
    $info .= "1. Install XAMPP or similar PHP/MySQL environment\n";
    $info .= "2. Start Apache and MySQL services\n";
    $info .= "3. Open phpMyAdmin or MySQL command line\n";
    $info .= "4. Create new database:\n";
    $info .= "   CREATE DATABASE asclepius_db CHARACTER SET utf8 COLLATE utf8_unicode_ci;\n";
    $info .= "5. Import the SQL file:\n";
    $info .= "   - Via phpMyAdmin: Import tab > Choose file > Go\n";
    $info .= "   - Via command line: mysql -u root -p asclepius_db < exported_file.sql\n";
    $info .= "6. Extract application files to htdocs/asclepius/\n";
    $info .= "7. Update includes/config.php with your database settings\n";
    $info .= "8. Access via http://localhost/asclepius/\n\n";
    
    $info .= "=== TROUBLESHOOTING ===\n\n";
    $info .= "If you get collation errors:\n";
    $info .= "1. Check MySQL/MariaDB version compatibility\n";
    $info .= "2. Try creating database with: CHARACTER SET utf8 COLLATE utf8_general_ci\n";
    $info .= "3. If still issues, contact the system administrator\n\n";
    
    file_put_contents($infoFile, $info);
}

function createCompletePackage($zipFile, $sqlFile, $infoFile, $timestamp) {
    $zip = new ZipArchive();
    
    if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
        throw new Exception("Cannot create ZIP file: $zipFile");
    }
    
    // Add SQL dump
    $zip->addFile($sqlFile, 'database/asclepius_database.sql');
    
    // Add info file
    $zip->addFile($infoFile, 'database/README.txt');
    
    // Add application files (excluding sensitive and temporary files)
    $excludePatterns = [
        '/vendor/',
        '/node_modules/',
        '/logs/',
        '/database_exports/',
        '/uploads/temp/',
        '.log',
        '.tmp',
        '.cache'
    ];
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen(__DIR__) + 1);
        
        // Skip excluded files
        $skip = false;
        foreach ($excludePatterns as $pattern) {
            if (strpos($relativePath, $pattern) !== false) {
                $skip = true;
                break;
            }
        }
        
        if (!$skip && $file->isFile()) {
            $zip->addFile($filePath, 'application/' . str_replace('\\', '/', $relativePath));
        }
    }
    
    // Add setup instructions
    $setupInstructions = "=== ASCLEPIUS SYSTEM SETUP GUIDE ===\n\n";
    $setupInstructions .= "1. DATABASE SETUP:\n";
    $setupInstructions .= "   - Extract this ZIP file\n";
    $setupInstructions .= "   - Import database/asclepius_database.sql into MySQL\n";
    $setupInstructions .= "   - See database/README.txt for detailed instructions\n\n";
    $setupInstructions .= "2. APPLICATION SETUP:\n";
    $setupInstructions .= "   - Copy application/ folder contents to your web server\n";
    $setupInstructions .= "   - Update includes/config.php with your database settings\n";
    $setupInstructions .= "   - Ensure proper file permissions\n\n";
    $setupInstructions .= "3. DEPENDENCIES:\n";
    $setupInstructions .= "   - PHP 7.4+ (8.0+ recommended)\n";
    $setupInstructions .= "   - MySQL 5.7+ or MariaDB 10.1+\n";
    $setupInstructions .= "   - Apache/Nginx web server\n";
    $setupInstructions .= "   - Required PHP extensions: PDO, MySQLi, GD, cURL\n\n";
    $setupInstructions .= "4. FIRST RUN:\n";
    $setupInstructions .= "   - Access http://your-domain/path-to-app/\n";
    $setupInstructions .= "   - Login with existing credentials\n";
    $setupInstructions .= "   - Test all functionality\n\n";
    
    $zip->addFromString('SETUP_INSTRUCTIONS.txt', $setupInstructions);
    
    $zip->close();
}

function showExportSummary($sqlFile, $zipFile, $dbName) {
    echo "=== EXPORT SUMMARY ===\n\n";
    echo "✓ Database exported successfully!\n\n";
    echo "Files created:\n";
    echo "- SQL Dump: " . basename($sqlFile) . " (" . formatBytes(filesize($sqlFile)) . ")\n";
    echo "- Complete Package: " . basename($zipFile) . " (" . formatBytes(filesize($zipFile)) . ")\n\n";
    
    echo "=== TRANSFER INSTRUCTIONS ===\n\n";
    echo "1. Copy the ZIP file to your target laptop:\n";
    echo "   " . basename($zipFile) . "\n\n";
    echo "2. On the target laptop:\n";
    echo "   - Install XAMPP or similar environment\n";
    echo "   - Extract the ZIP file\n";
    echo "   - Follow SETUP_INSTRUCTIONS.txt\n\n";
    echo "3. Database import command:\n";
    echo "   mysql -u root -p -e \"CREATE DATABASE asclepius_db CHARACTER SET utf8 COLLATE utf8_unicode_ci;\"\n";
    echo "   mysql -u root -p asclepius_db < asclepius_database.sql\n\n";
    echo "Export completed successfully! 🎉\n";
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// Run the export
if (exportDatabase()) {
    echo "\n✓ Export completed successfully!\n";
} else {
    echo "\n❌ Export failed!\n";
    exit(1);
}
?>