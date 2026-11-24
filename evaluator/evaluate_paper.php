<?php
$projectId = (int) ($_GET['project_id'] ?? 0);
header('Location: ' . ($projectId > 0 ? 'evaluate_selection.php?project_id=' . $projectId : 'projects.php'));
exit;
