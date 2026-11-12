# Final System Implementation Report

## Project Title

**CEIT-T07B Verdict-Based Scoring System for BSIT Capstone Project Evaluation**

## 1. Executive Summary

This project implemented a browser-based capstone evaluation system for BSIT projects. The system replaces disconnected manual scoring steps with one role-based workflow that covers project assignment, evaluator scoring, official verdict classification, student result viewing, and report generation.

The implementation uses PHP, MySQL, PDO, HTML, CSS, JavaScript, and optional Composer packages. It is deployable in XAMPP and uses source-controlled verdict bands and generated rules; the Dean can inspect this configuration but cannot edit it through the application.

## 2. Implemented Features

| Area | Implemented capability |
|---|---|
| Authentication | Session-based login and role routing. |
| Dean administration | User management, analytics, read-only fuzzy configuration, assignments, reports. |
| Evaluator workflow | Assigned-project dashboard, system rubric, paper chapters, remarks, score saving. |
| Verdict analysis | Five official verdict bands, crisp one-hot memberships, 25 generated rules, minimum rule firing, maximum consequent aggregation, representative-score output. |
| Official verdict | Latest score per evaluator is averaged per component; the unweighted mean of panel-average system and paper scores is rounded and classified through CEIT-T07B. |
| Student portal | Project status, score summary, feedback, history, notifications, profile, fuzzy trace. |
| Reporting | Printable project report, Dompdf support, wkhtmltopdf fallback, browser-side jsPDF fallback. |
| Validation | Disabled rule and membership-function validation endpoints plus executable fuzzy test harness. |
| Security controls | Role guards, prepared statements, output escaping, CSRF validation, password hashing support. |

## 3. Implementation Mapping

- Authentication and routing: [index.php](../index.php)
- Database and shared helpers: [config.php](../config.php)
- Fuzzy engine: [fuzzy_engine.php](../fuzzy_engine.php)
- Dean assignment workflow: [dean/assign_evaluators.php](../dean/assign_evaluators.php)
- Project evaluation: [evaluator/evaluate_project.php](../evaluator/evaluate_project.php)
- Paper evaluation: [evaluator/evaluate_paper.php](../evaluator/evaluate_paper.php)
- Student result explanation: [students/student_dashboard.php](../students/student_dashboard.php)
- Report display: [dean/generate_report.php](../dean/generate_report.php)
- PDF endpoint: [dean/download_report.php](../dean/download_report.php)
- Database schema: [sql/fuzzy_eval.sql](../sql/fuzzy_eval.sql)
- Fuzzy tests: [tests/FuzzyEngineTest.php](../tests/FuzzyEngineTest.php)

## 4. Demonstrated Business Workflow

1. A dean logs in and confirms the project and user records.
2. The dean assigns one or more evaluators to a project.
3. The assigned evaluator opens the project dashboard.
4. The evaluator rates ten system criteria from 0 to 10.
5. The application normalizes the criteria total to a 0-100 project score.
6. The evaluator rates five paper chapters from 0 to 20.
7. The application normalizes the chapter total to a 0-100 paper score.
8. The application consolidates each evaluator's latest score into separate panel-average system and paper scores.
9. The engine maps each component score to one official verdict category and evaluates the corresponding rule from the 25 generated verdict combinations.
10. The matching rule's representative score is returned as a separate internal analysis value.
11. When both panel averages exist, the application calculates their unweighted arithmetic mean, rounds it for classification, and stores the official CEIT-T07B verdict.
12. The student views the actual average, rounded classification score, official verdict, recommended next step, and separate fuzzy explanation trace.
13. The dean can open the project report and print or export it.

## 5. Fuzzy Algorithm Used

Let $P$ be the panel-average project score and $T$ be the panel-average paper score. Each component average uses the latest saved score from each evaluator. The official application score is the unweighted arithmetic mean:

$$
FinalScore = \frac{P + T}{2}
$$

The form does not specify weights for these separate application components; equal weighting is an explicit assumption and requires institutional confirmation. Separately, the analysis maps rounded component scores to one of the five CEIT-T07B verdict bands. Membership is crisp and one-hot, not gradual or trapezoidal. The engine generates 25 rules by combining each pair of input verdict categories. The consequent is selected by averaging the categories' representative scores and applying the official classifier. For multiple antecedents, the rule strength is:

$$
\alpha = min(\mu_{ProjectQuality}, \mu_{PaperQuality}, ...)
$$

For each output label, the engine retains the maximum rule strength:

$$
\beta_{label} = max(\alpha_1, \alpha_2, ...)
$$

The output value uses the representative score of the consequent category:

$$
S = \frac{\sum_i center_i\beta_i}{\sum_i\beta_i}
$$

Exactly one rule fires at strength 1 for every valid input pair, so the internal analysis value equals that rule's representative consequent score. It does not determine the official verdict. The official average is retained to two decimal places, then rounded half-up to the nearest whole number and classified through the shared CEIT-T07B verdict function: 95–100 No Revision, 80–94 Minor Revision, 60–79 Major Revision, 50–59 Redefense, and 0–49 Failed.

## 6. Evidence of Transparency

The result trace includes:

- actual official average and rounded classification score
- internal representative-score analysis value
- final output label
- membership degree for each input category
- each fired rule
- each rule's antecedent values
- each rule's firing strength
- each output category strength
- representative score used for the analysis output
- final CEIT-T07B verdict and description

This evidence supports a defense claim that the system does not produce an unexplained score.

## 7. Testing and Verification Plan

### Functional tests

- Login as dean, evaluator, and student.
- Confirm role-specific redirects.
- Assign an evaluator to a project.
- Confirm the evaluator dashboard lists assigned work.
- Submit valid project criteria.
- Submit valid paper chapter scores.
- Confirm the overall result changes after each evaluation.
- Confirm the student can see scores and the fuzzy trace.
- Confirm the dean report shows the same result.

### Fuzzy tests

- Test the official verdict membership at every range boundary.
- Test that exactly one rule fires for every valid score pair.
- Test that the matching rule has full strength and inactive rules have zero strength.
- Test that the generated consequent and representative output use the verdict score bands.
- Test all official CEIT-T07B verdict boundaries, including decimal values.

### Security tests

- Open dean pages while logged out.
- Open evaluator pages using a student session.
- Submit a POST request with an invalid CSRF token.
- Attempt to render HTML through project titles, remarks, and feedback.
- Attempt to access a project identifier that does not belong to the evaluator.

### Deployment checks

- Start Apache and MySQL in XAMPP.
- Import [sql/fuzzy_eval.sql](../sql/fuzzy_eval.sql).
- Confirm the database name is `capstone_fuzzy_eval`.
- Run `composer install` when PDF and PHPUnit dependencies are needed.
- Run PHP lint with `C:\xampp\php\php.exe`.
- Test report generation with Dompdf installed and without Dompdf installed.

## 8. Current Verification Status

The fuzzy test suite passed all 9 tests and 363,756 assertions, including verdict-boundary checks, exhaustive 0–100 half-point score-pair coverage, generated-rule checks, and a regression proving that the official verdict does not use the separate rule-analysis output. A SQLite-backed panel consolidation check verified latest-per-evaluator averaging and pending results when a component is missing. All modified PHP files passed syntax checks with the XAMPP PHP executable.

Recommended Windows commands from the project folder:

```powershell
C:\xampp\php\php.exe -l php\fuzzy_engine.php
C:\xampp\php\php.exe php\vendor\phpunit\phpunit\phpunit --bootstrap php\vendor\autoload.php tests\FuzzyEngineTest.php
```

## 9. Results and Contribution

The implementation contributes the following:

1. A single workflow for dean assignment, evaluator scoring, official verdict classification, and student reporting.
2. Five official verdict categories and a source-controlled generated rule set.
3. A transparent decision trace for academic review.
4. Role separation that supports institutional responsibility boundaries.
5. A reusable PHP engine that can be tested independently of the page templates.

## 10. Limitations to State During Defense

The current version is a working prototype. The following should be stated honestly:

- It is designed for controlled deployment and still needs production hardening.
- The rule analysis uses crisp one-hot categories and representative scores; it is not gradual fuzzy inference.
- The SQL schema would benefit from foreign keys and additional indexes.
- Evaluation history and feedback structures exist, but every workflow path should be audited to ensure each save creates the expected history record.
- Assignment authorization should be enforced directly at the evaluation endpoints as an additional access-control layer.
- Larger deployments should add pagination, stronger audit logging, and operational monitoring.

## 11. Defense Presentation Script

### Opening

"Our project is a web-based BSIT capstone evaluation system that combines structured rubric scoring with CEIT-T07B verdict classification and a separate verdict-based rule analysis. Its purpose is to make evaluation more consistent, transparent, and easier to review."

### Problem

"Manual evaluation can produce inconsistent interpretation. The system applies the official CEIT-T07B score bands consistently and documents the verdict-based rule analysis alongside the authoritative average."

### Demonstration

"The dean first assigns evaluators to a project. The application averages each evaluator's latest system and paper scores per component, then calculates the unweighted mean of those panel averages as the official score. Separately, a crisp rule engine maps component scores to the five official verdict categories and derives an analysis value. The rounded official average—not that analysis value—is classified using CEIT-T07B."

### Transparency explanation

"The student sees the actual official average, its rounded classification score, the CEIT-T07B verdict and next step, plus the separate verdict memberships, matching rule, strength, and representative-score analysis value."

### Closing

"The result is a maintainable prototype with role-based access, fixed verdict-based rules, automated CEIT-T07B verdict classification, and an explanation layer that can support academic evaluation and future institutional refinement."

## 12. Recommended Final Defense Checklist

- Import the SQL database before the demonstration.
- Verify the seeded dean, evaluator, and student accounts.
- Assign the evaluator before opening the evaluator dashboard.
- Demonstrate one project evaluation and one paper evaluation.
- Show the official student verdict and the separate fuzzy analysis trace.
- Show the dean report and print/PDF action.
- Keep the fuzzy rule and membership-function pages available for questions.
- Explain that the official result uses the rounded average, and that the one-hot verdict rules are a separate crisp analysis rather than gradual fuzzy inference.
- State the prototype limitations clearly.
