// Chart.js Annotation plugin implementation
document.addEventListener('DOMContentLoaded', function() {
    // Register the annotation plugin
    Chart.register(ChartAnnotation);
    
    // Initialize prediction chart
    initPredictionChart();
    
    // Add a save button programmatically
    addSavePredictionButton();
    
    // Handle form submission if the form exists
    const predictionForm = document.getElementById('predictionForm');
    if (predictionForm) {
        predictionForm.addEventListener('submit', function(e) {
            e.preventDefault();
            // Handle form submission logic here
        });
    }
    
    // Initialize map if showing all barangays
    if (document.getElementById('predictionMap')) {
        initPredictionMap();
    }
});

// Function to add a save prediction button
function addSavePredictionButton() {
    // Find the chart container
    const chartContainer = document.getElementById('predictionChart')?.closest('.card');
    
    if (!chartContainer) {
        console.warn('Could not find chart container to add save button');
        return;
    }
    
    // Find the card header, or create one if it doesn't exist
    let header = chartContainer.querySelector('.card-header');
    if (!header) {
        header = document.createElement('div');
        header.className = 'card-header d-flex justify-content-between align-items-center';
        header.innerHTML = '<h5 class="mb-0">Dengue Case Prediction</h5>';
        
        // Insert at the beginning of the card
        chartContainer.insertBefore(header, chartContainer.firstChild);
    }
    
    // Create the save button
    const saveBtn = document.createElement('button');
    saveBtn.id = 'savePredictionBtn';
    saveBtn.className = 'btn btn-sm btn-primary';
    saveBtn.innerHTML = '<i class="fas fa-save me-1"></i> Save Prediction';
    
    // Add to the header
    header.appendChild(saveBtn);
    
    // Add the click event
    saveBtn.addEventListener('click', function() {
        // Collect data to save
        const predictionData = {
            barangayId: typeof selectedBarangayId !== 'undefined' ? selectedBarangayId : 0,
            predictions: predictionChartData.predictedData.filter(d => d.y !== null).map(d => ({
                date: d.x,
                cases: d.y
            })),
            confidence: typeof confidenceLevel !== 'undefined' ? confidenceLevel : 85,
            riskLevel: typeof riskLevel !== 'undefined' ? riskLevel : 'Moderate',
            algorithm: document.querySelector('input[name="algorithm"]:checked')?.value || 'moving_average',
            weatherData: typeof weatherDataJson !== 'undefined' ? weatherDataJson : []
        };
        
        // Save the prediction
        savePredictionResults(predictionData);
    });
}

// Function to initialize the prediction chart with weather data annotations
function initPredictionChart() {
    const ctx = document.getElementById('predictionChart').getContext('2d');
    
    // Get data from the page
    const chartData = window.predictionChartData || {
        labels: [],
        historicalData: [],
        predictedData: [],
        weatherData: []
    };
    
    // Process weather data if it exists
    if (typeof weatherDataJson !== 'undefined' && weatherDataJson) {
        try {
            const parsedWeatherData = JSON.parse(weatherDataJson);
            chartData.weatherData = parsedWeatherData;
        } catch (e) {
            console.error('Error parsing weather data:', e);
        }
    }
    
    // Create chart instance
    window.predictionChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    label: 'Historical Cases',
                    data: chartData.historicalData,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    fill: true,
                    pointRadius: 3,
                    tension: 0.2
                },
                {
                    label: 'Predicted Cases',
                    data: chartData.predictedData,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    fill: true,
                    pointRadius: 3,
                    borderDash: [5, 5],
                    tension: 0.2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit: 'day',
                        tooltipFormat: 'MMM d, yyyy',
                        displayFormats: {
                            day: 'MMM d'
                        }
                    },
                    title: {
                        display: true,
                        text: 'Date'
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Cases'
                    },
                    ticks: {
                        callback: function(value) {
                            return Math.round(value);
                        }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.dataset.label || '';
                            const value = context.parsed.y || 0;
                            return `${label}: ${Math.round(value)}`;
                        }
                    }
                },
                legend: {
                    position: 'top'
                },
                annotation: {
                    annotations: {}
                }
            }
        }
    });
    
    // Add weather data annotations if available
    if (chartData.weatherData && chartData.weatherData.length > 0) {
        addWeatherAnnotations(chartData.weatherData);
    }
}

// Function to add weather annotations to the chart
function addWeatherAnnotations(weatherData) {
    if (!window.predictionChart || !weatherData || weatherData.length === 0) return;
    
    const annotations = {};
    
    // Add weather data
    weatherData.forEach((day, index) => {
        const annotation = {
            type: 'line',
            mode: 'vertical',
            scaleID: 'x',
            value: day.date,
            borderColor: 'rgba(75, 192, 192, 0.5)',
            borderWidth: 1,
            label: {
                enabled: true,
                position: 'top',
                content: `${day.condition} ${day.temp_c}°C`,
                backgroundColor: 'rgba(75, 192, 192, 0.7)',
                color: '#fff',
                font: {
                    size: 10
                },
                yAdjust: index % 2 ? 30 : 0 // Alternate positioning to avoid overlap
            }
        };
        
        // Add high rainfall indicator
        if (day.precip_mm > 10) {
            annotation.borderColor = 'rgba(0, 0, 255, 0.7)';
            annotation.borderWidth = 2;
            annotation.label.backgroundColor = 'rgba(0, 0, 255, 0.7)';
            annotation.label.content = `${day.condition} ${day.temp_c}°C ☂️${day.precip_mm}mm`;
            annotation.label.className = 'weather-annotation rain';
            
            // Add a risk note if this creates breeding conditions
            if (day.precip_mm > 20) {
                annotation.label.content += ' 💧 Breeding risk';
            }
        }
        
        // Add high temperature indicator
        if (day.temp_c > 32) {
            annotation.borderColor = 'rgba(255, 0, 0, 0.7)';
            annotation.borderWidth = 2;
            annotation.label.backgroundColor = 'rgba(255, 0, 0, 0.7)';
            annotation.label.content = `${day.condition} ${day.temp_c}°C 🔥`;
            annotation.label.className = 'weather-annotation hot';
        }
        
        // Add optimal dengue breeding conditions indicator (high temp + high humidity)
        if (day.temp_c >= 28 && day.temp_c <= 32 && day.humidity > 70) {
            annotation.borderColor = 'rgba(255, 165, 0, 0.7)';
            annotation.borderWidth = 3;
            annotation.label.backgroundColor = 'rgba(255, 165, 0, 0.7)';
            annotation.label.content = `${day.condition} ${day.temp_c}°C 🦟 High Risk`;
            annotation.label.className = 'weather-annotation optimal';
            
            // Add dengue alert icon if conditions are perfect for mosquito breeding
            if (day.temp_c >= 29 && day.temp_c <= 31 && day.humidity > 80 && day.precip_mm > 0) {
                annotation.label.content = `${day.condition} ${day.temp_c}°C ⚠️ ALERT: Ideal Breeding`;
            }
        }
        
        // Add the annotation
        annotations[`weather-${index}`] = annotation;
    });
    
    // Update chart with annotations
    window.predictionChart.options.plugins.annotation.annotations = annotations;
    window.predictionChart.update();
}

// Function to initialize map for all barangays
function initPredictionMap() {
    // Map initialization code here - using the one from the existing file
}

// Function to show notifications
function showNotification(type, title, message) {
    // Create notification element
    const notificationId = 'notification-' + Date.now();
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show notification-toast`;
    notification.id = notificationId;
    notification.innerHTML = `
        <strong>${title}</strong>
        <p>${message}</p>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    // Style the notification
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.minWidth = '300px';
    notification.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    
    // Add to document
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        const notificationElement = document.getElementById(notificationId);
        if (notificationElement) {
            notificationElement.classList.remove('show');
            setTimeout(() => {
                if (notificationElement.parentNode) {
                    notificationElement.parentNode.removeChild(notificationElement);
                }
            }, 500);
        }
    }, 5000);
}

// Save prediction results to database
function savePredictionResults(data) {
    // Prepare data for saving
    const predictionData = {
        barangayId: data.barangayId || 0,
        predictions: data.predictions || [],
        confidence: data.confidence || 85,
        riskLevel: data.riskLevel || 'Moderate',
        algorithm: data.algorithm || 'moving_average',
        weatherData: data.weatherData || []
    };
    
    // Log the data being saved
    console.log('Saving prediction data:', predictionData);
    
    // Create a fetch request to save the prediction data
    fetch('api/save_prediction.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(predictionData)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok ' + response.statusText);
        }
        return response.json();
    })
    .then(result => {
        console.log('Prediction saved successfully:', result);
        
        // Show success notification
        if (typeof showNotification === 'function') {
            showNotification('success', 'Prediction saved successfully!', 'The prediction has been saved for future accuracy analysis.');
        }
    })
    .catch(error => {
        console.error('Error saving prediction:', error);
        
        // Show error notification
        if (typeof showNotification === 'function') {
            showNotification('danger', 'Error saving prediction', 'There was a problem saving the prediction data.');
        }
    });
}
