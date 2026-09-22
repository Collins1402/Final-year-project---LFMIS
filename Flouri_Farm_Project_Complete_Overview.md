# Flouri Farm LFMIS — Complete Project Overview

## PROJECT IDENTITY
- **Full Title:** Design and Development of a Web-Based Livestock Farm Management Information System (LFMIS)
- **Case Study:** Flouri Farm — mixed enterprise managing cows, goats, rabbits, and chickens
- **Student:** Collins Kariuki Kibiru (Admission No. 170160, BBIT 4B)
- **Supervisor:** Dr. Roent Ombasa
- **Institution:** School of Computing and Engineering Sciences, Strathmore University
- **Course:** IS Project II (IS 4201)
- **Proposal Defence:** July 2026 ✅ (COMPLETED)

---

## SEMESTER 1 — COMPLETED & SUBMITTED ✅

### Chapter 1: Background
- **Status:** ✅ Completed
- **Content:**
  - Livestock contributes ~40% of global agricultural GDP; ~12% of Kenya's national GDP
  - <15% of SSA smallholder farmers use digital record-keeping
  - Flouri Farm manages 4 species (cows, goats, rabbits, chickens) with no centralized system
  - Current state: manual, paper-based record-keeping leading to missed interventions, lost records, poor traceability

### Chapter 2: Problem Statement & Justification
- **Status:** ✅ Completed
- **The Problem:**
  - Records scattered across multiple notebooks per animal category → prone to error, hard to retrieve
  - Milk and egg produce logged inconsistently; inventory estimated, not tracked
  - Low digital/formal literacy among farm workers
  - Farmer cannot monitor health compliance, forecast produce, track costs, or react to emergencies
  
- **Justification:**
  - Digital livestock tools show clear benefits in herd health and productivity
  - ICT-based systems reduce admin time and improve decision quality
  - Existing tools (CattleMax, FarmWizard, SmartCow) target commercial, high-connectivity markets — not Kenyan smallholder context
  - Aligns with Kenya Big Four Agenda and Vision 2030

### Chapter 3: Literature Review & Gap Analysis
- **Status:** ✅ Completed
- **Comparison Table:** CattleMax, FarmWizard, Jaguza, SmartCow, iCow, LFMIS Namibia
- **Gap Identified:**
  - **No existing system combines:**
    1. Multi-species health tracking (cows, goats, rabbits, chickens)
    2. Produce & inventory management (milk, eggs, hay, salt lick)
    3. Low-literacy UI design
    4. Affordable, accessible platform for Kenyan smallholders
  - **Proposed LFMIS is unique** in offering all four capabilities together

### Design Philosophy & Methodology
- **Status:** ✅ Completed
- **OOAD (Object-Oriented Analysis & Design):**
  - Objects: animals, health events, produce entries, inventory records, income transactions
  - Enables modular design, easier maintenance, better scalability
  
- **Prototype Model (SDM):**
  - Early working versions the farmer evaluates before full commitment
  - Matches IS Project assessment milestones exactly
  - Critical for non-technical, low-literacy stakeholder

### Proposal Defence Documentation
- **Status:** ✅ Submitted
- **Deliverables:**
  - Presentation slides (10 slides)
  - Spoken defence script
  - Q&A preparation document

---

## SEMESTER 2 — SPRINT STRUCTURE (Current/Upcoming)

### Timeline & Milestones

| Week | Deliverable | Progress |
|------|-------------|----------|
| Wk 7–9 | Proposal Defence | ✅ DONE |
| Wk 10–11 | Prototype 1 (30%) | 🔄 IN PROGRESS |
| Sem 2 Sp1 | Prototype 1 Submission | 📅 PENDING |
| Sem 2 Sp2 | Prototype 2 (75%) | 📅 PENDING |
| Sem 2 Sp3 | Prototype 3 (90%) + Final Documentation | 📅 PENDING |

---

### PROTOTYPE 1 (30% of Final System) — CURRENT
**Target:** Core livestock and health management foundation

#### Functional Modules:
1. **Animal Profile Management**
   - Register animals (add, edit, delete)
   - Track: animal ID, species, breed, age, weight, health status
   - Assign animals to farm workers/sections

2. **Health Activity Management**
   - Log health activities: deworming, treatment, vaccinations, medications
   - Track awaiting & received status
   - Record maintenance records
   - Link activities to specific animals

#### Deliverables Expected:
- Working prototype (React + PHP + MySQL backend)
- Functional requirements documentation (2 per actor minimum)
- Non-functional requirements (usability, performance, security, uptime)
- Initial UML diagrams (use cases for Admin & Farm Worker)
- Database schema (partial)

---

### PROTOTYPE 2 (75% of Final System)
**Target:** Add produce, inventory, income, and reporting

#### New Functional Modules:
3. **Produce Management**
   - Milk tracking: volume (litres), time (morning/evening), monthly averages
   - Eggs tracking: trays, frequency

4. **Inventory Management**
   - Materials/Variables: Hay, Salt Lick
   - Track consumption, reorder points, supplier integration

5. **Income Management**
   - Sales of surplus: male/old cows, hens & chicks, rabbits
   - Till numbers, receipt generation

6. **Notifications & Reporting Dashboard**
   - Real-time alerts for health schedules, inventory levels
   - Performance reports (produce, revenue, expenses)

#### Deliverables Expected:
- Full use case diagram with all actors
- Sequence diagrams (2–3 key use cases)
- Updated ERD with all entities
- Complete functional & non-functional requirements by actor
- Use case narratives (2–3 detailed)

---

### PROTOTYPE 3 (90% of Final System)
**Target:** Full testing, mobile-responsiveness, final refinements

#### Final Additions:
7. **Customer Service Module** (optional/enhancement)
   - Complaints, feedback, suggestions, FAQ
   
8. **Maintenance Records** (formalized logging)

#### Testing & QA:
- Functional testing (all modules)
- Usability testing (SUS — System Usability Scale)
- Mobile-responsive refinement
- Performance optimization

#### Final Documentation:
- Complete technical documentation
- System architecture diagrams
- Deployment guide
- User manual for low-literacy farmers

---

## ACTORS IN THE SYSTEM

### Primary Actors (Users) 👤
1. **Admin (Farmer/Farm Owner)**
   - Manages overall farm operations, views reports, makes decisions
   
2. **Farm Worker/Laborer**
   - Logs daily activities, records incidents, low-bandwidth operations required

3. **Veterinarian** *(uncertain if to include — marked in notes)*
   - Views health records, records treatments, diagnoses
   - Mobile and desktop access
   
4. **Livestock Buyer/Market**
   - Views available livestock for purchase
   - Places orders, negotiates prices

5. **Feed Supplier**
   - Views feed requirements and orders
   - Submits invoices, delivery confirmations

### Secondary Actors (Systems) 🔗
6. **MPESA (Payment Gateway)**
   - Processes and verifies transactions
   - Sends payment confirmations
   - Must comply with PCI-DSS

7. **Bank/Financial Institution**
   - Accesses farm financial records and loan history
   - Generates reports for loan assessment
   - Requires encryption and audit-logging

8. **System Administrator**
   - Manages user accounts and permissions
   - Monitors system performance
   - Real-time monitoring and daily backups

---

## FUNCTIONAL & NON-FUNCTIONAL REQUIREMENTS BY ACTOR

### 1. Admin (Farmer/Farm Owner)
**Functional:**
- Manage and track livestock inventory (add, edit, delete animals with health status, breed, age, weight)
- Generate reports on farm performance, expenses, and revenue

**Non-Functional:**
- User-friendly with intuitive navigation
- 24/7 accessibility with 99% uptime
- **Low-literacy UI** — icons, visual cues, minimal text jargon

### 2. Farm Worker/Laborer
**Functional:**
- Log daily activities (feeding, cleaning, animal observation)
- Record animal incidents or health concerns
- Quick data entry with minimal typing

**Non-Functional:**
- Support low-bandwidth operations
- Offline mode with sync when connection restored
- Simple, icon-based interface

### 3. Veterinarian
**Functional:**
- View livestock health records and medical history
- Record vaccinations, treatments, and diagnoses for animals

**Non-Functional:**
- Medical data encrypted and secure
- Mobile and desktop accessible
- Quick access to critical health information

### 4. Livestock Buyer/Market
**Functional:**
- View available livestock for purchase with detailed specifications
- Place purchase orders and negotiate prices
- Track order status

**Non-Functional:**
- Product listings load within 3 seconds
- Real-time inventory updates
- Support mobile browsing

### 5. Feed Supplier
**Functional:**
- View farm feed requirements and pending orders
- Submit invoices and delivery confirmations
- Track payment status

**Non-Functional:**
- Timely notifications for pending orders
- Support multiple file formats (PDF, Excel)
- Email and SMS notification integration

### 6. MPESA (Payment Gateway)
**Functional:**
- Process and verify payment transactions securely
- Send payment confirmation notifications
- Generate transaction logs

**Non-Functional:**
- Payment processing completes within 5 seconds
- PCI-DSS compliance
- Audit trail for all transactions

### 7. Bank/Financial Institution
**Functional:**
- Access farm financial records and loan history
- Generate financial reports for loan assessment

**Non-Functional:**
- All financial data encrypted
- Audit-logged with tamper evidence
- Compliance with banking regulations and data protection laws

### 8. System Administrator
**Functional:**
- Manage user accounts and access permissions (RBAC)
- Monitor system performance and troubleshoot issues
- Generate system health reports

**Non-Functional:**
- Real-time system monitoring dashboard
- Automatic daily data backups
- Security log auditing

---

## WHAT STILL NEEDS TO BE ADDED ⚠️

### 1. **UML Analysis Diagrams** (CRITICAL)

#### A. Use Case Diagram
- **Actors:** Show as stickman (👤) for users, <<MPESA>>, <<Bank>>, <<SMS>> for systems
- **Scope:** System boundary box
- **Use Cases:** Ovals for each functional capability
- **Relationships:**
  - Association lines (—) from actors to use cases
  - `<<include>>` for mandatory sub-flows (e.g., Place Order includes Process Payment)
  - `<<extend>>` for optional/conditional flows (e.g., Log Activity extends to Record Health Issue if symptoms detected)

**Key Use Cases to Define:**
- Register Animal
- Log Health Activity
- Record Produce Output
- Manage Inventory
- Process Payment (via MPESA)
- Generate Report
- Place Order
- View Health Records (Vet)

#### B. Entity-Relationship Diagram (ERD)
- **Entities (singular, capitalized first letter):**
  - User
  - Livestock
  - Activity
  - HealthRecord
  - Produce
  - Inventory
  - Order
  - OrderDetail (junction table for many-to-many)
  - Transaction
  - FinancialRecord
  - AuditLog

- **Attributes per entity:**
  - Primary Keys (underlined)
  - Foreign Keys
  - Data types (INT, VARCHAR, DATE, DECIMAL, etc.)
  - Constraints (NOT NULL, UNIQUE, etc.)

- **Cardinality Notations:**
  - 1:1, 1:N (1 to many), N:1, N:N (many-to-many)
  - Use standard crow's foot notation

**Example Relationships:**
- User (1) → (N) Livestock
- Livestock (1) → (N) Activity
- Livestock (N) → (M) Order (via OrderDetail junction table)
- HealthRecord (N) → (1) Livestock
- Transaction (N) → (1) User

#### C. Sequence Diagrams
- **Key Flows (2–3 critical paths):**
  1. "Log Health Activity" → optional extend to "Record Health Issue" (if symptoms noted)
  2. "Place Order" → mandatory include "Process Payment via MPESA"
  3. "View Dashboard Report" (showing data retrieval and aggregation)

- **Lifelines:** Actors, System, Database, MPESA Gateway
- **Messages:**
  - Solid arrows (→) for synchronous calls
  - Dashed arrows (⇢) for asynchronous/responses
  - Self-calls for internal logic

### 2. **Use Case Narratives** (2–3 detailed)

**Template for each:**
- Use Case Name & ID (UC-001, UC-002, etc.)
- Actor
- Preconditions
- Main Flow (step-by-step)
- Alternative Flows
- Postconditions
- Success Criteria

**Example Narratives to Develop:**
- UC-001: Admin Registers New Animal
- UC-002: Farm Worker Records Health Activity
- UC-003: Place Livestock Order & Process Payment

### 3. **Advanced Features (4th Year Feel)** ⚠️ CRITICAL

#### A. Machine Learning Integration
**What to add:**
- **Predictive Health Monitoring:**
  - Anomaly detection on health event patterns
  - Alert system for unusual activity (e.g., sudden weight loss, missed vaccinations)
  - Predictive model for breeding cycles
  
- **Produce Forecasting:**
  - Time-series forecasting (milk/egg production trends)
  - Seasonal pattern analysis
  
- **Cost Optimization:**
  - Feed cost prediction based on market trends
  - Optimal breeding time recommendations

**Implementation approaches:**
- Python backend service (scikit-learn, pandas)
- API calls from PHP backend
- Pre-trained models (e.g., ARIMA for time series)

#### B. Weather Integration & Profit Optimization
**What to add:**
- **Weather API Integration:**
  - Real-time weather data (OpenWeatherMap or local KMS data)
  - Historical weather patterns
  
- **Correlation Analysis:**
  - Link weather (rainfall, temperature, sunshine) to:
    - Milk production (drops in dry seasons)
    - Egg production (affected by heat stress)
    - Pasture quality (affects feed costs)
    - Feed requirements (seasonal variation)
  
- **Profit Dashboard:**
  - "Earned this week considering weather: KES X"
  - "Forecast: expected production in rainy season"
  - "Feed cost projection" (based on weather-driven demand)
  
- **Recommendations:**
  - "Increase feed quantity; drought forecast for 2 weeks"
  - "Optimal breeding window considering climate"

### 4. **Data Collection & User Research** (Methodological)
- **Who is a user?** Define personas for each actor type
- **Data sources:**
  - Manual entry (farm worker, farmer)
  - MPESA API (payment data)
  - Weather API (climate data)
  - Vet records (health data upload)
  
- **Data collection strategy:**
  - Initial farm audit (document existing practices)
  - Training period (1–2 weeks supervised use)
  - Feedback loops (SUS questionnaire, interviews)
  - Ethical considerations (data privacy, farmer consent)

### 5. **Mobile App Consideration**
- Responsive web design first (Prototype 1–3)
- Native mobile app (post-final system) or Progressive Web App (PWA)
- Offline-first architecture for farm workers

### 6. **Cloud Computing & Deployment**
- **Transfer of Database to Buyer after Purchase:**
  - How to export animal records securely when a livestock is sold
  - Data portability and privacy considerations
  
- **Cloud Platform:**
  - AWS, Azure, or Google Cloud for scalability
  - Database backup and disaster recovery
  - Multi-tenant considerations (support multiple farms in future)

### 7. **System Design Diagrams**
- **Architecture Diagram:** Frontend (React) ↔ API Gateway ↔ Backend (PHP) ↔ Database (MySQL)
- **Component Diagram:** Modules interaction (Animal Management, Health, Produce, Inventory, Payments)
- **Deployment Diagram:** Development, Testing, Production environments

### 8. **Data Validation & Security**
- **Input Validation:** Ensure data quality (no manual massage of data)
- **Access Control:** RBAC (role-based) by actor type
- **Encryption:** Sensitive data (health records, financial data)
- **Audit Trail:** All changes logged for compliance

### 9. **Testing Strategy**
- **Functional Testing:** Each use case tested
- **Non-Functional Testing:**
  - Performance (response time <3s for reports)
  - Security (PCI-DSS for payments, encryption)
  - Usability (SUS score target: >70 for low-literacy users)
  - Compatibility (mobile, desktop, low-bandwidth)

---

## TECHNICAL STACK (CONFIRMED)

| Component | Technology |
|-----------|-----------|
| **Frontend** | React.js, JavaScript |
| **Backend** | PHP (REST API) |
| **Database** | MySQL (XAMPP) |
| **Payment Gateway** | MPESA |
| **Mobile** | Responsive web (PWA possible) or native app (post-Phase 3) |
| **Data Analysis** | Python (scikit-learn, pandas) for ML features |
| **Weather Data** | OpenWeatherMap API or Kenya Met Department |

---

## KEY TERMINOLOGY & FRAMEWORKS TO USE

- **OOAD:** Object-Oriented Analysis & Design
- **UML:** Unified Modeling Language (use cases, ERD, sequence diagrams)
- **RBAC:** Role-Based Access Control
- **ACID:** Atomicity, Consistency, Isolation, Durability (database compliance)
- **PCI-DSS:** Payment Card Industry Data Security Standard
- **SUS:** System Usability Scale (for low-literacy testing)
- **Prototype Model:** Iterative development with stakeholder feedback
- **Use Case `<<include>>`:** Mandatory sub-flow
- **Use Case `<<extend>>`:** Optional/conditional sub-flow

---

## CRITICAL NOTES

### DO NOT DO:
- ❌ Massage or fabricate data
- ❌ Use vague actor definitions
- ❌ Skip requirements documentation
- ❌ Overlook low-literacy UI considerations
- ❌ Ignore MPESA integration complexity

### MUST DO:
- ✅ Draw UML diagrams with proper notation (stickman vs. <<System>>)
- ✅ Define functional + non-functional requirements for EVERY actor
- ✅ Include use case narratives with preconditions & postconditions
- ✅ Integrate ML and weather features for 4th-year credibility
- ✅ Test with actual low-literacy users (or simulate)
- ✅ Document data sources and collection methodology
- ✅ Ensure compliance with PCI-DSS and data protection laws

---

## NEXT STEPS BY PRIORITY

**Immediate (for Prototype 1 documentation):**
1. Complete UML use case diagram (all 8 actors)
2. Draft 2–3 detailed use case narratives
3. Finalize functional & non-functional requirements per actor
4. Create initial ERD with core entities

**Short-term (for Prototype 2):**
5. Sequence diagrams for critical flows
6. ML feature specification document
7. Weather API integration design
8. Mobile responsiveness testing plan

**Final (for Prototype 3 + submission):**
9. Complete system architecture diagrams
10. Testing strategy & results
11. Final documentation package
12. Deployment guide for cloud hosting

---

## QUESTION TO CLARIFY BEFORE PROCEEDING

1. **Include Veterinarian as a formal actor** in the system, or remove?
2. **Priority for ML features:**
   - High: Predictive health monitoring?
   - High: Produce forecasting?
   - High: Cost optimization?
   - Or equal priority for all?
3. **Weather integration scope:**
   - Just display current weather?
   - Or build profit correlation model?
4. **Mobile strategy:**
   - Responsive web only?
   - Native iOS/Android app?
   - Progressive Web App (PWA)?
5. **Cloud deployment timing:**
   - Part of Prototype 3?
   - Or post-project future work?
