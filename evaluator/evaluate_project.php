<?php
$projectId = (int) ($_GET['project_id'] ?? 0);
header('Location: evaluate_e3.php' . ($projectId > 0 ? '?project_id=' . $projectId : ''));
exit;
