<?php
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();
$tables = $db->query("SHOW TABLES LIKE '%fee%'")->fetchAll(PDO::FETCH_COLUMN);
echo "Fee tables:\n";
print_r($tables);

foreach ($tables as $tbl) {
    echo "\nColumns in $tbl:\n";
    $cols = $db->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
}

// Check FeeController
echo "\nFeeController exists: " . (file_exists('controllers/FeeController.php') ? 'YES' : 'NO') . "\n";
