# Flouri Farm LFMIS — ML, AI & Cloud Computing Integration Guide

---

## PART 1: MACHINE LEARNING INTEGRATION

### Where ML Fits in Your System

**Current Architecture:**
```
React Frontend ↔ PHP REST API ↔ MySQL Database
                      ↑
                      └─→ Python ML Service (NEW)
                           ↓
                      Trained Models
```

**ML sits between your backend and database**, analyzing historical data and returning intelligent predictions.

---

## 1.1 MACHINE LEARNING USE CASES FOR FLOURI FARM

### A. PREDICTIVE HEALTH MONITORING (HIGH PRIORITY)

**Problem:** Farm worker can't predict when animals will get sick. Health issues appear suddenly.

**ML Solution:** Build a health anomaly detection model that learns from historical health records.

#### How It Works:

**Step 1: Collect Historical Data**
```
Example data from Livestock table:
Animal ID | Species | Age | Weight | Last_Vaccination_Date | Days_Since_Deworming | Produce_Output | Date
COW-001   | Cow     | 4   | 450kg  | 2026-08-15           | 45                   | 12L            | 2026-09-20
COW-001   | Cow     | 4   | 445kg  | 2026-08-15           | 50                   | 8L             | 2026-09-21  ← Drop (anomaly)
COW-001   | Cow     | 4   | 435kg  | 2026-08-15           | 55                   | 6L             | 2026-09-22  ← Further drop

GOAT-005  | Goat    | 2   | 45kg   | 2026-07-20           | 30                   | —              | 2026-09-20
GOAT-005  | Goat    | 2   | 42kg   | 2026-07-20           | 35                   | —              | 2026-09-21  ← Weight drop
GOAT-005  | Goat    | 2   | 38kg   | 2026-07-20           | 40                   | —              | 2026-09-22  ← More drop (infection?)
```

**Step 2: Train an Anomaly Detection Model**

**Technology:** Python with scikit-learn or Isolation Forest

```python
from sklearn.ensemble import IsolationForest
import pandas as pd
import numpy as np

# Load historical data
data = pd.read_csv('livestock_health_history.csv')

# Features for model: weight change, produce drop, days since vaccination, etc.
features = data[['weight_change_percent', 'produce_drop_percent', 'days_since_vaccine', 'age_days']]

# Train Isolation Forest (detects outliers/anomalies)
model = IsolationForest(contamination=0.1)  # Expect 10% of records to be anomalies
model.fit(features)

# Predict on new data
new_record = [[−5, −30, 50, 1460]]  # 5% weight drop, 30% produce drop, etc.
prediction = model.predict(new_record)
# prediction = -1 means ANOMALY DETECTED → Alert farmer
```

**Step 3: API Integration**

PHP backend calls Python ML service:

```php
// File: backend/api/check_health_anomaly.php

$animalData = [
    'weight_change' => -5,  // 5% drop from last week
    'produce_change' => -30, // 30% drop in milk
    'days_since_vaccine' => 50,
    'age_days' => 1460
];

// Call Python ML service
$response = file_get_contents('http://ml-service:5000/predict_health_anomaly', false, 
    stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($animalData)
        ]
    ])
);

$prediction = json_decode($response, true);

if ($prediction['anomaly'] === true) {
    // Create alert for farmer
    $alertMsg = "ALERT: Cow COW-001 shows health anomaly. Milk dropped 30% in 2 days. Call vet.";
    // Store in database and push notification
}
```

**Step 4: Frontend Display**

```
Dashboard Alert:
┌─────────────────────────────────────────┐
│ ⚠️ HEALTH ANOMALY DETECTED               │
├─────────────────────────────────────────┤
│ Animal: COW-001 (Cow)                   │
│ Issue: Sudden milk production drop      │
│ Change: 30% decrease in 2 days          │
│ Recommendation: Contact veterinarian    │
│ [View Full Record] [Acknowledge]        │
└─────────────────────────────────────────┘
```

**Impact:** Farmer catches health issues **before animals get critically sick**, saves money on vet bills.

---

### B. PREDICTIVE PRODUCTION FORECASTING (HIGH PRIORITY)

**Problem:** Farmer can't predict how much milk/eggs he'll produce next month. Can't plan sales.

**ML Solution:** Time-series forecasting using historical production trends.

#### How It Works:

**Step 1: Collect Historical Produce Data**

```
Date       | Milk_Liters | Eggs_Trays | Weather_Temp | Rainfall_mm
2026-05-01 | 120        | 48        | 24°C        | 0mm
2026-05-02 | 125        | 50        | 23°C        | 0mm
2026-05-03 | 118        | 45        | 22°C        | 15mm  ← Rain reduces milk
2026-05-04 | 110        | 40        | 20°C        | 25mm  ← More rain
2026-05-05 | 115        | 42        | 21°C        | 10mm
...
2026-09-20 | 135        | 55        | 25°C        | 0mm   ← Current
```

**Step 2: Train a Time-Series Model**

**Technology:** Python with statsmodels (ARIMA) or Prophet

```python
from statsmodels.tsa.arima.model import ARIMA
import pandas as pd

# Load historical milk production
df = pd.read_csv('milk_production.csv', parse_dates=['date'], index_col='date')

# Train ARIMA model (Auto-Regressive Integrated Moving Average)
model = ARIMA(df['milk_liters'], order=(1, 1, 1))  # (p, d, q) parameters
fitted_model = model.fit()

# Forecast next 30 days
forecast = fitted_model.get_forecast(steps=30)
forecast_df = forecast.conf_int()

# Output:
# Date       | Predicted_Milk | Lower_Bound | Upper_Bound
# 2026-10-01 | 138           | 130        | 146
# 2026-10-02 | 140           | 131        | 149
# 2026-10-03 | 139           | 128        | 150
```

**Step 3: Integrate Weather Data**

Enhance predictions using weather patterns:

```python
from sklearn.linear_model import LinearRegression
import numpy as np

# Features: temperature, rainfall, season, days_since_last_vaccine
X = df[['temperature', 'rainfall_mm', 'season_encoded', 'days_since_vaccine']]
y = df['milk_liters']

model = LinearRegression()
model.fit(X, y)

# Predict for tomorrow
tomorrow_weather = [[25, 0, 3, 45]]  # 25°C, no rain, season 3, 45 days since vaccine
predicted_milk = model.predict(tomorrow_weather)[0]
# Output: 138 liters expected tomorrow
```

**Step 4: API Call**

```php
// PHP backend forecasts production
$response = file_get_contents('http://ml-service:5000/forecast_production', false,
    stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode([
                'animal_id' => 'COW-001',
                'product_type' => 'milk',
                'days_ahead' => 30
            ])
        ]
    ])
);

$forecast = json_decode($response, true);
// Returns: [138, 140, 139, 145, 142, ...]
```

**Step 5: Dashboard Display**

```
Production Forecast — Next 30 Days
┌──────────────────────────────────────────────┐
│ Milk Production (Liters)                     │
│ Expected: 138-140L per day                   │
│ Monthly Total: ~4,140L                       │
│                                              │
│      ╱╲        ╱╲                           │
│    ╱  ╲      ╱  ╲                           │
│  ╱      ╲    ╱    ╲                         │
│─────────────────────────────────             │
│ Oct 1      Oct 15    Oct 30                 │
│                                              │
│ Revenue Estimate: 4,140L × KES 50/L         │
│ = KES 207,000 (next month)                  │
└──────────────────────────────────────────────┘
```

**Impact:** Farmer knows expected income → can plan expenses, negotiate prices with buyers.

---

### C. COST OPTIMIZATION & BREEDING RECOMMENDATIONS (MEDIUM PRIORITY)

**Problem:** Farmer doesn't know optimal breeding timing or when feed costs are highest.

**ML Solution:** Correlate breeding success with seasonal patterns and forecast costs.

```python
# Identify breeding success rates by season
breeding_data = df[df['event'] == 'breeding_successful']
by_season = breeding_data.groupby('season')['success_rate'].mean()

# Output:
# Season 1 (Dry): 65% success
# Season 2 (Rains): 85% success  ← BEST time to breed
# Season 3 (Cold): 72% success

# Cost optimization
feed_costs = df.groupby('month')['feed_cost'].mean()
# October: KES 15,000/month (dry season, expensive)
# November: KES 12,000/month (rains, cheaper)
# Recommendation: Buy feed in November, store for October use
```

---

## 1.2 PYTHON ML SERVICE ARCHITECTURE

**Where to run ML code:**

```
Option A: On Same Server (Simple)
┌─────────────────────────────────────┐
│ VPS/Cloud Server                    │
├─────────────────────────────────────┤
│ React App (port 3000)               │
│ PHP API (port 8000)                 │
│ Python ML Service (port 5000)  ← ML │
│ MySQL Database                      │
└─────────────────────────────────────┘

Option B: Separate Microservice (Professional)
┌──────────────────────┐    ┌──────────────────────┐
│ Web Server 1         │    │ ML Service Server    │
├──────────────────────┤    ├──────────────────────┤
│ React + PHP + MySQL  │◄──►│ Python ML Models    │
│ (port 80, 8000)      │    │ (port 5000)         │
└──────────────────────┘    └──────────────────────┘
```

**Python ML Service Framework:**

```python
# File: ml_service/app.py
from flask import Flask, request, jsonify
import pickle
import pandas as pd
import numpy as np

app = Flask(__name__)

# Load pre-trained models at startup
health_model = pickle.load(open('models/health_anomaly_model.pkl', 'rb'))
forecast_model = pickle.load(open('models/production_forecast_model.pkl', 'rb'))
cost_model = pickle.load(open('models/cost_optimizer_model.pkl', 'rb'))

@app.route('/predict_health_anomaly', methods=['POST'])
def predict_health():
    data = request.json
    features = [
        data['weight_change'],
        data['produce_change'],
        data['days_since_vaccine'],
        data['age_days']
    ]
    prediction = health_model.predict([features])[0]
    
    return jsonify({
        'anomaly': prediction == -1,  # -1 = anomaly, 1 = normal
        'confidence': float(health_model.score([[*features]]))*100
    })

@app.route('/forecast_production', methods=['POST'])
def forecast():
    data = request.json
    animal_id = data['animal_id']
    days = data['days_ahead']
    
    predictions = forecast_model.get_forecast(steps=days).predicted_mean.tolist()
    
    return jsonify({
        'animal_id': animal_id,
        'forecasted_production': predictions,
        'total_expected': sum(predictions),
        'unit': 'liters'
    })

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=False)
```

---

## PART 2: ARTIFICIAL INTELLIGENCE (AI) INTEGRATION

### What's the Difference Between ML and AI Here?

- **Machine Learning:** Learns from historical data (health anomalies, production forecasting)
- **Artificial Intelligence:** Makes real-time intelligent decisions or recommendations based on data patterns

### 2.1 AI USE CASES FOR FLOURI FARM

### A. INTELLIGENT VETERINARY ASSISTANT (CHATBOT)

**Problem:** Farm worker needs health advice but can't reach vet immediately.

**AI Solution:** Chatbot trained on veterinary knowledge.

```
Farm Worker: "COW-001 has diarrhea and won't eat"

AI Chatbot Response:
"Based on symptoms:
- Likely: Gastroenteritis or bacterial infection
- Action: Isolate animal, provide clean water
- Treatment: Administer metronidazole 15mg/kg
- When to call vet: If condition worsens in 6 hours
- Cost estimate: KES 3,000-5,000 for treatment"
```

**Implementation Options:**

**Option 1: Simple Rule-Based AI (Easiest)**
```php
// File: backend/api/vet_assistant.php
$symptoms = ['diarrhea', 'loss_appetite'];

$diagnosis_rules = [
    'diarrhea' => ['gastroenteritis', 'bacterial_infection', 'parasites'],
    'loss_appetite' => ['infection', 'stress', 'malnutrition'],
];

$treatments = [
    'gastroenteritis' => 'Provide probiotics + clean water',
    'bacterial_infection' => 'Administer antibiotics (metronidazole)',
    'parasites' => 'Dewormer + isolate animal'
];

// Match symptoms to diagnosis
$possible_diagnoses = array_merge(...array_map(fn($s) => $diagnosis_rules[$s] ?? [], $symptoms));
$recommended_treatment = $treatments[array_shift($possible_diagnoses)];
```

**Option 2: NLP-based AI (Advanced)**
```python
# File: ml_service/vet_ai.py
from transformers import pipeline
import json

# Use pre-trained medical NLP model
classifier = pipeline("zero-shot-classification", model="facebook/bart-large-mnli")

symptoms_text = "Cow has diarrhea and won't eat"
possible_conditions = ["gastroenteritis", "bacterial_infection", "parasites", "malnutrition"]

result = classifier(symptoms_text, possible_conditions)
# Returns: {'labels': ['gastroenteritis', 'bacterial_infection', ...], 'scores': [0.92, 0.78, ...]}

diagnosis = result['labels'][0]  # Highest confidence
treatment = get_treatment(diagnosis)
```

---

### B. SMART INVENTORY MANAGEMENT

**Problem:** Farm runs out of hay or salt lick unexpectedly.

**AI Solution:** Predict consumption and auto-order supplies.

```python
# ML predicts daily consumption based on herd size, weather, season
daily_hay_consumption = predict_consumption(
    herd_size=50,
    weather='dry_season',
    temperature=28
)
# Output: 150kg per day

# Calculate reorder point
current_hay = 500kg  # In stock
days_to_supplier = 3  # Lead time
min_stock = 150kg * 3 = 450kg

if current_hay <= min_stock:
    auto_send_order_to_supplier("Hay", 1000kg)
    notify_farmer("Hay order placed automatically")
```

---

### C. DISEASE OUTBREAK PREDICTION

**Problem:** When one animal gets sick, does the whole herd get infected?

**AI Solution:** Predict outbreak risk based on proximity, health status, weather.

```python
# Input: COW-001 diagnosed with respiratory infection
# ML analyzes: Which animals are near COW-001? Weather conditions? Vaccination status?

outbreak_risk = predict_disease_spread(
    disease='respiratory_infection',
    infected_animal='COW-001',
    nearby_animals=['COW-002', 'COW-003', 'GOAT-001'],
    vaccination_rates=[0.8, 0.6, 0.5, 0.7],
    temperature=22,
    humidity=65
)

# Output:
# {
#     'outbreak_probability': 0.72,  # 72% chance of spread
#     'high_risk_animals': ['COW-002', 'COW-003'],
#     'recommendation': 'Quarantine COW-001 and COW-002 immediately',
#     'estimate_affected': 3-5 animals
# }
```

---

## PART 3: CLOUD COMPUTING INTEGRATION

### Why Cloud Computing for Flouri Farm?

1. **Scalability:** Start small, grow as farm grows
2. **Backup & Recovery:** Don't lose data if server fails
3. **Multi-farm support:** Eventually manage multiple farms
4. **Accessibility:** Access from anywhere (farmer, vet, buyers)
5. **ML Services:** Cloud providers offer pre-built ML services

---

## 3.1 CLOUD ARCHITECTURE

### Architecture Diagram:

```
┌─────────────────────────────────────────────────────────────────┐
│                        CLOUD PROVIDER (AWS/Azure/Google Cloud)  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────────────┐        ┌──────────────────────────┐ │
│  │   Frontend Service   │        │   AI/ML Services         │ │
│  │  (React hosted on    │        │  • Amazon SageMaker      │ │
│  │   CloudFront CDN)    │        │  • Jupyter Notebooks     │ │
│  │                      │        │  • Model Training Pipelines
│  └──────────────────────┘        └──────────────────────────┘ │
│           ↓                               ↑                     │
│  ┌──────────────────────┐        ┌──────────────────────────┐ │
│  │   API Gateway        │◄─────►│  Lambda Functions        │ │
│  │  (Routes requests)   │        │  (Serverless ML inference)
│  └──────────────────────┘        └──────────────────────────┘ │
│           ↓                               ↑                     │
│  ┌──────────────────────┐        ┌──────────────────────────┐ │
│  │   App Server         │        │  Data Lake (S3)          │ │
│  │  (PHP/Node.js)       │        │  • Historical data       │ │
│  │                      │        │  • Model artifacts       │ │
│  └──────────────────────┘        │  • Backups               │ │
│           ↓                       └──────────────────────────┘ │
│  ┌──────────────────────┐                                      │
│  │   Database           │                                      │
│  │  (RDS MySQL/Aurora)  │                                      │
│  │  (Automated backups) │                                      │
│  └──────────────────────┘                                      │
│           ↓                                                     │
│  ┌──────────────────────┐                                      │
│  │   Notification Service│                                     │
│  │  (SNS/CloudWatch)    │                                      │
│  │  • SMS alerts        │                                      │
│  │  • Push notifications│                                      │
│  └──────────────────────┘                                      │
└─────────────────────────────────────────────────────────────────┘
         │
         └──────────►  Internet (Farmer accesses via phone/laptop)
```

---

## 3.2 CLOUD PROVIDER OPTIONS

### Option 1: AWS (Amazon Web Services) — RECOMMENDED

**Why:** Cheapest for startups, best ML services, free tier for 12 months

| Service | Purpose | Cost |
|---------|---------|------|
| **EC2** | App server (PHP) | $10-20/month (t3.small) |
| **RDS** | Managed database | $15-30/month |
| **S3** | Data storage | $0.023/GB (first 1GB free) |
| **Lambda** | Serverless ML inference | $0.20 per 1M requests |
| **SageMaker** | Train ML models | Pay-as-you-go (~$0.50/hour) |
| **CloudFront** | CDN for React frontend | $0.085/GB data transfer |
| **SNS** | Send SMS alerts | $0.50 per 1000 SMS |
| **Total Monthly** | | **~$50-100** (starting) |

**AWS Free Tier (First 12 months):**
- 750 hours EC2
- 750 hours RDS
- 5GB S3 storage
- 1M Lambda requests
- 100 SNS SMS

---

### Option 2: Google Cloud

**Pros:** Good BigQuery for analytics, simple setup
**Cons:** Slightly more expensive than AWS
**Cost:** $60-120/month

---

### Option 3: Azure (Microsoft)

**Pros:** Good if using .NET, strong enterprise features
**Cons:** Pricier than AWS
**Cost:** $80-150/month

---

### Option 4: DigitalOcean (EASIEST FOR BEGINNERS)

**Pros:** Simple, great documentation, affordable
**Cons:** Limited AI/ML services
**Cost:** $25-50/month for basic setup

---

## 3.3 STEP-BY-STEP CLOUD DEPLOYMENT (Using AWS)

### Step 1: Set Up Cloud Database (RDS)

```bash
# AWS Console → RDS → Create Database
# Engine: MySQL 8.0
# Instance: db.t3.micro (free tier)
# Storage: 20GB
# Backup retention: 7 days (automatic)

# Connection string in PHP:
$host = "flouri-farm.xxxxx.ap-southeast-1.rds.amazonaws.com";
$db = "lfmis";
$user = "admin";
$pass = "SecurePassword123!";
$conn = new mysqli($host, $user, $pass, $db);
```

### Step 2: Deploy App Server (EC2)

```bash
# SSH into EC2 instance
ssh -i your-key.pem ec2-user@your-instance-ip

# Install dependencies
sudo yum update
sudo yum install php mysql apache2 python3

# Upload code
scp -r ./flouri-farm-app ec2-user@instance:/var/www/html/

# Start services
sudo systemctl start httpd
sudo systemctl start mysql
```

### Step 3: Deploy ML Service (Lambda or EC2)

**Option A: Serverless (Recommended)**
```python
# AWS Lambda function (auto-scales)
import json
import boto3
import pickle

# Load model from S3
s3 = boto3.client('s3')
model_obj = s3.get_object(Bucket='flouri-models', Key='health_model.pkl')
model = pickle.loads(model_obj['Body'].read())

def lambda_handler(event, context):
    features = [
        event['weight_change'],
        event['produce_change'],
        event['days_since_vaccine']
    ]
    prediction = model.predict([features])[0]
    
    return {
        'statusCode': 200,
        'body': json.dumps({'anomaly': prediction == -1})
    }
```

**Option B: EC2 Container (More Control)**
```bash
# Install Docker
sudo yum install docker -y
sudo service docker start

# Create ML service container
docker build -t flouri-ml .
docker run -d -p 5000:5000 flouri-ml
```

### Step 4: Set Up Automatic Backups

```bash
# AWS S3 backup script (daily)
#!/bin/bash
DATE=$(date +%Y-%m-%d)
mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASS lfmis > backup-$DATE.sql
aws s3 cp backup-$DATE.sql s3://flouri-backups/

# Schedule with CloudWatch Events (every day at 2 AM)
```

### Step 5: Set Up Monitoring & Alerts

```
AWS CloudWatch:
• CPU usage > 80% → Alert
• Database connection errors → Alert
• ML inference latency > 5s → Alert
• SMS sent to farmer: "System running slow, maintenance needed"
```

---

## PART 4: INTEGRATED ARCHITECTURE (ML + AI + Cloud)

### Complete System Flow:

```
┌─────────────────────────────────────────────────────────────────┐
│                    FARMER PHONE/LAPTOP                          │
│              (React Web App on CloudFront CDN)                  │
└────────────────────────┬────────────────────────────────────────┘
                         │ (User logs in, enters data)
                         ↓
┌─────────────────────────────────────────────────────────────────┐
│                    API GATEWAY (AWS)                            │
└────────────────────────┬────────────────────────────────────────┘
                         │
        ┌────────────────┼────────────────┐
        ↓                ↓                ↓
┌──────────────┐  ┌─────────────────┐  ┌──────────────────┐
│  RDS MySQL   │  │  Lambda Fn 1    │  │  Lambda Fn 2     │
│  (Store data)│  │  (Health check) │  │  (Forecast)      │
└──────────────┘  └────────┬────────┘  └─────────┬────────┘
       ↑                   │                     │
       │                   ↓                     ↓
       │            ┌─────────────────────────────────┐
       │            │   SageMaker Model Inference     │
       │            │  (Pre-trained ML models)        │
       │            └─────────────────────────────────┘
       │                   │
       │                   ↓
       │            ┌─────────────────────────────────┐
       │            │   Data Lake (S3)                │
       │            │  • Historical livestock data    │
       │            │  • Weather API cache            │
       │            │  • Model artifacts              │
       │            └─────────────────────────────────┘
       │
       └──────────────────┬─────────────────────────────
                         ↓
                  ┌──────────────┐
                  │  SNS Service │
                  │  Send SMS    │
                  └──────────────┘
                         ↓
                 [Farmer's Phone]
        "ALERT: COW-001 health anomaly detected"
```

---

## PART 5: HOW TO DOCUMENT THIS IN YOUR PROJECT

### For Your Proposal / Chapter 4-8:

#### A. Architecture Diagram (UML Component Diagram)

```
<<component>> Frontend React
         ↓
<<component>> API Gateway
    ┌──────┴──────┐
    ↓             ↓
<<component>>   <<component>>
PHP Backend     Lambda Functions
    ↓             ↓
<<component>>   <<component>>
RDS Database    SageMaker Models
    ↓
<<component>>
S3 Data Lake
```

#### B. Use Case Narrative for "ML Health Check"

```
Use Case: UC-010 — System Detects Health Anomaly Automatically

Actor: Admin (Farmer), System (ML Engine), Notification Service

Preconditions:
- Cow COW-001 is registered in system
- Health data for COW-001 exists for last 30 days
- ML health anomaly model is trained and deployed

Main Flow:
1. Farm worker logs daily weight and milk production for COW-001
2. Data is stored in database
3. System calls ML Lambda function with latest data
4. ML model analyzes patterns (weight drop 5%, milk drop 30%)
5. Model returns: Anomaly probability = 92%
6. System generates alert: "Health anomaly detected"
7. SNS sends SMS to farmer: "Cow COW-001 needs vet attention"
8. Farmer receives alert and calls veterinarian

Alternative Flows:
- If email preferred: Send email instead of SMS
- If multiple anomalies: Prioritize by severity score

Postconditions:
- Alert stored in AuditLog table
- Farmer receives notification within 1 minute
- Health history updated with ML prediction

Success Criteria:
- Anomaly detected within 5 seconds of data entry
- Notification delivered with 95% reliability
- False positive rate < 10%
```

#### C. Non-Functional Requirements for ML/AI/Cloud

```
ML Performance Requirements:
- Model inference latency: < 5 seconds
- Model accuracy: > 85% for health anomalies
- Model retraining: Monthly (with new data)
- Training time: < 30 minutes

Cloud Deployment Requirements:
- System uptime: 99.5% (only 3.6 hours downtime/month)
- Data backup frequency: Daily
- Database recovery time: < 1 hour
- Database recovery point: < 1 hour of data loss

AI Requirements:
- Vet chatbot response time: < 3 seconds
- Chatbot accuracy: Correct diagnosis in 80% of cases
- Multilingual support: English & Swahili

Security Requirements (Cloud):
- All data encrypted in transit (TLS 1.3)
- All data encrypted at rest (AES-256)
- Database access: Only PHP backend via IAM roles
- ML models: Versioned and audit-logged
- Compliance: GDPR, Data Protection Act 2019 (Kenya)
```

---

## PART 6: IMPLEMENTATION ROADMAP

### Phase 1 (Prototype 1 — 30%): Foundation
- ✅ Traditional database + API
- ✅ Manual health logging
- ⏳ Deploy to EC2 (basic cloud)
- **No ML yet**

### Phase 2 (Prototype 2 — 75%): Add ML Intelligence
- ✅ Set up S3 data lake
- ✅ Train health anomaly model (Isolation Forest)
- ✅ Deploy ML on Lambda
- ✅ Add production forecasting model
- ✅ Hook up SNS notifications

### Phase 3 (Prototype 3 — 90%): Full AI & Optimization
- ✅ Add vet chatbot (rule-based or NLP)
- ✅ Implement cost optimization
- ✅ Add disease outbreak prediction
- ✅ Full cloud deployment + auto-scaling
- ✅ CloudWatch monitoring

### Post-Project (Future): Enterprise Features
- Mobile app (native or PWA)
- Multi-farm management
- Real-time IoT sensor integration
- Advanced predictive maintenance

---

## PART 7: TECHNOLOGY STACK SUMMARY

| Layer | Technology | Purpose | Cost |
|-------|-----------|---------|------|
| **Frontend** | React.js | UI | Free |
| **Backend** | PHP 8.0 | API | Free |
| **Database** | RDS MySQL | Cloud database | $15/mo |
| **ML Service** | Python + scikit-learn | Model training | Free (local) |
| **ML Inference** | AWS Lambda | Run predictions | $0.20/1M calls |
| **Model Training** | SageMaker | Auto-train models | $0.50/hr |
| **Storage** | S3 | Data & backups | $1-5/mo |
| **Server** | EC2 t3.small | App hosting | $10/mo |
| **Monitoring** | CloudWatch | System health | Free (basic) |
| **Notifications** | SNS | SMS alerts | $0.50/1000 SMS |
| **CDN** | CloudFront | Fast content delivery | $0.085/GB |
| **Total** | | | **$50-80/month** |

---

## PART 8: CODE EXAMPLES

### Example 1: Health Anomaly Detection (Complete Flow)

**Frontend (React):**
```javascript
// File: frontend/src/components/HealthCheck.jsx
import React, { useState } from 'react';

export function HealthCheck() {
  const [animalId, setAnimalId] = useState('');
  const [weight, setWeight] = useState('');
  const [milkProduction, setMilkProduction] = useState('');
  const [result, setResult] = useState(null);

  const handleCheck = async () => {
    const response = await fetch('/api/check_health', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        animal_id: animalId,
        weight_change: weight,
        produce_change: milkProduction
      })
    });

    const data = await response.json();
    setResult(data);

    if (data.anomaly) {
      // Show alert
      alert(`⚠️ HEALTH ALERT: ${data.message}`);
      // Send SMS
      await fetch('/api/send_sms', {
        method: 'POST',
        body: JSON.stringify({ animal_id: animalId, message: data.message })
      });
    }
  };

  return (
    <div>
      <h2>Health Check</h2>
      <input placeholder="Animal ID" value={animalId} onChange={(e) => setAnimalId(e.target.value)} />
      <input placeholder="Weight change (%)" value={weight} onChange={(e) => setWeight(e.target.value)} />
      <input placeholder="Milk change (%)" value={milkProduction} onChange={(e) => setMilkProduction(e.target.value)} />
      <button onClick={handleCheck}>Check Health</button>

      {result && (
        <div style={{border: result.anomaly ? '2px solid red' : '2px solid green'}}>
          <p>Status: {result.anomaly ? '⚠️ ANOMALY' : '✅ NORMAL'}</p>
          <p>Confidence: {(result.confidence * 100).toFixed(1)}%</p>
        </div>
      )}
    </div>
  );
}
```

**Backend (PHP):**
```php
// File: backend/api/check_health.php
<?php
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

// Call Lambda function (ML inference)
$lambda_response = file_get_contents('https://lambda-endpoint.execute-api.ap-southeast-1.amazonaws.com/prod/predict', false,
    stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode([
                'weight_change' => $data['weight_change'],
                'produce_change' => $data['produce_change'],
                'days_since_vaccine' => 45,
                'age_days' => 1460
            ])
        ]
    ])
);

$prediction = json_decode($lambda_response, true);

// Save to database
$conn = new mysqli("flouri-farm.rds.amazonaws.com", "admin", "password", "lfmis");
$conn->query("INSERT INTO ml_predictions (animal_id, anomaly, confidence, created_at) 
             VALUES ('{$data['animal_id']}', " . ($prediction['anomaly'] ? 1 : 0) . ", {$prediction['confidence']}, NOW())");

// Send SMS if anomaly
if ($prediction['anomaly']) {
    $sns = new Aws\Sns\SnsClient([
        'version' => 'latest',
        'region'  => 'ap-southeast-1'
    ]);
    
    $sns->publish([
        'Message' => 'ALERT: ' . $data['animal_id'] . ' shows health anomaly',
        'PhoneNumber' => '+254712345678'
    ]);
}

echo json_encode($prediction);
?>
```

---

## SUMMARY: WHAT TO BUILD

| Feature | Tech | Difficulty | Impact | Timeline |
|---------|------|-----------|--------|----------|
| Health Anomaly Detection | Python + scikit-learn | Easy | High | Week 2-3 |
| Production Forecasting | ARIMA/Prophet | Medium | High | Week 3-4 |
| Vet Chatbot | Rule-based or NLP | Medium | Medium | Week 4-5 |
| Cloud Deployment | AWS EC2 + RDS | Easy | High | Week 5-6 |
| Lambda ML Inference | AWS Lambda | Medium | High | Week 6-7 |
| SNS Notifications | AWS SNS | Easy | Medium | Week 6 |
| Cost Optimization AI | Regression models | Medium | Medium | Week 7-8 |

**Total: 8 weeks → Fits in Semester 2!**

---

## NEXT STEP: CLARIFICATION QUESTIONS

1. **ML Priority:** All three (health, forecast, cost) or focus on 1-2?
2. **Cloud Choice:** AWS, Google Cloud, Azure, or DigitalOcean?
3. **Budget:** Can you afford $50-100/month for cloud, or need free option?
4. **Timeline:** Implement ML in Prototype 2 (75%) or wait until Prototype 3 (90%)?
5. **Data:** Do you have historical livestock data to train models, or will you use synthetic data?

