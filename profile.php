<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

// Get current user info
$user_id = getUserId();
$db = getDBConnection();

try {
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $userInfo = [
        'username' => getUsername(),
        'full_name' => getUserName(),
        'role' => getUserRole(),
        'email' => '',
        'created_at' => date('Y-m-d H:i:s'),
        'last_login' => date('Y-m-d H:i:s')
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile - ASCLEPIUS Dengue Monitoring</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/dengue_logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/dengue_logo.png">
    
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
</head>
<body>
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
                <a href="alerts.php" class="menu-item">
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
                    <div class="user-name"><?php echo htmlspecialchars($userInfo['full_name']); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($userInfo['role']); ?></div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container-fluid p-4">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="page-title mb-0">User Profile</h1>
                    <small class="text-muted">Manage your account information</small>
                </div>
            </div>

            <!-- Profile Card -->
            <div class="row">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-lg">
                        <div class="card-header bg-gradient-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-user-circle me-2"></i>
                                Account Information
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="profile-item mb-3">
                                        <label class="form-label fw-bold">Username</label>
                                        <div class="profile-value">
                                            <i class="fas fa-user me-2 text-primary"></i>
                                            <?php echo htmlspecialchars($userInfo['username']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="profile-item mb-3">
                                        <label class="form-label fw-bold">Full Name</label>
                                        <div class="profile-value">
                                            <i class="fas fa-id-card me-2 text-primary"></i>
                                            <?php echo htmlspecialchars($userInfo['full_name']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="profile-item mb-3">
                                        <label class="form-label fw-bold">Email</label>
                                        <div class="profile-value">
                                            <i class="fas fa-envelope me-2 text-primary"></i>
                                            <?php echo htmlspecialchars($userInfo['email'] ?: 'Not provided'); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="profile-item mb-3">
                                        <label class="form-label fw-bold">Role</label>
                                        <div class="profile-value">
                                            <i class="fas fa-user-shield me-2 text-primary"></i>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($userInfo['role']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="profile-item mb-3">
                                        <label class="form-label fw-bold">Account Created</label>
                                        <div class="profile-value">
                                            <i class="fas fa-calendar-plus me-2 text-primary"></i>
                                            <?php echo date('F j, Y', strtotime($userInfo['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="profile-item mb-3">
                                        <label class="form-label fw-bold">Last Login</label>
                                        <div class="profile-value">
                                            <i class="fas fa-clock me-2 text-primary"></i>
                                            <?php 
                                            if ($userInfo['last_login']) {
                                                echo date('F j, Y \a\t g:i A', strtotime($userInfo['last_login']));
                                            } else {
                                                echo 'First login';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card border-0 shadow-lg">
                        <div class="card-header bg-gradient-success text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-cogs me-2"></i>
                                Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="dashboard.php" class="btn btn-outline-primary">
                                    <i class="fas fa-tachometer-alt me-2"></i>
                                    Go to Dashboard
                                </a>
                                <a href="chatbot/chatbot.php" class="btn btn-outline-success">
                                    <i class="fas fa-robot me-2"></i>
                                    AI Chatbot
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card border-0 shadow-lg mt-4">
                        <div class="card-header bg-gradient-info text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                System Info
                            </h5>
                        </div>
                        <div class="card-body">
                            <small class="text-muted">
                                <strong>ASCLEPIUS</strong> - Advanced Dengue Monitoring System<br>
                                Version 1.0<br>
                                Logged in as: <?php echo htmlspecialchars($userInfo['role']); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .profile-value {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }
        
        .profile-item {
            margin-bottom: 1rem;
        }
        
        .bg-gradient-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .bg-gradient-success {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
        }
        
        .bg-gradient-info {
            background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
        }
    </style>
</body>
</html>
