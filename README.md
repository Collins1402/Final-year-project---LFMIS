# Design and Development of a Web-Based Livestock Farm Management Information System

## Project Overview

This project involves the design and development of a web-based Livestock Farm Management Information System (LFMIS) for Flouri Farm. The system will provide one centralized platform for recording livestock information, monitoring animal health and farm activities, managing farm produce and materials, processing customer orders and payments, and producing useful operational and financial reports.

The project responds to the problem of livestock farmers relying on fragmented manual records. These records can make it difficult to trace an animal's history, monitor vaccinations and treatment, track produce, manage inventory, verify transactions, and make timely business decisions. The proposed system is intended to improve record accessibility, livestock traceability, customer service, and farm management without adding unnecessary complexity for users with limited digital literacy.

## Proposed Scope

The system will cover:

- Animal profiles and livestock inventory for cows, goats, rabbits, and chickens.
- Health and maintenance records, including deworming, treatment, vaccinations, medication, and incidents.
- Farm activities such as feeding, cleaning, observation, breeding, and animal movement.
- Produce records for milk in litres, including morning and evening quantities by date and month, and eggs in trays.
- Materials and stock records for items such as hay and salt lick.
- Sales, income, expenses, customer orders, receipts, M-Pesa payments, till number generation, prompts, and loyalty points.
- Customer service through complaints, feedback, suggestions, and frequently asked questions.
- Livestock and produce sales, including the sale of male and old cows, hens and chicks, and rabbits.
- Reports that support farm performance, profitability, and decision-making.

The frontend will be developed with React and JavaScript. The backend will use PHP, with MySQL as the database during development through XAMPP. The design will also consider a future mobile application and low-bandwidth or offline use by farm workers.

## Users and External Systems

The main users and external actors will be:

- **Admin (Farmer/Farm Owner):** manages livestock, farm records, users, sales, and reports.
- **Farm Worker/Laborer:** records daily activities, observations, incidents, and stock usage.
- **Veterinarian:** views animal health history and records diagnoses, treatment, and vaccinations where applicable.
- **Livestock Buyer/Market:** views available livestock or produce and submits orders.
- **Feed Supplier:** responds to feed requirements and provides delivery or invoice information.
- **System Administrator:** manages accounts, permissions, backups, and system monitoring.
- **M-Pesa:** external payment gateway that processes and confirms customer payments.
- **Bank/Financial Institution:** external stakeholder that may receive authorized financial or loan-related reports.

In UML use case diagrams, people will be represented as actor stick figures, while external systems such as M-Pesa will use system notation such as `<<M-Pesa>>`. The system boundary will clearly separate LFMIS functionality from external actors.

## Data Collection and Analysis

Data will come from farm records and activities, including animal registration, worker observations, veterinary entries, produce measurements, stock updates, customer orders, payment confirmations, expenses, sales, and customer-service interactions. Additional contextual data may include daily weather information such as rainfall, temperature, and sunshine conditions from a suitable weather service or approved public dataset.

Data will be collected through system forms, farm observation, interviews with the farmer and workers, review of existing records, veterinary records, customer transactions, supplier documents, and payment-gateway responses. Data will be entered as it is generated and validated before storage. The project will not manipulate, massage, or fabricate data; analysis will clearly distinguish between recorded data, derived measures, and predictions.

Analysis may use spreadsheets, SQL queries, dashboards, descriptive statistics, and visualizations. These tools will help identify production trends, animal-health patterns, stock usage, sales performance, customer activity, and the relationship between weather conditions and farm income or produce output.

## Machine Learning and Decision Support

To give the project an appropriate fourth-year level, the system will include a small, clearly scoped machine-learning or predictive analytics component. Possible applications include:

- Predicting expected milk or egg production from historical production, animal, and weather data.
- Identifying unusual changes in production or animal-health records for early attention.
- Forecasting feed or material requirements from previous usage and livestock numbers.
- Estimating likely high-demand periods to support pricing and sales planning.

The model will be treated as decision support, not as a replacement for the farmer or veterinarian. Its performance will be evaluated using real collected data where sufficient data is available, and its limitations will be documented. Weather data will be used to investigate whether rainy, sunny, or unusually hot periods are associated with changes in production, sales, expenses, or profit. The project will report observed relationships carefully and will not claim that weather causes a business outcome without adequate evidence.

## Requirements and Design Deliverables

The project will define functional requirements describing what each actor can do and non-functional requirements describing quality expectations such as usability, security, availability, response time, accessibility, low-bandwidth support, backup, and data protection. At least two key functional requirements will be specified for each actor.

The analysis and design stage will include:

- UML use case diagram with actors, system actors, system boundary, and use cases.
- Two or three key use case narratives, such as recording animal treatment, placing an order, and confirming payment.
- System sequence diagrams and detailed sequence diagrams for selected workflows.
- Entity Relationship Diagram (ERD) showing singular entity names, primary keys, foreign keys, cardinalities, and explicit relationships such as one-to-one, one-to-many, many-to-one, many-to-many, and zero-to-many where applicable.
- Database design and implementation in MySQL.
- Interface designs that account for users with limited literacy through simple navigation, clear labels, familiar icons, validation, and accessible feedback.

## Development Plan

The first semester will focus on the concept note, project proposal, literature and problem analysis, requirements, and design diagrams. Proposal submission, defence, and feedback will be incorporated before implementation.

The second semester will use progressive sprints:

- **Sprint 1:** approximately 30% of the final system, covering the core database, authentication, livestock records, and basic farm activities.
- **Sprint 2:** approximately 75% of the final system, adding health records, produce, materials, orders, payments, and reporting.
- **Sprint 3:** approximately 90% of the final system, refining customer service, notifications, analytics or machine learning, testing, usability, and final documentation.

The final evaluation will consider whether LFMIS provides a better solution than fragmented manual methods by measuring record accessibility, traceability, transaction efficiency, reporting usefulness, usability, and the quality of decisions supported by the system.
