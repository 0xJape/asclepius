<?php
// Gemini API Proxy for browser requests
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$api_key = "AIzaSyCEHUna3NNvDBQ8H-J5oIrUqXCPbrDBTRE";
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent";

// Get JSON POST body
$input = file_get_contents('php://input');
if (!$input) {
    http_response_code(400);
    echo json_encode(["error" => "No input received.", "code" => 400]);
    exit;
}

// Validate JSON
$json_data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON: " . json_last_error_msg(), "code" => 400]);
    exit;
}

// Use cURL for better error handling
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $api_key
    ],
    CURLOPT_POSTFIELDS => $input,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => true
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// Handle cURL errors
if ($curlErrno) {
    http_response_code(503);
    echo json_encode([
        "error" => "Connection failed: $curlError",
        "code" => 503,
        "type" => "connection_error"
    ]);
    exit;
}

// Handle empty response
if (empty($result)) {
    http_response_code(502);
    echo json_encode([
        "error" => "Empty response from AI service",
        "code" => 502,
        "type" => "empty_response"
    ]);
    exit;
}

// Parse response
$response = json_decode($result, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode([
        "error" => "Invalid JSON response from AI service",
        "code" => 502,
        "type" => "parse_error"
    ]);
    exit;
}

// Handle API errors with user-friendly messages
if (isset($response['error'])) {
    $errorCode = $response['error']['code'] ?? $httpCode;
    $errorMsg = $response['error']['message'] ?? 'Unknown error';
    
    http_response_code($errorCode >= 400 && $errorCode < 600 ? $errorCode : 500);
    
    $userMessage = match(true) {
        $errorCode == 400 => "Invalid request format or content",
        $errorCode == 401 || $errorCode == 403 => "API authentication failed",
        $errorCode == 404 => "AI model not available",
        $errorCode == 429 => "Rate limit exceeded. Please wait a moment",
        $errorCode >= 500 => "AI service temporarily unavailable",
        default => "AI service error"
    };
    
    echo json_encode([
        "error" => $userMessage,
        "details" => $errorMsg,
        "code" => $errorCode,
        "type" => "api_error"
    ]);
    exit;
}

// Return successful response
http_response_code(200);
echo json_encode($response);
?>
