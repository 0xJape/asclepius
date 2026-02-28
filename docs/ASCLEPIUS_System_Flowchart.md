# 🏥 ASCLEPIUS Dengue Monitoring System - Flow Diagram

## 📊 **Main System Flow**

```mermaid
graph TD
    A[👤 User Login] --> B{Authentication}
    B -->|Valid| C[🏠 Dashboard]
    B -->|Invalid| A
    
    C --> D[📊 Real-time Statistics]
    C --> E[👥 Patient Management]
    C --> F[🔮 Prediction System]
    C --> G[🌦️ Weather Data]
    C --> H[🤖 AI Chatbot]
    C --> I[🚨 Alert System]
    C --> J[📍 Geographic View]
    
    %% Patient Management Flow
    E --> E1[➕ Add Patient]
    E --> E2[📝 Edit Patient]
    E --> E3[👁️ View Patient]
    E --> E4[📁 Case History]
    E1 --> E5[(🗄️ MySQL Database)]
    E2 --> E5
    E3 --> E5
    E4 --> E5
    
    %% Prediction System Flow
    F --> F1[📈 Historical Analysis]
    F --> F2[🌡️ Weather Correlation]
    F --> F3[🔢 Regression Models]
    F1 --> F4[📊 Generate Predictions]
    F2 --> F4
    F3 --> F4
    F4 --> F5[(🗄️ Save Predictions)]
    
    %% Weather Integration
    G --> G1[🌐 Open-Meteo API]
    G1 --> G2[📡 Fetch Data]
    G2 --> G3[📊 Display Weather]
    G2 --> F2
    
    %% AI Chatbot Flow
    H --> H1[💬 User Query]
    H1 --> H2[📊 Fetch Real-time Data]
    H2 --> H3[🤖 Google Gemini AI]
    H3 --> H4[💡 AI Response]
    H4 --> H5[📱 Display Answer]
    
    %% Alert System Flow
    I --> I1{📊 Check Thresholds}
    I1 -->|Exceeded| I2[🚨 Generate Alert]
    I1 -->|Normal| I3[✅ No Action]
    I2 --> I4[📧 Send Email via SMTP2GO]
    I4 --> I5[📱 Notify Officials]
    
    %% Geographic View
    J --> J1[🗺️ Leaflet Map]
    J1 --> J2[📍 Plot Cases]
    J2 --> J3[🎨 Risk Visualization]
    
    %% Data Flow to Database
    E5 --> K[📊 Analytics Engine]
    F5 --> K
    K --> D
    K --> I1
    
    style A fill:#e1f5fe
    style C fill:#f3e5f5
    style H fill:#fff3e0
    style I fill:#ffebee
    style E5 fill:#e8f5e8
    style G1 fill:#f0f4c3
```

## 🔄 **Data Processing Flow**

```mermaid
graph LR
    A[📝 Data Input] --> B[✅ Validation]
    B --> C[🗄️ Database Storage]
    C --> D[📊 Data Processing]
    D --> E[📈 Analytics]
    E --> F[🎯 Insights]
    F --> G[📱 Display]
    F --> H[🚨 Alerts]
    F --> I[🤖 AI Context]
    
    %% Parallel Processing
    C --> J[🌦️ Weather Correlation]
    C --> K[🔮 Prediction Model]
    J --> E
    K --> E
    
    style A fill:#e3f2fd
    style C fill:#e8f5e8
    style E fill:#fff8e1
    style H fill:#ffebee
```

## 🤖 **AI Chatbot Processing Flow**

```mermaid
graph TD
    A[💬 User Input] --> B[📊 Data Collection]
    
    B --> B1[👥 Patient Data]
    B --> B2[📈 Case Statistics]  
    B --> B3[🌦️ Weather Data]
    B --> B4[🔮 Predictions]
    B --> B5[🏛️ Officials Data]
    B --> B6[📊 Historical Data]
    
    B1 --> C[🧠 Context Building]
    B2 --> C
    B3 --> C
    B4 --> C
    B5 --> C
    B6 --> C
    
    C --> D[🤖 Gemini AI Processing]
    D --> E[💡 Response Generation]
    E --> F[📱 User Interface]
    
    style A fill:#e8eaf6
    style C fill:#fff3e0
    style D fill:#f3e5f5
    style F fill:#e0f2f1
```

## 🚨 **Alert System Flow**

```mermaid
graph TD
    A[📊 Continuous Monitoring] --> B{🔍 Check Conditions}
    
    B -->|Cases > 5/week| C[🚨 High Alert]
    B -->|Cases 3-5/week| D[⚠️ Medium Alert] 
    B -->|Cases < 3/week| E[✅ Normal Status]
    
    C --> F[📧 Email Notification]
    D --> F
    
    F --> G[🏛️ Get Officials List]
    G --> H[📱 Send to Barangay Captain]
    G --> I[📱 Send to Health Officer]
    G --> J[📱 Send to Primary Contact]
    
    H --> K[📋 Log Alert]
    I --> K
    J --> K
    
    style A fill:#e8f5e8
    style C fill:#ffebee
    style D fill:#fff8e1
    style E fill:#e8f5e8
    style F fill:#f3e5f5
```

## 📈 **Prediction System Flow**

```mermaid
graph TD
    A[📊 Historical Data] --> B[🔢 Data Analysis]
    C[🌦️ Weather Data] --> B
    
    B --> D[📈 Trend Calculation]
    B --> E[🌡️ Weather Correlation]
    
    D --> F[🤖 Regression Model]
    E --> F
    
    F --> G[🔮 14-Day Prediction]
    G --> H[📊 Confidence Level]
    G --> I[⚠️ Risk Assessment]
    
    H --> J[(🗄️ Save Results)]
    I --> J
    
    J --> K[📱 Dashboard Display]
    J --> L[🤖 AI Context]
    J --> M[🚨 Alert System]
    
    style A fill:#e3f2fd
    style C fill:#f0f4c3
    style F fill:#fff3e0
    style G fill:#f3e5f5
    style J fill:#e8f5e8
```

## 🗺️ **Geographic Visualization Flow**

```mermaid
graph TD
    A[📊 Case Data] --> B[📍 Coordinate Mapping]
    C[🏘️ Barangay Boundaries] --> D[🗺️ Leaflet Map]
    
    B --> D
    B --> E[🎨 Risk Color Coding]
    E --> D
    
    D --> F[📍 Interactive Markers]
    D --> G[🔥 Heat Map Layer]
    D --> H[📊 Case Clustering]
    
    F --> I[👆 Click Events]
    G --> I
    H --> I
    
    I --> J[📋 Case Details Popup]
    
    style A fill:#e3f2fd
    style D fill:#e8f5e8
    style E fill:#fff8e1
    style J fill:#f3e5f5
```

## 🌐 **System Architecture Overview**

```mermaid
graph TB
    subgraph "Frontend Layer"
        A[🎨 HTML/CSS/JS]
        B[📊 Chart.js]
        C[🗺️ Leaflet.js]
        D[🎯 Bootstrap]
    end
    
    subgraph "Backend Layer"
        E[⚡ PHP Core]
        F[🔐 Authentication]
        G[📊 Data Processing]
        H[🚨 Alert Engine]
    end
    
    subgraph "AI Layer"
        I[🤖 Gemini API]
        J[🔄 n8n Workflows]
        K[🧠 Context Builder]
    end
    
    subgraph "Data Layer"
        L[(🗄️ MySQL Database)]
        M[🌦️ Weather API]
        N[📧 SMTP2GO]
    end
    
    A --> E
    B --> E
    C --> E
    D --> E
    
    E --> L
    F --> L
    G --> L
    H --> N
    
    G --> I
    K --> I
    J --> I
    
    E --> M
    L --> G
    
    style A fill:#e3f2fd
    style E fill:#fff3e0
    style I fill:#f3e5f5
    style L fill:#e8f5e8
```

## 📱 **User Interface Flow**

```mermaid
graph TD
    A[🏠 Login Page] --> B[📊 Dashboard]
    
    B --> C[📈 Analytics View]
    B --> D[👥 Patients View] 
    B --> E[🔮 Predictions View]
    B --> F[🌦️ Weather View]
    B --> G[🤖 Chatbot View]
    B --> H[🚨 Alerts View]
    B --> I[🗺️ Map View]
    
    C --> C1[📊 Charts & Graphs]
    D --> D1[📋 Patient List]
    D --> D2[➕ Add Patient]
    D --> D3[📝 Edit Patient]
    E --> E1[📈 Trend Analysis]
    E --> E2[🔮 Forecasts]
    F --> F1[🌡️ Current Weather]
    F --> F2[📅 7-Day Forecast]
    G --> G1[💬 Chat Interface]
    G --> G2[⚡ Quick Questions]
    H --> H1[🚨 Active Alerts]
    H --> H2[📧 Send Alert]
    I --> I1[📍 Case Locations]
    I --> I2[🎨 Risk Zones]
    
    style A fill:#e1f5fe
    style B fill:#f3e5f5
    style G fill:#fff3e0
    style H fill:#ffebee
```

---

## 🔄 **Complete System Workflow Summary**

1. **🔐 Authentication** → User login and session management
2. **📊 Data Collection** → Patient cases, weather, historical data
3. **🔢 Processing** → Analytics, predictions, correlations
4. **🤖 AI Integration** → Intelligent responses and insights
5. **📱 Visualization** → Dashboard, maps, charts
6. **🚨 Monitoring** → Alert thresholds and notifications
7. **📧 Communication** → Email alerts to officials
8. **🔄 Feedback Loop** → Continuous data updates and improvements

This flowchart represents the complete ASCLEPIUS system architecture and data flow! 🎯