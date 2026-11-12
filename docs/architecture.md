# BSIT Capstone Fuzzy Evaluation System Architecture

## 1. System Context

The BSIT Capstone Fuzzy Evaluation System is a PHP and MySQL web application for evaluating capstone projects through two controlled inputs: system/project quality and technical paper quality. A dean manages projects, users, and evaluators and can inspect the fixed fuzzy rules and membership functions. Evaluators score assigned projects. Students view their project status, scores, feedback, and the fuzzy decision trace.

The application is deployed as a server-rendered PHP site under XAMPP. Apache serves the pages, PHP executes business logic, and MySQL stores users, projects, evaluations, assignments, feedback, and history. The runtime fuzzy configuration is source-controlled in `php/fuzzy_engine.php`; legacy fuzzy configuration tables are not used.

## 2. High-Level Component Diagram

```mermaid
flowchart LR
    Browser[Web Browser]
    Apache[Apache / XAMPP]
    PHP[PHP Application]
    Config[config.php\nPDO + sessions + CSRF helpers]
    Engine[fuzzy_engine.php\nOfficial verdict rules and analysis trace]
    Dean[Dean Module]
    Evaluator[Evaluator Module]
    Student[Student Module]
    API[JSON Validation and Simulation APIs]
    DB[(MySQL\ncapstone_fuzzy_eval)]
    PDF[Report and PDF Export]

    Browser --> Apache
    Apache --> PHP
    PHP --> Config
    Config --> Dean
    Config --> Evaluator
    Config --> Student
    Config --> API
    Config --> PDF
    Config --> Engine
    Dean --> DB
    Evaluator --> DB
    Student --> DB
    API --> Engine
    Engine --> DB
    PDF --> DB
    PDF --> Engine
```

## 3. Role-Based Module Architecture

```mermaid
flowchart TB
    Login[index.php\nAuthentication and role router]
    Login --> DeanDashboard[dean/dashboard.php]
    Login --> EvaluatorDashboard[evaluator/dashboard.php]
    Login --> StudentDashboard[students/student_dashboard.php]

    DeanDashboard --> Users[users.php]
    DeanDashboard --> Assign[assign_evaluators.php]
    DeanDashboard --> Rules[fuzzy_rules.php]
    DeanDashboard --> MF[membership_functions.php]
    DeanDashboard --> Analytics[analytics.php]
    DeanDashboard --> Reports[generate_report.php\ndownload_report.php]

    EvaluatorDashboard --> ProjectEval[evaluate_project.php]
    EvaluatorDashboard --> PaperEval[evaluate_paper.php]
    ProjectEval --> Engine[fuzzy_engine.php]
    PaperEval --> Engine

    StudentDashboard --> Trace[Decision explanation\ninput memberships + fired rules]
    Reports --> Trace
```

## 4. End-to-End Sequence

```mermaid
sequenceDiagram
    actor Dean
    actor Evaluator
    actor Student
    participant Login as index.php
    participant App as PHP pages
    participant DB as MySQL
    participant Engine as fuzzy_engine.php

    Dean->>Login: Submit credentials
    Login->>DB: Verify user and role
    DB-->>Login: Dean account
    Login-->>Dean: Open dean dashboard
    Dean->>App: Assign evaluator to project
    App->>DB: Insert evaluator_assignments row

    Evaluator->>Login: Submit credentials
    Login->>DB: Verify evaluator account
    Login-->>Evaluator: Open evaluator dashboard
    Evaluator->>App: Submit system criteria
    App->>DB: Insert evaluations_project
    App->>Engine: Build verdict-based analysis from component scores
    Engine->>Engine: Map rounded inputs to official verdict bands
    Engine-->>App: Verdict memberships, matching rule, and analysis value
    App->>DB: Consolidate latest project score per evaluator

    Evaluator->>App: Submit paper chapters
    App->>DB: Insert evaluations_paper
    App->>DB: Consolidate latest project and paper scores per evaluator
    App->>App: Calculate unweighted official average and rounded verdict
    App->>DB: Update official average and CEIT-T07B verdict

    Student->>Login: Submit credentials
    Login-->>Student: Open student dashboard
    Student->>DB: Read own project and evaluations
    Student->>Engine: Build explanation trace
    Engine-->>Student: Membership values and fired rules
    Student-->>Student: View transparent result explanation
```

## 5. Data Architecture

```mermaid
erDiagram
    USERS ||--o{ PROJECTS : owns
    USERS ||--o{ EVALUATOR_ASSIGNMENTS : evaluates
    PROJECTS ||--o{ EVALUATOR_ASSIGNMENTS : receives
    PROJECTS ||--o{ EVALUATIONS_PROJECT : has
    PROJECTS ||--o{ EVALUATIONS_PAPER : has
    PROJECTS ||--o{ EVALUATION_FEEDBACK : receives
    PROJECTS ||--o{ EVALUATION_HISTORY : records
    USERS ||--o{ EVALUATION_FEEDBACK : writes
    USERS ||--o{ EVALUATION_HISTORY : creates

    USERS {
        int id PK
        string full_name
        string email
        string role
        string school_id
        string department
    }
    PROJECTS {
        int id PK
        string code
        string title
        int student_id FK
        decimal project_score
        decimal paper_score
        decimal overall_score
        string overall_rating
        string status
    }
    EVALUATOR_ASSIGNMENTS {
        int id PK
        int project_id FK
        int evaluator_id FK
        int assigned_by FK
        string status
    }
    EVALUATIONS_PROJECT {
        int id PK
        int project_id FK
        int evaluator_id FK
        decimal total_score
        text remarks
    }
    EVALUATIONS_PAPER {
        int id PK
        int project_id FK
        int evaluator_id FK
        decimal total_score
        text remarks
    }
    MEMBERSHIP_FUNCTIONS {
        int id PK
        string variable
        string label
        decimal a
        decimal b
        decimal c
        decimal d
    }
    FUZZY_RULES {
        int id PK
        text rule_text
        string status
    }
}
```

## 6. Main Processing Pipeline

1. `index.php` authenticates the user and redirects by role.
2. `config.php` creates the PDO connection, provides escaping and CSRF helpers, and loads the fuzzy engine.
3. The dean registers projects and assigns evaluators through `dean/assign_evaluators.php`.
4. `evaluator/evaluate_project.php` collects ten 0-10 system criteria and normalizes their sum to a 0-100 project score.
5. `evaluator/evaluate_paper.php` collects five 0-20 chapter scores and normalizes their sum to a 0-100 paper score.
6. `fuzzy_engine.php` loads verdict bands and generates the 25 verdict-combination rules from source code.
7. The engine maps each input to one verdict category, applies minimum rule firing and maximum consequent aggregation, and calculates the representative-score output.
8. The project record stores `project_score`, `paper_score`, `overall_score`, and `overall_rating`.
9. The shared CEIT-T07B verdict function classifies the rounded official panel-average score; the separate crisp rule-analysis output is only for explanation.
10. The dean report page presents the same result for printing or PDF generation.

## 7. Security and Control Boundaries

- Role checks are performed on dean, evaluator, and student pages.
- Database queries use PDO prepared statements for user-supplied identifiers and form values.
- POST forms use CSRF tokens through `csrf_token()` and `csrf_check()`.
- Output is escaped through `esc()` before being rendered in HTML.
- Evaluator visibility is restricted by the evaluator assignment workflow and dashboard query.
- Dean configuration pages and validation endpoints are read-only or disabled; fixed fuzzy rules and membership functions cannot be changed through the application.

## 8. Deployment View

```mermaid
flowchart LR
    User[Dean / Evaluator / Student Browser]
    Apache[Apache Web Server]
    PHP[PHP Runtime in XAMPP]
    MySQL[MySQL Server]
    Composer[Composer packages\nDompdf optional]

    User --> Apache
    Apache --> PHP
    PHP --> MySQL
    PHP --> Composer
```

## 9. Current Scope and Technical Note

The implementation is a prototype suitable for a capstone demonstration and controlled institutional evaluation workflow. Verdict analysis uses crisp one-hot membership, minimum rule firing, maximum consequent aggregation, and representative-score averaging. Because the membership bands do not overlap, this is not gradual fuzzy inference and should be described accurately in the thesis. The official result always comes from the panel-average score and CEIT-T07B classifier.
