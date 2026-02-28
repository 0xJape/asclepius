-- Drop existing tables if they exist
SET FOREIGN_KEY_CHECKS=0;  -- Temporarily disable foreign key checks

DROP TABLE IF EXISTS cases_log;
DROP TABLE IF EXISTS patient_cases;
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS barangays;
DROP TABLE IF EXISTS admins;

SET FOREIGN_KEY_CHECKS=1;  -- Re-enable foreign key checks

-- Create barangays table
CREATE TABLE barangays (
    barangay_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    population INT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create patients table
CREATE TABLE patients (
    patient_id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    date_of_birth DATE NOT NULL,
    gender ENUM('Male', 'Female', 'Other') NOT NULL,
    contact_number VARCHAR(20),
    address TEXT NOT NULL,
    barangay_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create patient cases table
CREATE TABLE patient_cases (
    case_id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    date_reported DATE NOT NULL,
    symptoms TEXT,
    status ENUM('Mild', 'Moderate', 'Severe', 'Critical', 'Recovered', 'Deceased') NOT NULL DEFAULT 'Mild',
    temperature DECIMAL(4,1),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create admins table
CREATE TABLE admins (
    admin_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL, -- Will store hashed passwords
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    role ENUM('Admin', 'Super Admin') NOT NULL DEFAULT 'Admin',
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin account
INSERT INTO admins (username, password, first_name, last_name, email, role) 
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: 'password'
    'System',
    'Administrator',
    'admin@asclpe.local',
    'Super Admin'
);

-- Insert sample barangays
INSERT INTO barangays (name, population) VALUES 
('Acmonan', 2500),
('Bunao', 3000),
('Cebuano', 2800),
('Crossing Rubber', 3500),
('Kablon', 4000),
('Kalkam', 2700),
('Linan', 3200),
('Poblacion', 8000),
('Polonuling', 2900),
('Simbo', 2600),
('Tubeng', 2700),
('Lunen', 3200);

-- Add indexes for better query performance
ALTER TABLE patients ADD INDEX idx_barangay (barangay_id);
ALTER TABLE patient_cases ADD INDEX idx_patient (patient_id);
ALTER TABLE patient_cases ADD INDEX idx_date_reported (date_reported);
ALTER TABLE patients ADD INDEX idx_name (last_name, first_name);

UPDATE barangays SET latitude = 6.3221108, longitude = 125.0112782 WHERE barangay_id = 1; -- Acmonan
UPDATE barangays SET latitude = 6.3027709, longitude = 124.9423933 WHERE barangay_id = 2; -- Bunao
UPDATE barangays SET latitude = 6.3621533, longitude = 124.9561748 WHERE barangay_id = 3; -- Cebuano
UPDATE barangays SET latitude = 6.3380938, longitude = 124.9280742 WHERE barangay_id = 4; -- Crossing Rubber
UPDATE barangays SET latitude = 6.3098751, longitude = 125.0250484 WHERE barangay_id = 5; -- Kablon
UPDATE barangays SET latitude = 6.3170390, longitude = 124.9313665 WHERE barangay_id = 6; -- Kalkam
UPDATE barangays SET latitude = 6.3702789, longitude = 124.9839858 WHERE barangay_id = 7; -- Linan
UPDATE barangays SET latitude = 6.3254884, longitude = 124.9671983 WHERE barangay_id = 8; -- Poblacion
UPDATE barangays SET latitude = 6.2862549, longitude = 124.9710942 WHERE barangay_id = 9; -- Polonuling
UPDATE barangays SET latitude = 6.2547646, longitude = 124.9561748 WHERE barangay_id = 10; -- Simbo
UPDATE barangays SET latitude = 6.3134792, longitude = 124.8900033 WHERE barangay_id = 11; -- Tubeng
UPDATE barangays SET latitude = 6.3117971, longitude = 124.9120662 WHERE barangay_id = 12; -- Lunen
