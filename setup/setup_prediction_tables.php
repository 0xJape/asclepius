<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in and is admin
checkAuth();

// Initialize results
$results = [
    'success' => false,
    'messages' => [],
    'errors' => []
];

// Read the SQL file
$sqlFile = file_get_contents('database/prediction_tables.sql');
if (!$sqlFile) {
    $results['errors'][] = "Could not read the SQL file.";
} else {
    $db = getDBConnection();
    
    // Split the SQL file into separate statements
    $statements = explode(';', $sqlFile);
    
    try {
        // Start transaction
        $db->beginTransaction();
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $db->exec($statement);
                $results['messages'][] = "Executed: " . substr($statement, 0, 50) . "...";
            }
        }
        
        // Commit the transaction
        $db->commit();
        $results['success'] = true;
        $results['messages'][] = "Prediction tables successfully created!";
    } catch (PDOException $e) {
        // Roll back the transaction
        $db->rollBack();
        $results['errors'][] = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Prediction Tables - Dengue Monitoring System</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/dashboard.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0"><i class="fas fa-database me-2"></i>Setup Prediction Tables</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($results['success']): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                Prediction tables were successfully created!
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($results['errors'])): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Errors:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($results['errors'] as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <h5 class="mb-3">Execution Results:</h5>
                        <div class="bg-dark text-light p-3 rounded" style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($results['messages'] as $message): ?>
                                <div class="mb-2"><?php echo htmlspecialchars($message); ?></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex justify-content-between">
                            <a href="dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                            <a href="prediction.php" class="btn btn-success">
                                <i class="fas fa-chart-pie me-2"></i>Go to Prediction Module
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
