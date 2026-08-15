<?php
// Import data.sql safely (string-aware statement splitter)
$sqlFile = "/workspace/public/install/data.sql";
if (!file_exists($sqlFile)) { echo "✗ data.sql missing!\n"; exit(1); }
$sql = file_get_contents($sqlFile);
$c = new PDO("mysql:host=127.0.0.1;port=3306;dbname=xinyue_search;charset=utf8mb4", "xinyue", "xinyue_pass_2026", [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$c->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$c->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

$lines = explode("\n", $sql);
$current = "";
$inString = false;
$stringCh = "";
$total = 0;
$errors = 0;
$skippedMessages = [];

$flush = function() use ($c, &$total, &$errors, &$skippedMessages, &$current) {
    $stmt = trim($current);
    $current = "";
    if (!$stmt) return;
    if (preg_match('/^\s*(\/\*.*?\*\/|--|#|SET\b)/si', $stmt)) return;
    try {
        $c->exec($stmt);
        $total++;
    } catch (PDOException $e) {
        $errors++;
        if ($errors <= 8) $skippedMessages[] = "  WARN skip: " . mb_substr($e->getMessage(), 0, 130);
    }
};

foreach ($lines as $line) {
    $trimmed = ltrim($line);
    if ($trimmed !== "" && (
        $trimmed[0] === "#" ||
        (isset($trimmed[0], $trimmed[1], $trimmed[2]) && $trimmed[0] === "-" && $trimmed[1] === "-" && ctype_space($trimmed[2]))
    )) {
        continue;
    }
    $n = strlen($line);
    for ($i = 0; $i < $n; $i++) {
        $ch = $line[$i];
        if (!$inString && ($ch === "'" || $ch === '"')) {
            $inString = true;
            $stringCh = $ch;
            $current .= $ch;
        } elseif ($inString) {
            $current .= $ch;
            if ($ch === "\\" && $i < $n - 1) { $current .= $line[$i+1]; $i++; continue; }
            if ($ch === $stringCh) { $inString = false; }
        } elseif ($ch === ";") {
            $current .= $ch;
            $flush();
        } else {
            $current .= $ch;
        }
    }
    $current .= "\n";
}
if (trim($current)) $flush();

echo "✓ Executed $total statements ($errors skipped)\n";
foreach ($skippedMessages as $m) echo "$m\n";

echo "\n=== Tables imported ===\n";
$tables = $c->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
$totalRows = 0;
foreach ($tables as $t) {
    $cnt = $c->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $totalRows += $cnt;
    printf("  - %-25s %d rows\n", $t, $cnt);
}
echo "\nTotal: ".count($tables)." tables, $totalRows rows\n";
