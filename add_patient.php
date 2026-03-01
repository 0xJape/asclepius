<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDBConnection();
    
    try {
        // Validate and sanitize input data
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $date_of_birth = $_POST['date_of_birth'];
        $gender = $_POST['gender'];
        $address = trim($_POST['address']);
        $contact_number = trim($_POST['contact_number']);
        $barangay_id = (int)$_POST['barangay_id'];
        
        // Basic validation
        $errors = [];
        
        if (empty($first_name)) {
            $errors[] = "First name is required.";
        }
        
        if (empty($last_name)) {
            $errors[] = "Last name is required.";
        }
        
        if (empty($date_of_birth)) {
            $errors[] = "Date of birth is required.";
        } else {
            // Check if date is not in the future
            if (strtotime($date_of_birth) > time()) {
                $errors[] = "Date of birth cannot be in the future.";
            }
            
            // Check if age is reasonable (not over 120 years)
            $age = date_diff(date_create($date_of_birth), date_create('today'))->y;
            if ($age > 120) {
                $errors[] = "Please enter a valid date of birth.";
            }
        }
        
        if (empty($gender) || !in_array($gender, ['Male', 'Female'])) {
            $errors[] = "Please select a valid gender.";
        }
        
        if (empty($address)) {
            $errors[] = "Address is required.";
        }
        
        if (!empty($contact_number)) {
            // Basic Philippine mobile number validation
            if (!preg_match('/^(09|\+639)\d{9}$/', $contact_number)) {
                $errors[] = "Please enter a valid Philippine mobile number (e.g., 09171234567).";
            }
        }
        
        if (empty($barangay_id) || $barangay_id <= 0) {
            $errors[] = "Please select a barangay.";
        }
        
        // Check if patient already exists (same name and birth date)
        if (empty($errors)) {
            $checkQuery = "SELECT patient_id FROM patients WHERE first_name = ? AND last_name = ? AND date_of_birth = ?";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([$first_name, $last_name, $date_of_birth]);
            $existing_patient = $checkStmt->fetch();
            
            if ($existing_patient) {
                $existing_patient_id = $existing_patient['patient_id'];
                
                // Set a session message to inform user about existing patient
                $_SESSION['info_message'] = "Patient '{$first_name} {$last_name}' already exists in the system. A new case will be added to their record.";
                
                // Always redirect to add case page for existing patient
                header("Location: add_case.php?patient_id={$existing_patient_id}&from_add_patient=1");
                exit;
            }
        }
        // If no errors, insert the patient
        if (empty($errors)) {
            $insertQuery = "
                INSERT INTO patients (first_name, last_name, date_of_birth, gender, address, contact_number, barangay_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $insertStmt = $db->prepare($insertQuery);
            $success = $insertStmt->execute([
                $first_name,
                $last_name,
                $date_of_birth,
                $gender,
                $address,
                $contact_number ?: null,
                $barangay_id
            ]);
            
            if ($success) {
                $patient_id = $db->lastInsertId();
                $_SESSION['success_message'] = "Patient '{$first_name} {$last_name}' has been successfully added.";
                
                // Check if user wants to add a case immediately
                if (isset($_POST['add_case']) && $_POST['add_case'] === '1') {
                    header("Location: add_case.php?patient_id={$patient_id}");
                } else {
                    header("Location: view_patient.php?id={$patient_id}");
                }
                exit;
            } else {
                $errors[] = "Failed to add patient. Please try again.";
            }
        }
        
    } catch (PDOException $e) {
        error_log("Error adding patient: " . $e->getMessage());
        $errors[] = "Database error occurred. Please try again.";
    }
}

// Get barangays for dropdown
function getBarangays() {
    $db = getDBConnection();
    try {
        $stmt = $db->query("SELECT barangay_id, name FROM barangays ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("Error getting barangays: " . $e->getMessage());
        return [];
    }
}

$barangays = getBarangays();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Patient - Dengue Monitoring System</title>
    
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
        .form-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .form-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .form-body {
            padding: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-outline-secondary {
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 600;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .required {
            color: #dc3545;
        }
        
        .age-display {
            font-size: 0.875rem;
            color: #6c757d;
            font-style: italic;
        }
        
        .contact-help {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .section-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
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
                <a href="patients.php" class="menu-item active">
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
                    <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Demo User'); ?></div>
                    <div class="user-role">Administrator</div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Back Button -->
        <div class="mb-4">
            <a href="patients.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Patients
            </a>
        </div>

        <!-- Main Form -->
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card form-card">
                    <div class="form-header">
                        <h2 class="mb-0">
                            <i class="fas fa-user-plus me-3"></i>Add New Patient
                        </h2>
                        <p class="mb-0 mt-2 opacity-75">Enter patient information. If patient exists, a new case will be added to their record.</p>
                    </div>
                    
                    <div class="form-body">
                        <!-- Display errors -->
                        <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-exclamation-triangle me-2"></i>Please correct the following errors:</h6>
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="add_patient.php" id="addPatientForm">
                            <!-- Personal Information Section -->
                            <div class="form-section">
                                <h5 class="section-title">
                                    <i class="fas fa-user me-2"></i>Personal Information
                                </h5>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="first_name" class="form-label">
                                                First Name <span class="required">*</span>
                                            </label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="first_name" 
                                                   name="first_name" 
                                                   value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" 
                                                   required
                                                   placeholder="Enter first name">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="last_name" class="form-label">
                                                Last Name <span class="required">*</span>
                                            </label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="last_name" 
                                                   name="last_name" 
                                                   value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" 
                                                   required
                                                   placeholder="Enter last name">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="date_of_birth" class="form-label">
                                                Date of Birth <span class="required">*</span>
                                            </label>
                                            <input type="date" 
                                                   class="form-control" 
                                                   id="date_of_birth" 
                                                   name="date_of_birth" 
                                                   value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>" 
                                                   required
                                                   max="<?php echo date('Y-m-d'); ?>"
                                                   onchange="calculateAge()">
                                            <div id="ageDisplay" class="age-display mt-1"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="gender" class="form-label">
                                                Gender <span class="required">*</span>
                                            </label>
                                            <select class="form-select" id="gender" name="gender" required>
                                                <option value="">Select Gender</option>
                                                <option value="Male" <?php echo (($_POST['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>
                                                    Male
                                                </option>
                                                <option value="Female" <?php echo (($_POST['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>
                                                    Female
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact Information Section -->
                            <div class="form-section">
                                <h5 class="section-title">
                                    <i class="fas fa-address-book me-2"></i>Contact Information
                                </h5>
                                
                                <div class="form-group">
                                    <label for="contact_number" class="form-label">
                                        Contact Number
                                    </label>
                                    <input type="tel" 
                                           class="form-control" 
                                           id="contact_number" 
                                           name="contact_number" 
                                           value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" 
                                           placeholder="09171234567"
                                           pattern="^(09|\+639)\d{9}$">
                                    <div class="contact-help mt-1">
                                        Format: 09171234567 or +639171234567 (Optional)
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="address" class="form-label">
                                        Address <span class="required">*</span>
                                    </label>
                                    <textarea class="form-control" 
                                              id="address" 
                                              name="address" 
                                              rows="3" 
                                              required
                                              placeholder="Enter complete address"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="barangay_id" class="form-label">
                                        Barangay <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="barangay_id" name="barangay_id" required>
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                        <option value="<?php echo $barangay['barangay_id']; ?>" 
                                                <?php echo (($_POST['barangay_id'] ?? '') == $barangay['barangay_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($barangay['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Additional Options -->
                            <div class="form-section">
                                <h5 class="section-title">
                                    <i class="fas fa-cogs me-2"></i>Additional Options
                                </h5>
                                
                                <div class="form-check">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           id="add_case" 
                                           name="add_case" 
                                           value="1"
                                           checked
                                           <?php echo (($_POST['add_case'] ?? '1') === '1') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="add_case">
                                        <strong>Add medical case immediately after registration</strong>
                                        <br>
                                        <small class="text-muted">
                                            Recommended: This will create a new case record for dengue symptoms. If the patient already exists, a new case will be added to their existing record.
                                        </small>
                                    </label>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="d-flex justify-content-between align-items-center">
                                <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo me-2"></i>Reset Form
                                </button>
                                
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-user-plus me-2"></i>Add Patient
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Calculate and display age based on date of birth
function calculateAge() {
    const dobInput = document.getElementById('date_of_birth');
    const ageDisplay = document.getElementById('ageDisplay');
    
    if (dobInput.value) {
        const dob = new Date(dobInput.value);
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            age--;
        }
        
        if (age < 0) {
            ageDisplay.textContent = 'Invalid date: Date cannot be in the future';
            ageDisplay.style.color = '#dc3545';
        } else if (age > 120) {
            ageDisplay.textContent = 'Please check the date: Age seems too high';
            ageDisplay.style.color = '#dc3545';
        } else {
            ageDisplay.textContent = `Age: ${age} years old`;
            ageDisplay.style.color = '#6c757d';
        }
    } else {
        ageDisplay.textContent = '';
    }
}

// Format contact number as user types
document.getElementById('contact_number').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
    
    if (value.startsWith('639')) {
        value = '+' + value;
    } else if (value.startsWith('9') && value.length === 10) {
        value = '0' + value;
    }
    
    e.target.value = value;
});

// Reset form function
function resetForm() {
    if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        document.getElementById('addPatientForm').reset();
        document.getElementById('ageDisplay').textContent = '';
    }
}

// Form validation before submit
document.getElementById('addPatientForm').addEventListener('submit', function(e) {
    const firstName = document.getElementById('first_name').value.trim();
    const lastName = document.getElementById('last_name').value.trim();
    const dob = document.getElementById('date_of_birth').value;
    const gender = document.getElementById('gender').value;
    const address = document.getElementById('address').value.trim();
    const barangayId = document.getElementById('barangay_id').value;
    
    let errors = [];
    
    if (!firstName) errors.push('First name is required');
    if (!lastName) errors.push('Last name is required');
    if (!dob) errors.push('Date of birth is required');
    if (!gender) errors.push('Gender is required');
    if (!address) errors.push('Address is required');
    if (!barangayId) errors.push('Barangay is required');
    
    // Check if date is in the future
    if (dob && new Date(dob) > new Date()) {
        errors.push('Date of birth cannot be in the future');
    }
    
    if (errors.length > 0) {
        e.preventDefault();
        alert('Please correct the following errors:\n\n' + errors.join('\n'));
        return false;
    }
    
    return true;
});

// Auto-calculate age when page loads (if date is already filled)
document.addEventListener('DOMContentLoaded', function() {
    calculateAge();
});
</script>

</body>
</html>