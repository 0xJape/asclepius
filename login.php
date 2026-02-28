<?php
session_start();
require_once 'includes/config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("SELECT user_id, username, password_hash, full_name, role FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Successful login
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                
                // Update last login
                $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                $stmt->execute([$user['user_id']]);
                
                header('Location: dashboard.php');
                exit();
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (PDOException $e) {
            // For demo mode, allow any login
            $_SESSION['user_id'] = 1;
            $_SESSION['user_name'] = $username ?: 'Administrator';
            $_SESSION['username'] = $username ?: 'admin';
            $_SESSION['user_role'] = 'admin';
            header('Location: dashboard.php');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ASCLEPIUS Dengue Monitoring System</title>
    
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
    
    <style>
        :root {
            --login-gradient-start: #0f172a;
            --login-gradient-end: #1e293b;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--slate-100) 0%, var(--slate-200) 100%);
            font-family: var(--font-family-body);
        }
        
        .login-container {
            width: 100%;
            max-width: 440px;
            padding: 2rem;
        }
        
        .login-card {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--login-gradient-start) 0%, var(--login-gradient-end) 100%);
            padding: 2.5rem 2rem;
            text-align: center;
            color: white;
        }
        
        .login-logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--cyan-400), var(--cyan-500));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            backdrop-filter: blur(8px);
        }
        
        .login-logo i {
            font-size: 2.5rem;
        }
        
        .login-title {
            font-family: var(--font-family-display);
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .login-subtitle {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .input-group-icon {
            position: relative;
        }
        
        .input-group-icon .form-control {
            padding-left: 3rem;
            height: 50px;
            border-radius: var(--radius-lg);
            border: 2px solid var(--gray-200);
            font-size: 1rem;
            transition: all 0.2s ease;
        }
        
        .input-group-icon .form-control:focus {
            border-color: var(--cyan-500);
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.15);
        }
        
        .input-group-icon .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            font-size: 1.1rem;
            transition: color 0.2s ease;
        }
        
        .input-group-icon .form-control:focus + .input-icon,
        .input-group-icon .form-control:not(:placeholder-shown) + .input-icon {
            color: var(--cyan-500);
        }
        
        .btn-login {
            width: 100%;
            height: 50px;
            background: linear-gradient(135deg, var(--cyan-500) 0%, var(--cyan-600) 100%);
            border: none;
            border-radius: var(--radius-lg);
            color: white;
            font-family: var(--font-family-display);
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(6, 182, 212, 0.25);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(6, 182, 212, 0.35);
            background: linear-gradient(135deg, var(--cyan-600) 0%, var(--cyan-700) 100%);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert-error {
            background: var(--red-50);
            border: 1px solid var(--red-200);
            border-radius: var(--radius-lg);
            color: var(--red-700);
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .alert-error i {
            color: var(--red-500);
            font-size: 1.1rem;
        }
        
        .login-footer {
            text-align: center;
            padding: 0 2rem 2rem;
            color: var(--gray-500);
            font-size: 0.85rem;
        }
        
        .login-footer a {
            color: var(--green-600);
            font-weight: 500;
            text-decoration: none;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        .demo-notice {
            background: var(--blue-50);
            border: 1px solid var(--blue-200);
            border-radius: var(--radius-lg);
            padding: 1rem;
            margin-top: 1.5rem;
            font-size: 0.85rem;
            color: var(--blue-700);
        }
        
        .demo-notice strong {
            display: block;
            margin-bottom: 0.25rem;
            color: var(--blue-800);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <i class="fas fa-heartbeat"></i>
                </div>
                <h1 class="login-title">ASCLEPIUS</h1>
                <p class="login-subtitle">Dengue Surveillance & Early Warning System</p>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label" for="username">Username</label>
                        <div class="input-group-icon">
                            <input type="text" 
                                   class="form-control" 
                                   id="username" 
                                   name="username" 
                                   placeholder="Enter your username"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                   required
                                   autofocus>
                            <i class="fas fa-user input-icon"></i>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-group-icon">
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Enter your password"
                                   required>
                            <i class="fas fa-lock input-icon"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i>
                        Sign In
                    </button>
                </form>
                
                <div class="demo-notice">
                    <strong><i class="fas fa-info-circle me-1"></i> Demo Mode</strong>
                    Enter any username and password to access the system.
                </div>
            </div>
            
            <div class="login-footer">
                <p>ASCLEPIUS - Tupi, South Cotabato<br>
                Municipal Health Office &copy; <?php echo date('Y'); ?></p>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
