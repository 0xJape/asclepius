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
$url = "https://generativelanguage.googleapis.com/v1beta2/models/gemini-pro:generateContent?key=$api_key";

// Get JSON POST body
$input = file_get_contents('php://input');
if (!$input) {
    echo json_encode(["error" => "No input received."]);
    exit;
}

// Validate JSON
$json_data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["error" => "Invalid JSON: " . json_last_error_msg()]);
    exit;
}

// Forward request to Gemini API
$options = [
    "http" => [
        "header" => "Content-Type: application/json\r\n",
        "method" => "POST",
        "content" => $input,
        "timeout" => 30,
        "ignore_errors" => true
    ]
];

$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === FALSE) {
    $error_msg = error_get_last();
    echo json_encode([
        "error" => "Failed to connect to Gemini API.",
        "details" => $error_msg ? $error_msg['message'] : "Unknown error",
        "php_config" => [
            "allow_url_fopen" => ini_get('allow_url_fopen') ? true : false,
            "openssl" => extension_loaded('openssl')
        ]
    ]);
    exit;
}

// Check HTTP response code
$http_code = null;
if (isset($http_response_header)) {
    foreach ($http_response_header as $header) {
        if (strpos($header, 'HTTP/') === 0) {
            $http_code = $header;
            break;
        }
    }
}

// Return Gemini API response
$response = json_decode($result, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        "error" => "Invalid response from Gemini API",
        "raw_response" => substr($result, 0, 500),
        "http_code" => $http_code
    ]);
    exit;
}

echo json_encode($response);
?>
