// Initialize map with barangay data
// Enhanced map initialization with better UX
// Initialize map with barangay data
// Simple working map initialization
// This should be in assets/js/dashboard.js
function initializeMap(map, barangayData) {
    const markers = [];
    
    barangayData.forEach(barangay => {
        if (barangay.latitude && barangay.longitude) {
            // Determine circle size and color based on case count
            const caseCount = parseInt(barangay.case_count) || 0;
            let radius, color, fillColor, fillOpacity;
            
            if (caseCount >= 10) {
                radius = 25;
                color = '#dc3545';
                fillColor = '#dc3545';
                fillOpacity = 0.8;
            } else if (caseCount >= 5) {
                radius = 20;
                color = '#fd7e14';
                fillColor = '#fd7e14';
                fillOpacity = 0.7;
            } else if (caseCount >= 1) {
                radius = 15;
                color = '#ffc107';
                fillColor = '#ffc107';
                fillOpacity = 0.6;
            } else {
                radius = 10;
                color = '#28a745';
                fillColor = '#28a745';
                fillOpacity = 0.5;
            }
            
            // Create circle marker
            const circle = L.circleMarker([barangay.latitude, barangay.longitude], {
                radius: radius,
                fillColor: fillColor,
                color: color,
                weight: 3,
                opacity: 0.9,
                fillOpacity: fillOpacity,
                className: 'barangay-marker'
            });
            
            // Create popup content
            const popupContent = `
                <div class="barangay-popup">
                    <h6><i class="fas fa-map-marker-alt text-primary me-2"></i>${barangay.name}</h6>
                    <p class="mb-1"><strong>Cases:</strong> ${caseCount}</p>
                    <p class="mb-1"><strong>Population:</strong> ${barangay.population ? parseInt(barangay.population).toLocaleString() : 'N/A'}</p>
                    <p class="mb-0"><strong>Case Rate:</strong> ${barangay.population && barangay.population > 0 ? ((caseCount / barangay.population) * 1000).toFixed(2) : 'N/A'} per 1,000</p>
                </div>
            `;
            
            circle.bindPopup(popupContent);
            circle.addTo(map);
            markers.push(circle);
        }
    });
    
    // Fit map to show all markers if any exist
    if (markers.length > 0) {
        const group = new L.featureGroup(markers);
        map.fitBounds(group.getBounds().pad(0.1));
    }
    
    return markers;
}

// Simple chart initialization
function initializeCharts(barangayData) {
    const chartElement = document.getElementById('chart');
    if (!chartElement) return;
    
    const ctx = chartElement.getContext('2d');
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: barangayData.map(d => d.name),
            datasets: [{
                label: 'Cases',
                data: barangayData.map(d => d.case_count || 0),
                backgroundColor: 'rgba(220, 53, 69, 0.5)',
                borderColor: 'rgba(220, 53, 69, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// Create enhanced popup content
function createEnhancedPopup(barangay, caseCount) {
    const riskLevel = getRiskLevel(caseCount);
    const riskColor = getRiskColor(riskLevel);
    
    return `
        <div class="barangay-popup">
            <div class="popup-header">
                <h6 class="mb-1">
                    <i class="fas fa-map-marker-alt text-primary me-2"></i>
                    ${barangay.name}
                </h6>
                <span class="badge bg-${riskColor} risk-badge">${riskLevel} Risk</span>
            </div>
            
            <div class="popup-body">
                <div class="stats-row">
                    <div class="stat-item">
                        <i class="fas fa-virus text-danger"></i>
                        <span class="stat-label">Total Cases</span>
                        <span class="stat-value">${caseCount}</span>
                    </div>
                    
                    <div class="stat-item">
                        <i class="fas fa-users text-info"></i>
                        <span class="stat-label">Population</span>
                        <span class="stat-value">${barangay.population ? barangay.population.toLocaleString() : 'N/A'}</span>
                    </div>
                </div>
                
                ${caseCount > 0 ? `
                <div class="case-rate">
                    <small class="text-muted">
                        <i class="fas fa-chart-line me-1"></i>
                        Case Rate: ${barangay.population ? ((caseCount / barangay.population) * 1000).toFixed(2) : 'N/A'} per 1,000
                    </small>
                </div>
                ` : ''}
            </div>
            
            <div class="popup-actions">
                <button class="btn btn-sm btn-primary me-2" onclick="viewBarangayDetails(${barangay.barangay_id})">
                    <i class="fas fa-eye me-1"></i>View Details
                </button>
                ${caseCount > 0 ? `
                <button class="btn btn-sm btn-outline-warning" onclick="viewCases(${barangay.barangay_id})">
                    <i class="fas fa-list me-1"></i>Cases
                </button>
                ` : ''}
            </div>
        </div>
    `;
}

// Helper functions
function getRiskLevel(caseCount) {
    if (caseCount >= 10) return 'HIGH';
    if (caseCount >= 5) return 'MEDIUM';
    if (caseCount >= 1) return 'LOW';
    return 'MINIMAL';
}

function getRiskColor(riskLevel) {
    switch (riskLevel) {
        case 'HIGH': return 'danger';
        case 'MEDIUM': return 'warning';
        case 'LOW': return 'info';
        default: return 'success';
    }
}

// Update sidebar with barangay details
function updateBarangayDetails(barangay, caseCount) {
    const detailsPanel = document.getElementById('barangay-details');
    if (detailsPanel) {
        detailsPanel.innerHTML = `
            <div class="selected-barangay">
                <h6><i class="fas fa-map-pin me-2"></i>${barangay.name}</h6>
                <p class="mb-1"><strong>Cases:</strong> ${caseCount}</p>
                <p class="mb-1"><strong>Population:</strong> ${barangay.population?.toLocaleString() || 'N/A'}</p>
                <p class="mb-0"><strong>Risk Level:</strong> 
                    <span class="badge bg-${getRiskColor(getRiskLevel(caseCount))}">${getRiskLevel(caseCount)}</span>
                </p>
            </div>
        `;
        detailsPanel.scrollIntoView({ behavior: 'smooth' });
    }
}

// Action functions
function viewBarangayDetails(barangayId) {
    window.location.href = `barangay_details.php?id=${barangayId}`;
}

function viewCases(barangayId) {
    window.location.href = `patients.php?barangay=${barangayId}`;
}

// Initialize charts
function initializeCharts(barangayData) {
    const chartElement = document.getElementById('chart');
    if (!chartElement) return; // Exit if chart element doesn't exist
    
    const ctx = chartElement.getContext('2d');
    
    // Sort barangays by total cases
    const sortedData = [...barangayData].sort((a, b) => (b.case_count || 0) - (a.case_count || 0));
    
    // Destroy existing chart if it exists
    if (window.barChart) {
        window.barChart.destroy();
    }
    
    window.barChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: sortedData.map(d => d.name),
            datasets: [
                {
                    label: 'Total Cases',
                    data: sortedData.map(d => d.case_count || 0),
                    backgroundColor: 'rgba(220, 53, 69, 0.5)',
                    borderColor: 'rgba(220, 53, 69, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Recent Cases',
                    data: sortedData.map(d => d.recent_cases || 0),
                    backgroundColor: 'rgba(255, 193, 7, 0.5)',
                    borderColor: 'rgba(255, 193, 7, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Cases'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top'
                },
                title: {
                    display: true,
                    text: 'Dengue Cases by Barangay'
                }
            }
        }
    });
}

// Fit map to markers if we have data
function fitMapToData(map, barangayData) {
    if (barangayData.length > 0) {
        const validLocations = barangayData.filter(location => location.latitude && location.longitude);
        
        if (validLocations.length > 0) {
            const bounds = L.latLngBounds();
            validLocations.forEach(location => {
                bounds.extend([location.latitude, location.longitude]);
            });
            
            if (bounds.isValid()) {
                map.fitBounds(bounds.pad(0.1));
            }
        }
    }
}

// Auto-refresh dashboard data
let refreshInterval;
function startAutoRefresh() {
    // Only start auto-refresh if we're on the dashboard page
    if (window.location.pathname.includes('dashboard.php')) {
        refreshInterval = setInterval(() => {
            fetch('api/dashboard-data.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateDashboardStats(data.stats);
                        updateAlerts(data.alerts);
                        updateCases(data.cases);
                    }
                })
                .catch(error => console.error('Error refreshing dashboard:', error));
        }, 300000); // Refresh every 5 minutes
    }
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
}

// Update dashboard stats (if elements exist)
function updateDashboardStats(stats) {
    const elements = {
        'total-cases': stats.total_cases,
        'new-cases': stats.new_cases,
        'active-alerts': stats.active_alerts,
        'affected-areas': stats.affected_areas
    };
    
    Object.entries(elements).forEach(([id, value]) => {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value || 0;
        }
    });
}

// Update alerts section (if element exists)
function updateAlerts(alerts) {
    const alertsContainer = document.getElementById('alerts-container');
    if (alertsContainer && alerts) {
        // Update alerts display logic here
        console.log('Updating alerts:', alerts);
    }
}

// Update cases section (if element exists)
function updateCases(cases) {
    const casesContainer = document.getElementById('cases-container');
    if (casesContainer && cases) {
        // Update cases display logic here
        console.log('Updating cases:', cases);
    }
}

// Risk score calculation helper
function calculateRiskScore(caseData) {
    let score = 0;
    
    // Base score from number of cases
    score += (caseData.cases || 0) * 10;
    
    // Environmental factors
    if (caseData.temperature && caseData.temperature > 30) score += 20;
    if (caseData.humidity && caseData.humidity > 70) score += 20;
    
    // Recent cases weight
    if (caseData.newCases7Days && caseData.newCases7Days > 5) score += 30;
    
    return Math.min(score, 100); // Cap at 100
}

// Initialize dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Only start auto-refresh on dashboard page
    if (window.location.pathname.includes('dashboard.php')) {
        startAutoRefresh();
    }
    
    // Clean up on page hide
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopAutoRefresh();
        } else if (window.location.pathname.includes('dashboard.php')) {
            startAutoRefresh();
        }
    });
});

// Export functions for global use
window.initializeMap = initializeMap;
window.initializeCharts = initializeCharts;
window.fitMapToData = fitMapToData;
window.viewBarangayDetails = viewBarangayDetails;
window.viewCases = viewCases;
