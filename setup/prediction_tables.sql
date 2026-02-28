-- Add prediction-related tables to the existing schema
-- For database: asclpe_db

-- Table to store prediction models configuration
CREATE TABLE IF NOT EXISTS prediction_models (
    model_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    parameters TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table to store prediction results
CREATE TABLE IF NOT EXISTS predictions (
    prediction_id INT PRIMARY KEY AUTO_INCREMENT,
    model_id INT NOT NULL,
    barangay_id INT NOT NULL,
    prediction_date DATE NOT NULL,
    target_date DATE NOT NULL,
    predicted_cases INT NOT NULL,
    confidence_level DECIMAL(5,2),
    risk_level ENUM('Low', 'Moderate', 'High', 'Critical') NOT NULL,
    risk_score INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES prediction_models(model_id) ON DELETE RESTRICT,
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table to store prediction accuracy metrics
CREATE TABLE IF NOT EXISTS prediction_accuracy (
    accuracy_id INT PRIMARY KEY AUTO_INCREMENT,
    prediction_id INT NOT NULL,
    actual_cases INT,
    error_margin DECIMAL(5,2),
    verified_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prediction_id) REFERENCES predictions(prediction_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table to store environmental factors that might affect predictions
CREATE TABLE IF NOT EXISTS environmental_factors (
    factor_id INT PRIMARY KEY AUTO_INCREMENT,
    barangay_id INT NOT NULL,
    date_recorded DATE NOT NULL,
    temperature DECIMAL(4,1),
    humidity DECIMAL(4,1),
    rainfall DECIMAL(6,2),
    water_source_count INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default prediction model
INSERT INTO prediction_models (name, description, parameters) 
VALUES (
    'Moving Average Model',
    'Simple moving average model for dengue case prediction based on historical data',
    '{"window_size": 7, "trend_factor": 1.2, "seasonal_adjust": true}'
);

-- Create a view to easily get prediction accuracy
CREATE OR REPLACE VIEW prediction_performance AS
SELECT 
    p.prediction_id,
    p.barangay_id,
    b.name as barangay_name,
    p.prediction_date,
    p.target_date,
    p.predicted_cases,
    pa.actual_cases,
    p.risk_level,
    p.risk_score,
    pa.error_margin,
    CASE 
        WHEN pa.actual_cases IS NOT NULL THEN
            (1 - ABS(p.predicted_cases - pa.actual_cases) / GREATEST(pa.actual_cases, 1)) * 100
        ELSE NULL
    END as accuracy_percent
FROM predictions p
LEFT JOIN prediction_accuracy pa ON p.prediction_id = pa.prediction_id
JOIN barangays b ON p.barangay_id = b.barangay_id
ORDER BY p.target_date DESC;
