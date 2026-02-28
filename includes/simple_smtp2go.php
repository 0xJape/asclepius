<?php
/**
 * Simplified SMTP2GO API Client
 * Uses cURL instead of GuzzleHttp to avoid dependency issues
 */

class SimpleSMTP2GO {
    private $apiKey;
    private $apiRegion = 'us';
    private $baseUrl;
    
    public function __construct($apiKey, $region = 'us') {
        $this->apiKey = $apiKey;
        $this->apiRegion = $region;
        $this->baseUrl = $region === 'eu' ? 'https://api.eu.smtp2go.com/v3/' : 'https://api.smtp2go.com/v3/';
    }
    
    /**
     * Send email using SMTP2GO API
     */
    public function sendEmail($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody, $textBody = null) {
        // Validate inputs
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'raw_response' => null,
                'error' => 'Invalid recipient email address: ' . $toEmail
            ];
        }
        
        if (empty($fromEmail) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'raw_response' => null,
                'error' => 'Invalid sender email address: ' . $fromEmail
            ];
        }
        
        $payload = [
            'sender' => $fromEmail,
            'to' => [
                $toEmail // SMTP2GO API expects simple email strings in array
            ],
            'subject' => $subject,
            'html_body' => $htmlBody
        ];
        
        // Add sender name if provided
        if (!empty($fromName)) {
            $payload['sender_name'] = $fromName;
        }
        
        // Add text body if provided
        if (!empty($textBody)) {
            $payload['text_body'] = $textBody;
        }
        
        return $this->makeApiCall('email/send', $payload);
    }
    
    /**
     * Make API call using cURL
     */
    private function makeApiCall($endpoint, $data) {
        $url = $this->baseUrl . $endpoint;
        
        // Add API key to the data payload (SMTP2GO expects it in the body)
        $data['api_key'] = $this->apiKey;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'SimpleSMTP2GO-PHP/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'cURL Error: ' . $error,
                'response' => null
            ];
        }
        
        $decodedResponse = json_decode($response, true);
        
        // More detailed success checking
        $success = false;
        if ($httpCode === 200) {
            if (isset($decodedResponse['data']['succeeded']) && $decodedResponse['data']['succeeded'] > 0) {
                $success = true;
            } elseif (isset($decodedResponse['data']['email_id'])) {
                // Alternative success indicator
                $success = true;
            }
        }
        
        return [
            'success' => $success,
            'http_code' => $httpCode,
            'response' => $decodedResponse,
            'raw_response' => $response,
            'error' => null
        ];
    }
    
    /**
     * Test API connection
     */
    public function testConnection() {
        return $this->makeApiCall('email/send', [
            'sender' => 'test@example.com',
            'to' => [['email' => 'test@example.com']],
            'subject' => 'Test',
            'html_body' => 'Test',
            'test_mode' => true // This won't actually send an email
        ]);
    }
}

/**
 * Enhanced email functions using SimpleSMTP2GO
 */

// Send enhanced test email using simplified API
function sendSimpleTestEmail($testEmail) {
    try {
        // Validate configuration first
        $config = validateEnhancedSMTP2GOConfig();
        if (!$config['valid']) {
            return [
                'success' => false,
                'message' => 'Configuration errors: ' . implode(', ', $config['errors'])
            ];
        }
        
        // Create SimpleSMTP2GO client
        $client = new SimpleSMTP2GO(SMTP2GO_API_KEY, SMTP2GO_API_REGION);
        
        // Generate email content
        $subject = '[DENGUE ALERT TEST] Simplified SMTP2GO Integration Test';
        $htmlBody = generateSimpleTestEmailHTML($testEmail);
        $textBody = generateSimpleTestEmailText($testEmail);
        
        // Send email
        $result = $client->sendEmail(
            SMTP2GO_FROM_EMAIL,
            SMTP2GO_FROM_NAME,
            $testEmail,
            'Test Recipient',
            $subject,
            $htmlBody,
            $textBody
        );
        
        // Log the result
        logEnhancedEmailDelivery(
            $testEmail, 
            $subject, 
            $result['success'] ? 'sent' : 'failed',
            $result['success'] ? null : ($result['error'] ?? 'Unknown error'),
            'simple_smtp2go'
        );
        
        if ($result['success']) {
            return [
                'success' => true,
                'message' => 'Test email sent successfully using SimpleSMTP2GO! Check your inbox.'
            ];
        } else {
            $errorMsg = 'Failed to send email';
            if (isset($result['response']['data']['failures'][0]['error'])) {
                $errorMsg .= ': ' . $result['response']['data']['failures'][0]['error'];
            } elseif (isset($result['error'])) {
                $errorMsg .= ': ' . $result['error'];
            }
            
            return [
                'success' => false,
                'message' => $errorMsg
            ];
        }
        
    } catch (Exception $e) {
        logEnhancedEmailDelivery($testEmail, 'Test Email', 'failed', $e->getMessage(), 'simple_smtp2go');
        return [
            'success' => false,
            'message' => 'Exception: ' . $e->getMessage()
        ];
    }
}

// Test SMTP2GO connection without sending email
function testSimpleSMTP2GOConnection() {
    try {
        $config = validateEnhancedSMTP2GOConfig();
        if (!$config['valid']) {
            return [
                'success' => false,
                'message' => 'Configuration invalid: ' . implode(', ', $config['errors'])
            ];
        }
        
        $client = new SimpleSMTP2GO(SMTP2GO_API_KEY, SMTP2GO_API_REGION);
        $result = $client->testConnection();
        
        if ($result['success'] || $result['http_code'] === 200) {
            return [
                'success' => true,
                'message' => 'SMTP2GO API connection successful!'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'API connection failed: ' . ($result['error'] ?? 'HTTP ' . $result['http_code'])
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Connection test failed: ' . $e->getMessage()
        ];
    }
}

// Generate simple HTML email for testing
function generateSimpleTestEmailHTML($testEmail) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 30px 20px; text-align: center; }
            .content { padding: 30px 20px; }
            .success-box { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 6px; margin: 15px 0; }
            .info-box { background: #f8f9fa; padding: 20px; margin: 20px 0; border-left: 4px solid #007bff; border-radius: 6px; }
            .footer { text-align: center; margin-top: 30px; color: #6c757d; font-size: 12px; background: #f8f9fa; padding: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎉 SimpleSMTP2GO Success!</h1>
                <p>Dengue Early Warning System - Simplified Email Test</p>
            </div>
            
            <div class='content'>
                <div class='success-box'>
                    <h3>✅ Email Delivery Successful!</h3>
                    <p>Your SimpleSMTP2GO integration is working perfectly! This email was sent using a dependency-free implementation.</p>
                </div>
                
                <div class='info-box'>
                    <h4>📧 Test Details:</h4>
                    <strong>Test Email:</strong> " . htmlspecialchars($testEmail) . "<br>
                    <strong>Method:</strong> SimpleSMTP2GO (cURL-based)<br>
                    <strong>API Region:</strong> " . SMTP2GO_API_REGION . "<br>
                    <strong>From:</strong> " . SMTP2GO_FROM_NAME . " &lt;" . SMTP2GO_FROM_EMAIL . "&gt;<br>
                    <strong>Timestamp:</strong> " . date('Y-m-d H:i:s T') . "
                </div>
                
                <div class='success-box'>
                    <h4>🔧 Solution Features:</h4>
                    <ul>
                        <li>✅ No GuzzleHttp dependency required</li>
                        <li>✅ Uses native PHP cURL</li>
                        <li>✅ Lightweight and fast</li>
                        <li>✅ Compatible with manual installation</li>
                        <li>✅ Perfect for dengue alert system</li>
                    </ul>
                </div>
            </div>
            
            <div class='footer'>
                <p>This email was sent using SimpleSMTP2GO - a dependency-free SMTP2GO implementation.<br>
                Ready for production dengue monitoring alerts!</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

// Generate simple text email for testing
function generateSimpleTestEmailText($testEmail) {
    return "
===========================================
🎉 SimpleSMTP2GO SUCCESS!
===========================================

DENGUE EARLY WARNING SYSTEM
Simplified Email Integration Test

✅ Email delivery successful!
Your SimpleSMTP2GO integration is working perfectly!

-------------------------------------------
📧 TEST DETAILS
-------------------------------------------
Test Email: $testEmail
Method: SimpleSMTP2GO (cURL-based)
API Region: " . SMTP2GO_API_REGION . "
From: " . SMTP2GO_FROM_NAME . " <" . SMTP2GO_FROM_EMAIL . ">
Timestamp: " . date('Y-m-d H:i:s T') . "

-------------------------------------------
🔧 SOLUTION FEATURES
-------------------------------------------
✅ No GuzzleHttp dependency required
✅ Uses native PHP cURL
✅ Lightweight and fast  
✅ Compatible with manual installation
✅ Perfect for dengue alert system

-------------------------------------------
READY FOR PRODUCTION!
-------------------------------------------
This email was sent using SimpleSMTP2GO - a dependency-free
SMTP2GO implementation. Your dengue monitoring system is 
ready to send professional email alerts!

System powered by Advanced Dengue Monitoring Platform
===========================================
    ";
}

?>
