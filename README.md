# ASCLEPIUS - Dengue Early Warning System

<p align="center">
  <img src="assets/dengue_logo.png" alt="ASCLEPIUS Logo" width="120">
</p>

<p align="center">
  <strong>AI-Powered Dengue Surveillance & Prediction System</strong><br>
  For Tupi, South Cotabato, Philippines
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.x-777BB4?style=flat-square&logo=php" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=flat-square&logo=bootstrap&logoColor=white" alt="Bootstrap">
  <img src="https://img.shields.io/badge/Gemini-AI-4285F4?style=flat-square&logo=google&logoColor=white" alt="Gemini AI">
</p>

---

## Overview

**ASCLEPIUS** (Advanced Surveillance and Control Linkage for Epidemiological Prediction and Intervention Using Statistics) is a comprehensive dengue surveillance and early warning system designed for barangay health administrators in Tupi, South Cotabato.

The system combines real-time case monitoring, weather data integration, and AI-powered predictions to help health officials make data-driven decisions for dengue prevention and outbreak response.

## Features

### Core Modules

| Module | Description |
|--------|-------------|
| **Dashboard** | Real-time overview with case statistics, risk maps, and trend charts |
| **Patient Management** | Complete patient records with case history tracking |
| **Risk Prediction** | ASCLEPIUS MLR model for dengue outbreak forecasting |
| **Analytics** | Comprehensive data analysis with exportable reports |
| **Alerts** | Automated email notifications to barangay officials |
| **AI Chatbot** | Gemini-powered assistant for data queries and insights |

### Key Capabilities

- **Real-time Monitoring** - Track active, recovered, and critical cases across 15 barangays
- **Weather Integration** - Live weather data from Open-Meteo API for risk correlation
- **Predictive Modeling** - ASCLEPIUS MLR formula: `y = -72.612471 + 0.00905443P + 2.447256T - 0.0778633H`
- **Interactive Maps** - GeoJSON-based visualization of dengue hotspots
- **Automated Alerts** - Email notifications via SMTP2GO when thresholds are exceeded
- **AI Analysis** - Natural language queries about dengue data and predictions

## Technology Stack

```
Backend:        PHP 8.x
Database:       MySQL/MariaDB
Frontend:       HTML5, CSS3, JavaScript
UI Framework:   Bootstrap 5.3.0
Icons:          Font Awesome 6.4.0
Fonts:          Poppins + Inter (Google Fonts)
Maps:           Leaflet.js + GeoJSON
Charts:         Chart.js
AI:             Google Gemini API
Weather:        Open-Meteo API
Email:          SMTP2GO
Server:         Apache (XAMPP)
```

## Installation

### Prerequisites

- XAMPP (Apache + MySQL + PHP 8.x)
- Composer (for email dependencies)
- Google Gemini API key
- SMTP2GO account (for email alerts)

### Setup Steps

1. **Clone the repository**
   ```bash
   git clone https://github.com/0xJape/asclepius.git
   cd asclepius
   ```

2. **Configure database**
   - Create database `asclpe_db` in phpMyAdmin
   - Import `database/schema.sql`
   - Import `setup/prediction_tables.sql` for prediction data

3. **Configure settings**
   - Edit `includes/config.php` with database credentials
   - Add Gemini API key in `chatbot/chatbot.php`
   - Configure SMTP settings in `includes/smtp_config.php`

4. **Install dependencies**
   ```bash
   composer install
   ```

5. **Access the system**
   ```
   http://localhost/asclpe/login.php
   ```

## Project Structure

```
asclpe/
├── api/                    # API endpoints
│   ├── chatbot_data.php
│   ├── dashboard-data.php
│   └── gemini_proxy.php
├── assets/
│   ├── css/               # Stylesheets
│   ├── js/                # JavaScript files
│   └── geojson/           # Map data
├── chatbot/               # AI chatbot module
├── database/              # SQL schemas
├── docs/                  # Documentation
├── includes/              # PHP includes
│   ├── auth.php
│   ├── config.php
│   └── smtp_config.php
├── setup/                 # Installation scripts
├── uploads/               # File uploads
└── *.php                  # Main application pages
```

## ASCLEPIUS Mathematical Model

The system uses a validated Multi-Linear Regression (MLR) formula for dengue prediction:

```
y = -72.612471 + 0.00905443P + 2.447256T - 0.0778633H
```

**Where:**
- `y` = Predicted dengue cases
- `P` = Population per barangay
- `T` = Average temperature (°C)
- `H` = Average humidity (%)

The model was trained on 11 years of historical data (2014-2024) covering all 15 barangays.

## Barangays Covered

| Barangay | Code |
|----------|------|
| Acmonan | ACM |
| Bololmala | BOL |
| Bunao | BUN |
| Cebulan | CEB |
| Crossing Rubber | CRO |
| Kablon | KAB |
| Kalkam | KAL |
| Linan | LIN |
| Lunen | LUN |
| Miasong | MIA |
| Palian | PAL |
| Poblacion | POB |
| Polonuling | POL |
| Simbo | SIM |
| Tinago | TIN |

## Screenshots

<details>
<summary>View Screenshots</summary>

### Dashboard
Real-time overview with interactive risk map and case statistics.

### Patient Management
Complete patient records with case history and status tracking.

### Risk Prediction
Weather-based prediction with the ASCLEPIUS MLR model.

### AI Chatbot
Natural language interface for data queries and insights.

</details>

## API Reference

### Dashboard Data
```
GET /api/dashboard-data.php
```
Returns real-time statistics, case counts, and barangay data.

### Chatbot
```
POST /chatbot/chatbot.php
Content-Type: application/x-www-form-urlencoded
Body: message=<query>
```
Returns AI-generated response with dengue data analysis.

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/improvement`)
3. Commit changes (`git commit -am 'Add new feature'`)
4. Push to branch (`git push origin feature/improvement`)
5. Open a Pull Request

## License

This project is developed for academic and public health purposes.

## Acknowledgments

- **Tupi, South Cotabato** - Local Government Unit
- **Open-Meteo** - Weather data API
- **Google Gemini** - AI capabilities
- **SMTP2GO** - Email service

---

<p align="center">
  <strong>ASCLEPIUS</strong> - Protecting Communities Through Data-Driven Dengue Surveillance
</p>
