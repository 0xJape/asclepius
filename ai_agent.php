<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Verify user is logged in
checkAuth();

// Get current user info safely
$user = isset($_SESSION['user']) ? $_SESSION['user'] : ['username' => 'Administrator'];

// Handle POST request for AI chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    header('Content-Type: application/json');
    $userMessage = trim($_POST['message']);
    
    if (empty($userMessage)) {
        echo json_encode(['response' => 'Please provide a message.']);
        exit;
    }
    
    $aiResponse = getSimpleGeminiResponse($userMessage);
    echo json_encode([
        'response' => $aiResponse,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Simple AI response function for the AI agent page
function getSimpleGeminiResponse($userMessage) {
    // Get basic dengue data
    $db = getDBConnection();
    
    // Get total cases
    $stmt = $db->query("SELECT COUNT(*) as total_cases FROM patient_cases");
    $totalCases = $stmt->fetch(PDO::FETCH_ASSOC)['total_cases'];
    
    // Get recent cases (last 7 days)
    $stmt = $db->query("SELECT COUNT(*) as recent_cases FROM patient_cases WHERE date_reported >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $recentCases = $stmt->fetch(PDO::FETCH_ASSOC)['recent_cases'];
    
    // Build context for AI
    $context = "ASCLEPIUS Dengue Surveillance System Data:\n";
    $context .= "Total Cases: $totalCases\n";
    $context .= "Recent Cases (7 days): $recentCases\n\n";
    $context .= "You are the ASCLEPIUS AI assistant for dengue surveillance. Provide helpful, accurate responses about dengue prevention, monitoring, and the data shown above.";
    
    // Call Gemini API
    $apiKey = "AIzaSyDtdoTf66gjbtdw3DC1FmFcrALoRetFQjc";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=$apiKey";
    
    $requestBody = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $context . "\n\nUser Question: " . $userMessage]
                ]
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return "I'm experiencing technical difficulties right now. Please try asking about dengue prevention, current cases, or risk assessment.";
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        return $data['candidates'][0]['content']['parts'][0]['text'];
    }
    
    return "I'm here to help with dengue surveillance questions. You can ask me about current cases, prevention tips, or risk assessment.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASCLEPIUS AI Agent - Dengue Surveillance System</title>
    <meta name="description" content="AI-powered dengue surveillance and prediction system">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/dengue_logo.png">
    
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
    
    <!-- Dashboard CSS -->
    <link href="assets/css/dashboard.css" rel="stylesheet">
    
    <style>
        /* Override dashboard background for chat interface */
        .main-content {
            background: #f5f7fa;
            padding: 20px;
        }
        
        .chat-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .chat-panel {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 0;
            overflow: hidden;
        }
        
        .chat-header {
            padding: 32px 32px 20px 32px;
            background: linear-gradient(135deg, #10b981, #047857);
            color: white;
        }
        
        .chat-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            color: white;
        }
        
        .chat-header .subtitle {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 400;
        }
        
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 16px;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .chat-section {
            padding: 32px;
        }
        
        .chat-section h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 24px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .chat-container {
            height: 400px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            background-color: #f8fafc;
            margin-bottom: 20px;
        }
        
        .message {
            margin-bottom: 20px;
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .user-message {
            display: flex;
            justify-content: flex-end;
        }
        
        .user-message .message-content {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            padding: 12px 16px;
            border-radius: 18px 18px 4px 18px;
            max-width: 70%;
            font-weight: 500;
        }
        
        .bot-message {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        
        .bot-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 4px;
        }
        
        .bot-message .message-content {
            background: white;
            border: 1px solid #e2e8f0;
            padding: 16px;
            border-radius: 18px 18px 18px 4px;
            max-width: 85%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .message-input-container {
            position: relative;
            margin-top: 20px;
        }
        
        .message-input {
            width: 100%;
            padding: 16px 60px 16px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 25px;
            font-size: 16px;
            outline: none;
            transition: all 0.3s ease;
            background: white;
        }
        
        .message-input:focus {
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }
        
        .send-button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .send-button:hover {
            transform: translateY(-50%) scale(1.05);
            box-shadow: 0 4px 12px rgba(66, 153, 225, 0.3);
        }
        
        .send-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: translateY(-50%);
        }
        
        .typing-indicator {
            display: none;
            margin-top: 12px;
            color: #718096;
            font-style: italic;
            align-items: center;
            gap: 8px;
        }
        
        .typing-dots {
            display: flex;
            gap: 4px;
        }
        
        .typing-dot {
            width: 6px;
            height: 6px;
            background: #cbd5e0;
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }
        
        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }
        
        .suggestions-panel {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 24px;
            height: fit-content;
        }
        
        .suggestions-panel h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .suggestion-item {
            padding: 16px 0;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .suggestion-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .suggestion-item:hover {
            color: #4299e1;
            transform: translateX(4px);
        }
        
        .suggestion-title {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 15px;
        }
        
        .suggestion-desc {
            font-size: 13px;
            color: #718096;
            line-height: 1.4;
        }
        
        @media (max-width: 1024px) {
            .chat-layout {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .suggestions-panel {
                order: -1;
                padding: 20px;
            }
            
            .suggestion-item {
                padding: 12px 0;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .chat-header {
                padding: 24px 24px 16px 24px;
            }
            
            .chat-header h1 {
                font-size: 24px;
            }
            
            .chat-section {
                padding: 24px;
            }
            
            .chat-container {
                height: 300px;
            }
            
            .message-input {
                padding: 14px 50px 14px 18px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar Navigation -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <i class="fas fa-heartbeat me-2"></i>
                    <span>ASCLEPIUS</span>
                </div>
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
                    <a href="ai_agent.php" class="menu-item active">
                        <i class="fas fa-robot"></i>
                        <span>AI Agent</span>
                    </a>
                </div>
            </div>
            
            <div class="sidebar-footer">
                <div class="user-profile">
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        <div class="user-role">Administrator</div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="chat-layout">
                <!-- Chat Panel -->
                <div class="chat-panel">
                    <div class="chat-header">
                        <h1>AI Agent</h1>
                        <div class="subtitle">ASCLEPIUS Intelligence Assistant</div>
                        <div class="status-indicator">
                            <div class="status-dot"></div>
                            AI Active
                        </div>
                    </div>
                    
                    <div class="chat-section">
                        <h2>
                            <i class="fas fa-robot"></i>
                            Chat with ASCLEPIUS
                        </h2>
                        
                        <div class="chat-container" id="chatContainer">
                            <div class="message bot-message">
                                <div class="bot-avatar">
                                    <i class="fas fa-robot" style="color: white; font-size: 14px;"></i>
                                </div>
                                <div class="message-content">
                                    <strong>ASCLEPIUS:</strong> Hello! I'm your AI-powered dengue surveillance assistant. I can help you analyze trends, predict risks, and provide actionable insights for dengue prevention. How can I assist you today?
                                </div>
                            </div>
                        </div>
                        
                        <div class="typing-indicator" id="typingIndicator">
                            <div class="bot-avatar">
                                <i class="fas fa-robot" style="color: white; font-size: 12px;"></i>
                            </div>
                            <div class="typing-dots">
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                                <div class="typing-dot"></div>
                            </div>
                        </div>
                        
                        <form id="chatForm" class="message-input-container">
                            <input type="text" class="message-input" id="messageInput" 
                                   placeholder="Ask me about dengue surveillance, predictions, or recommendations..." 
                                   autocomplete="off" required>
                            <button type="submit" class="send-button" id="sendButton">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Suggestions Panel -->
                <div class="suggestions-panel">
                    <h3>
                        <i class="fas fa-bolt text-success"></i>
                        Quick Actions
                    </h3>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('What is the current dengue situation?')">
                        <div class="suggestion-title">Current Situation</div>
                        <div class="suggestion-desc">What is the current dengue case count in our area?</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Predict dengue risk for next week')">
                        <div class="suggestion-title">Risk Prediction</div>
                        <div class="suggestion-desc">What does the ASCLEPIUS model predict for upcoming weeks?</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Show prevention recommendations')">
                        <div class="suggestion-title">Prevention Tips</div>
                        <div class="suggestion-desc">What are the best strategies to prevent dengue outbreaks?</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Analyze recent case patterns')">
                        <div class="suggestion-title">Pattern Analysis</div>
                        <div class="suggestion-desc">How have dengue cases changed over recent weeks?</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Which barangays are high-risk areas?')">
                        <div class="suggestion-title">High-Risk Areas</div>
                        <div class="suggestion-desc">Show me the barangays with highest dengue risk levels</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('How does weather affect dengue transmission?')">
                        <div class="suggestion-title">Weather Impact</div>
                        <div class="suggestion-desc">Analyze temperature and humidity effects on dengue spread</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Show historical dengue trends from 2014-2024')">
                        <div class="suggestion-title">Historical Analysis</div>
                        <div class="suggestion-desc">View long-term trends and patterns from our database</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Generate alert recommendations for officials')">
                        <div class="suggestion-title">Alert System</div>
                        <div class="suggestion-desc">Who should be notified based on current risk levels?</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Validate ASCLEPIUS model accuracy')">
                        <div class="suggestion-title">Model Validation</div>
                        <div class="suggestion-desc">How accurate are our mathematical predictions?</div>
                    </div>
                    
                    <div class="suggestion-item" onclick="sendQuickMessage('Show demographic analysis of cases')">
                        <div class="suggestion-title">Demographics</div>
                        <div class="suggestion-desc">Analyze cases by age, gender, and location patterns</div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function addMessage(message, isUser = false) {
            const chatContainer = document.getElementById('chatContainer');
            const messageDiv = document.createElement('div');
            
            if (isUser) {
                messageDiv.className = 'message user-message';
                messageDiv.innerHTML = `
                    <div class="message-content">
                        <strong>You:</strong> ${message}
                    </div>
                `;
            } else {
                messageDiv.className = 'message bot-message';
                messageDiv.innerHTML = `
                    <div class="bot-avatar">
                        <i class="fas fa-robot" style="color: white; font-size: 14px;"></i>
                    </div>
                    <div class="message-content">
                        <strong>ASCLEPIUS:</strong> ${message}
                    </div>
                `;
            }
            
            chatContainer.appendChild(messageDiv);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        function sendQuickMessage(message) {
            document.getElementById('messageInput').value = message;
            document.getElementById('chatForm').dispatchEvent(new Event('submit'));
        }

        document.getElementById('chatForm').onsubmit = async function(e) {
            e.preventDefault();
            
            const messageInput = document.getElementById('messageInput');
            const sendButton = document.getElementById('sendButton');
            const typingIndicator = document.getElementById('typingIndicator');
            const message = messageInput.value.trim();
            
            if (!message) return;
            
            // Add user message
            addMessage(message, true);
            
            // Show typing indicator
            typingIndicator.style.display = 'flex';
            sendButton.disabled = true;
            messageInput.value = '';
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'message=' + encodeURIComponent(message)
                });
                
                const data = await response.json();
                addMessage(data.response || 'Sorry, I could not process your request.');
                
            } catch (error) {
                addMessage('Sorry, I encountered a connection error. Please try again.');
            } finally {
                typingIndicator.style.display = 'none';
                sendButton.disabled = false;
                messageInput.focus();
            }
        };

        // Focus on input when page loads
        document.getElementById('messageInput').focus();
        
        // Auto-resize chat container on window resize
        window.addEventListener('resize', function() {
            const chatContainer = document.getElementById('chatContainer');
            chatContainer.scrollTop = chatContainer.scrollHeight;
        });
    </script>
</body>
</html>