<?php
// This is deliberately a thin, read-only route. The evaluation page performs
// the assignment, submission, and ownership checks before rendering anything.
$e3SubmittedView = true;
require __DIR__ . '/evaluate_e3.php';
