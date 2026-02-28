-- Update Barangay Coordinates for Tupi, South Cotabato
-- This script adds missing barangays and updates coordinates

-- First, update existing barangay coordinates
UPDATE barangays SET latitude = 6.3369, longitude = 124.9493 WHERE barangay_id = 9; -- Poblacion
UPDATE barangays SET latitude = 6.3454, longitude = 124.9937 WHERE barangay_id = 1; -- Acmonan
UPDATE barangays SET latitude = 6.316306, longitude = 125.0138 WHERE barangay_id = 5; -- Kablon
UPDATE barangays SET latitude = 6.2857, longitude = 124.9679 WHERE barangay_id = 10; -- Polonuling
UPDATE barangays SET latitude = 6.3346, longitude = 124.9220 WHERE barangay_id = 6; -- Kalkam
UPDATE barangays SET latitude = 6.3390, longitude = 124.9007 WHERE barangay_id = 17; -- Lunen
UPDATE barangays SET latitude = 6.3280, longitude = 124.9403 WHERE barangay_id = 2; -- Bunao
UPDATE barangays SET latitude = 6.3808, longitude = 124.9623 WHERE barangay_id = 3; -- Cebuano
UPDATE barangays SET latitude = 6.3651, longitude = 124.9918 WHERE barangay_id = 7; -- Linan
UPDATE barangays SET latitude = 6.3631, longitude = 124.9192 WHERE barangay_id = 4; -- Crossing Rubber
UPDATE barangays SET latitude = 6.3696, longitude = 124.8873 WHERE barangay_id = 15; -- Tubeng
UPDATE barangays SET latitude = 6.2480, longitude = 124.9582 WHERE barangay_id = 11; -- Simbo

-- Add missing barangays to fill the gaps in barangay_id sequence
INSERT INTO barangays (barangay_id, name, latitude, longitude) VALUES
(8, 'Palian', 6.3725, 124.9100),
(12, 'Bololmala', 6.3926, 124.9206),
(13, 'Miasong', 6.4129, 125.0935),
(14, 'Juan Loreto Tamayo', 6.3965, 125.0521);

-- Display final result
SELECT barangay_id, name, latitude, longitude 
FROM barangays 
ORDER BY barangay_id;
