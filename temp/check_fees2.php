<?php
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();

// Check fees_enabled for all schools
$rows = $db->query("SELECT id, school_name, fees_enabled FROM school_info")->fetchAll(PDO::FETCH_ASSOC);
echo "Schools:\n";
foreach ($rows as $r) {
    echo "  ID={$r['id']} | {$r['school_name']} | fees_enabled=" . var_export($r['fees_enabled'], true) . "\n";
}

// Check if fees_enabled column exists
$cols = $db->query("SHOW COLUMNS FROM school_info LIKE 'fees_enabled'")->fetchAll(PDO::FETCH_ASSOC);
echo "\nfees_enabled column: " . ($cols ? "EXISTS ({$cols[0]['Type']}, default={$cols[0]['Default']})" : "MISSING") . "\n";
