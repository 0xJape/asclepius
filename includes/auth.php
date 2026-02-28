<?php
// Basic authentication stub
function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
    if (!isset($_SESSION['user_name'])) {
        $_SESSION['user_name'] = 'Demo User'; // fallback for demo
    }
}

// Demo user for testing
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['user_name'] = 'Demo User';
}

// This file now only contains authentication-related functions
// All data-related functions have been moved to config.php
