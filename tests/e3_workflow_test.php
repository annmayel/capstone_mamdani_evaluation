<?php

require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/e3.php';

$partial = e3_validate_scores(['fr1_1' => '5'], false);
if ($partial['errors'] !== [] || $partial['scores']['fr1_1'] !== 5.0 || $partial['scores']['arc2_1'] !== null) {
    throw new RuntimeException('An E3 draft must allow incomplete criteria while retaining entered scores.');
}

$required = e3_validate_scores(['fr1_1' => '5']);
if (!$required['errors']) {
    throw new RuntimeException('E3 submission must require every official criterion.');
}

$complete = [];
foreach (e3_criteria() as $criterion) {
    $complete[$criterion['key']] = (string) $criterion['maximum'];
}
$submitted = e3_validate_scores($complete);
if ($submitted['errors'] !== [] || $submitted['total'] !== 100.0) {
    throw new RuntimeException('The official E3 maximum scores must total 100.');
}

echo "e3_workflow: ok\n";
