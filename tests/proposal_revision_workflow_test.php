<?php
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_revisions.php';
require_once __DIR__ . '/../php/submissions.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)');
$pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, student_id INTEGER)');
$pdo->exec('ALTER TABLE projects ADD COLUMN proposal_revision_accepted_submission_id INTEGER');
$pdo->exec('CREATE TABLE student_submissions (id INTEGER PRIMARY KEY, project_id INTEGER, stage TEXT, status TEXT)');
$pdo->exec('CREATE TABLE proposal_revision_cycles (id INTEGER PRIMARY KEY, project_id INTEGER, original_submission_id INTEGER, revision_submission_id INTEGER, revision_number INTEGER, status TEXT, created_at TEXT, completed_at TEXT)');
$pdo->exec('CREATE TABLE proposal_revision_reviews (id INTEGER PRIMARY KEY, revision_cycle_id INTEGER, evaluator_id INTEGER, decision TEXT, comments TEXT, submitted_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO users VALUES (10,'Evaluator A'),(11,'Evaluator B')");
$pdo->exec("INSERT INTO proposal_revision_cycles VALUES (41,5,14,15,1,'pending_panel_review','2026-11-26 12:00:00',NULL)");
// Review IDs deliberately differ from the cycle ID.  The evaluator endpoint
// must update the assigned review row, never the cycle row identifier.
$pdo->exec("INSERT INTO proposal_revision_reviews VALUES (71,41,10,'pending',NULL,NULL,'2026-11-26 12:00:00','2026-11-26 12:00:00'),(72,41,11,'pending',NULL,NULL,'2026-11-26 12:00:00','2026-11-26 12:00:00')");
$pdo->exec("UPDATE proposal_revision_reviews SET decision='accepted',comments='Addressed',submitted_at='2026-11-26 12:01:00' WHERE id=71 AND revision_cycle_id=41 AND evaluator_id=10 AND decision='pending'");
$pending = proposal_revision_refresh_status($pdo, 41);
if ($pending['status'] !== 'pending_panel_review' || $pending['pending_count'] !== 1) throw new RuntimeException('A partial panel must remain pending.');
$pdo->exec("UPDATE proposal_revision_reviews SET decision='accepted',comments='Addressed',submitted_at='2026-11-26 12:02:00' WHERE id=72 AND revision_cycle_id=41 AND evaluator_id=11 AND decision='pending'");
$accepted = proposal_revision_refresh_status($pdo, 41);
if ($accepted['status'] !== 'accepted') throw new RuntimeException('All accepted panel reviews must accept the revision.');
if (!proposal_revision_is_fully_accepted(['status' => 'accepted'], $accepted)) throw new RuntimeException('Every original reviewer accepting must complete the revision stage.');
$acceptedProject = [
    'proposal_confirmed_at' => '2026-11-27 09:00:00',
    'proposal_official_verdict' => 'PASSED/APPROVED (Major Revision)',
    'proposal_required_revisions' => 'Address the panel feedback.',
    'proposal_revision_accepted_submission_id' => 15,
];
if (!proposal_allows_final_submission($acceptedProject, ['id' => 15])) throw new RuntimeException('An accepted original-panel revision must unlock Final Defense without another Chairperson acceptance.');
if (proposal_allows_final_submission($acceptedProject, ['id' => 14])) throw new RuntimeException('Final Defense must bind to the accepted revision submission, not the original proposal.');
$pdo->exec("INSERT INTO projects (id,student_id) VALUES (6,100)");
$pdo->exec("INSERT INTO student_submissions VALUES (15,6,'proposal','submitted')");
$pdo->exec("INSERT INTO proposal_revision_cycles VALUES (42,6,14,15,1,'accepted','2026-11-26 12:00:00','2026-11-26 12:02:00')");
$pdo->exec("INSERT INTO proposal_revision_reviews VALUES (73,42,10,'accepted','Addressed','2026-11-26 12:01:00','2026-11-26 12:00:00','2026-11-26 12:01:00'),(74,42,11,'accepted','Addressed','2026-11-26 12:02:00','2026-11-26 12:00:00','2026-11-26 12:02:00')");
if (!proposal_revision_sync_accepted_submission($pdo, 6, 15)) throw new RuntimeException('A fully accepted historical revision must synchronize its authoritative submission pointer.');
if ((int) $pdo->query('SELECT proposal_revision_accepted_submission_id FROM projects WHERE id=6')->fetchColumn() !== 15) throw new RuntimeException('Accepted revision synchronization must persist the project pointer.');
if ($pdo->query('SELECT status FROM student_submissions WHERE id=15')->fetchColumn() !== 'accepted') throw new RuntimeException('Accepted revision synchronization must persist the accepted submission status.');
$pdo->exec("UPDATE proposal_revision_reviews SET decision='returned' WHERE id=71");
$pdo->exec("UPDATE proposal_revision_reviews SET decision='pending',comments=NULL,submitted_at=NULL WHERE id=72");
$returned = proposal_revision_refresh_status($pdo, 41);
if ($returned['status'] !== 'pending_panel_review' || $returned['pending_count'] !== 1) throw new RuntimeException('A return must not finalize the revision while another panel review is pending.');
$pdo->exec("UPDATE proposal_revision_reviews SET decision='accepted',comments='Addressed',submitted_at='2026-11-26 12:03:00' WHERE id=72");
$returnedComplete = proposal_revision_refresh_status($pdo, 41);
if ($returnedComplete['status'] !== 'returned' || $returnedComplete['pending_count'] !== 0) throw new RuntimeException('A replacement revision may begin only after the returned panel cycle is complete.');
if (proposal_revision_is_fully_accepted(['status' => 'returned'], $returnedComplete)) throw new RuntimeException('A returned revision must never unlock Final Defense.');
$access = $pdo->prepare('SELECT c.id FROM proposal_revision_cycles c INNER JOIN proposal_revision_reviews r ON r.revision_cycle_id=c.id WHERE c.id=? AND r.evaluator_id=?');
$access->execute([41, 12]);
if ($access->fetchColumn()) throw new RuntimeException('An evaluator outside the original panel must not access the review.');
$pdo->exec("INSERT INTO proposal_revision_cycles VALUES (2,5,14,16,2,'pending_panel_review','2026-11-26 13:00:00',NULL)");
$pdo->exec("INSERT INTO proposal_revision_reviews VALUES (3,2,10,'pending',NULL,NULL,'2026-11-26 13:00:00','2026-11-26 13:00:00'),(4,2,11,'pending',NULL,NULL,'2026-11-26 13:00:00','2026-11-26 13:00:00')");
$resubmitted = proposal_revision_review_summary($pdo, 2);
if ($resubmitted['pending_count'] !== 2 || $resubmitted['assigned_count'] !== 2) throw new RuntimeException('A returned proposal must create a new pending review cycle for the same panel.');
if (proposal_revision_upload_state(true, true, null, null) !== 'allowed') throw new RuntimeException('A major revision must allow the first revised upload.');
if (proposal_revision_upload_state(true, true, ['status' => 'returned'], ['pending_count' => 0]) !== 'allowed') throw new RuntimeException('A completed returned review must allow another revision.');
if (proposal_revision_upload_state(true, true, ['status' => 'pending_panel_review'], ['pending_count' => 1]) !== 'pending') throw new RuntimeException('A pending panel review must block another upload.');
if (proposal_revision_upload_state(true, true, ['status' => 'accepted'], ['pending_count' => 0]) !== 'accepted') throw new RuntimeException('An accepted revision must block further proposal uploads.');
if (proposal_revision_upload_state(true, false, null, null) !== 'schema_required') throw new RuntimeException('A major revision must not fall through to the generic submission lock when the revision schema is unavailable.');
if (proposal_revision_upload_state(false, true, null, null) !== 'not_required') throw new RuntimeException('An original proposal without a major-revision result must retain the normal submission lock rules.');
$pdo->exec('INSERT INTO projects (id,student_id) VALUES (5,100)');
$ownership = $pdo->prepare('SELECT id FROM projects WHERE id=? AND student_id=?');
$ownership->execute([5, 101]);
if ($ownership->fetchColumn()) throw new RuntimeException('A different student must not be able to submit against another student project.');
echo "proposal_revision_workflow: ok\n";
