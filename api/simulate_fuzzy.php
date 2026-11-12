<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');

if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'dean') {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$scores = [];
foreach (['project', 'paper'] as $field) {
    $value = $input[$field] ?? null;
    if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 100) {
        http_response_code(422);
        echo json_encode(['error' => ucfirst($field) . ' score must be a finite number from 0 to 100.']);
        exit;
    }
    $scores[$field] = (float) $value;
}

$trace = build_fuzzy_trace($scores['project'], $scores['paper']);
$fires = array_map(static function ($rule) {
    return [
        'rule_id' => $rule['id'],
        'text' => $rule['rule_text'],
        'strength' => $rule['strength'],
        'consequent' => $rule['consequent'],
        'antecedents' => $rule['antecedents'],
    ];
}, $trace['firedRules']);
echo json_encode([
    'project' => $scores['project'],
    'paper' => $scores['paper'],
    'average_score' => $trace['final_score'],
    'rounded_score' => $trace['rounded_score'],
    'degrees' => $trace['inputMemberships'],
    'fires' => $fires,
    'aggregated' => $trace['outputMemberships'],
    'defuzz' => $trace['defuzz'],
    'rating' => $trace['rating'],
    'rating_description' => $trace['rating_description'],
    'next_step' => $trace['next_step'],
]);
