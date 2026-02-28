# Dengue Prediction System: Weather Annotations

## Overview

The dengue prediction system now includes weather annotations that help visualize the relationship between weather conditions and dengue case predictions. This feature integrates real-time weather data from Open-Meteo API with our prediction models, highlighting significant weather events that may affect dengue transmission.

## Features

### Weather Annotations

The chart displays vertical annotation lines that indicate:

1. **High Rainfall** (blue lines)
   - Shows days with significant rainfall (>10mm)
   - Highlights potential breeding ground formation
   - More intense blue for heavier rainfall

2. **High Temperature** (red lines)
   - Indicates days with temperatures above 32°C
   - Shows conditions favorable for rapid mosquito development

3. **Optimal Breeding Conditions** (orange lines)
   - Highlights days with the perfect combination of temperature (28-32°C) and humidity (>70%)
   - Special alerts for ideal dengue transmission conditions

### Integration with Prediction Models

The annotation system links weather conditions to prediction outcomes by:

1. Visually correlating weather events with predicted case increases
2. Showing how consecutive days of favorable breeding conditions affect predictions
3. Highlighting weather pattern changes that may impact outbreak risks

## Using Weather Annotations

### Interpreting the Annotations

- **Blue markers** (☂️): Indicate rainfall events that may create breeding sites
- **Red markers** (🔥): Show high temperature days that accelerate mosquito development
- **Orange markers** (🦟): Highlight optimal breeding conditions with high risk
- **Warning symbols** (⚠️): Indicate periods requiring special attention due to ideal transmission conditions

### Making Decisions

1. **Immediate Interventions**: When seeing clusters of optimal breeding condition markers, prioritize vector control in those time periods
2. **Resource Planning**: Use the annotations to determine when to increase surveillance and treatment resources
3. **Public Warnings**: Issue advisories when weather conditions are trending toward high-risk scenarios

## Technical Implementation

The weather annotations are implemented using:

1. **Chart.js Annotation Plugin**: For drawing the vertical lines and markers
2. **Open-Meteo API Integration**: For real-time and forecast weather data
3. **Custom Styling**: CSS classes that visually differentiate different weather conditions

## Saving Predictions

The system now includes a "Save Prediction" button that stores:

1. The predicted case data
2. The algorithm used and confidence level
3. Weather conditions that influenced the prediction

This data is saved to the database for later accuracy analysis and model improvement.

## Future Enhancements

Future versions will include:

1. Additional weather indicators (wind patterns, barometric pressure)
2. Machine learning models that better incorporate weather as predictive features
3. Comparative views showing previous years' weather patterns alongside predictions
