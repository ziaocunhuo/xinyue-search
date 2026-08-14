<?php
/**
 * Convert MySQL install SQL -> SQLite-compatible SQL, then import into SQLite.
 * Handles: ENGINE=InnoDB, CHARSET=utf8mb4, AUTO_INCREMENT, `backticks`, TINYINT -> INTEGER,
 *          INT(xx) -> INTEGER, DECIMAL(a,b) -> REAL, KEY definitions -> CREATE INDEX.
 */
set_time_limit(0);

$mysqlFile = __DIR__ . '/../public/install/data.sql';
$sqliteFile = __DIR__ . '/../data/xinyue_search.sqlite';

if (!file_exists($mysqlFile)) {
    fwrite(STDERR, "ERROR: data.sql not found at $mysqlFile\n");
    exit(1);
}

echo "Loading MySQL schema SQL...\n";
$sql = file_get_contents($mysqlFile);

// --- Preprocess ---
// 1. Strip comments
$sql = preg_replace('!/\*.*?\*/!s', '', $sql);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$sql = preg_replace('/^\s*#.*$/m', '', $sql);

// 2. Normalize backticks -> double quotes (SQLite accepts both)
$sql = preg_replace('/`([^`]+)`/', '"$1"', $sql);

// 3. Split into statements
$rawStatements = array_filter(array_map('trim', explode(";\n", $sql)));

$converted = [];
$createIndexes = [];  // deferred CREATE INDEX statements
$insertCount = 0;
$tableCount = 0;

foreach ($rawStatements as $idx => $stmt) {
    if (!$stmt) continue;
    if (preg_match('/^\s*SET\b/i', $stmt)) continue;        // SET NAMES etc.
    if (preg_match('/^\s*DROP\s+TABLE\s+IF\s+EXISTS\b/i', $stmt)) {
        $converted[] = preg_replace('/\s*$/i', '', $stmt) . ';';
        continue;
    }
    if (preg_match('/^\s*CREATE\s+TABLE\b/i', $stmt)) {
        $tableCount++;
        // Parse table name
        if (!preg_match('/CREATE\s+TABLE\s+"?([^"\s(]+)"?\s*\(/i', $stmt, $tm)) {
            echo "  WARN: can't parse table name, skipping\n";
            continue;
        }
        $tableName = $tm[1];
        echo "  Processing CREATE TABLE: $tableName\n";

        // Extract columns block: everything between first ( and last )
        $open = strpos($stmt, '(');
        $close = strrpos($stmt, ')');
        if ($open === false || $close === false) continue;
        $colsBlock = substr($stmt, $open + 1, $close - $open - 1);

        // Split columns by top-level commas (respect parens)
        $colDefs = [];
        $depth = 0;
        $buf = '';
        for ($i = 0, $len = strlen($colsBlock); $i < $len; $i++) {
            $ch = $colsBlock[$i];
            if ($ch === '(') $depth++;
            elseif ($ch === ')') $depth--;
            elseif ($ch === ',' && $depth === 0) {
                $colDefs[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf)) $colDefs[] = trim($buf);

        $newCols = [];
        $pkFound = false;
        foreach ($colDefs as $cd) {
            $origCd = $cd;
            // KEY / INDEX lines: convert to deferred CREATE INDEX
            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX)\s+["\(]?(\w+)?/i', $cd, $km)) {
                $type = strtoupper($km[1]);
                $idxName = $km[2] ?? null;
                // Extract columns list from inside the outermost parens
                if (preg_match('/\(([^()]*(?:\([^()]*\)[^()]*)*)\)\s*$/', $cd, $cm)) {
                    $colsList = '"' . implode('","', array_map(function($c){ return trim(trim($c), '"` '); }, explode(',', $cm[1]))) . '"';
                    if ($type === 'PRIMARY KEY') {
                        $pkFound = true;
                        $newCols[] = "PRIMARY KEY ($colsList)";
                    } else {
                        $unique = (strpos($type, 'UNIQUE') === 0) ? 'UNIQUE ' : '';
                        $name = $idxName ? ($tableName . '_' . $idxName) : ($tableName . '_' . md5($colsList));
                        $createIndexes[] = "CREATE {$unique}INDEX IF NOT EXISTS \"{$name}\" ON \"{$tableName}\" ({$colsList});";
                    }
                }
                continue;
            }
            // FULLTEXT / SPATIAL: skip (SQLite doesn't support)
            if (preg_match('/^(FULLTEXT|SPATIAL)\s+/i', $cd)) continue;
            // CONSTRAINT FK: skip (SQLite supports FKs but converting MySQL's is error-prone, rely on app-level)
            if (preg_match('/^CONSTRAINT\s+/i', $cd)) continue;

            // Transform column type
            $cd = preg_replace_callback('/^\s*"?(\w+)"?\s+(\w+)(?:\(([^)]*)\))?\s*/i', function($m) use (&$pkFound) {
                $colName = $m[1];
                $type = strtoupper($m[2]);
                $params = $m[3] ?? '';
                // Map MySQL types -> SQLite storage classes
                switch ($type) {
                    case 'TINYINT': case 'SMALLINT': case 'MEDIUMINT':
                    case 'INT': case 'INTEGER': case 'BIGINT':
                        $sqliteType = 'INTEGER';
                        break;
                    case 'DECIMAL': case 'FLOAT': case 'DOUBLE': case 'REAL':
                        $sqliteType = 'REAL';
                        break;
                    case 'CHAR': case 'VARCHAR': case 'TINYTEXT': case 'TEXT':
                    case 'MEDIUMTEXT': case 'LONGTEXT': case 'ENUM': case 'SET':
                        $sqliteType = 'TEXT';
                        break;
                    case 'BLOB': case 'TINYBLOB': case 'MEDIUMBLOB': case 'LONGBLOB':
                    case 'BINARY': case 'VARBINARY':
                        $sqliteType = 'BLOB';
                        break;
                    case 'DATE': case 'TIME': case 'DATETIME': case 'TIMESTAMP': case 'YEAR':
                        $sqliteType = 'TEXT';
                        break;
                    case 'JSON': case 'BOOL': case 'BOOLEAN':
                        $sqliteType = 'TEXT';
                        break;
                    default:
                        $sqliteType = 'TEXT';
                }
                // Detect AUTO_INCREMENT
                return "\"{$colName}\" {$sqliteType} ";
            }, $cd, 1);

            // Replace AUTO_INCREMENT (after type, before anything) with AUTOINCREMENT
            // SQLite: must be INTEGER PRIMARY KEY to use AUTOINCREMENT
            $hasAutoInc = (stripos($cd, 'AUTO_INCREMENT') !== false);
            if ($hasAutoInc) {
                // Strip old AUTO_INCREMENT
                $cd = preg_replace('/\bAUTO_INCREMENT\b/i', '', $cd);
                // Append PRIMARY KEY AUTOINCREMENT if not already declared
                if (stripos($cd, 'PRIMARY KEY') === false && !$pkFound) {
                    $cd = preg_replace('/\s*(DEFAULT|NOT NULL|NULL|UNSIGNED|COMMENT|CHARACTER|COLLATE|ON\s+UPDATE|,?\s*$)/i', ' INTEGER PRIMARY KEY AUTOINCREMENT $1', $cd, 1);
                    $pkFound = true;
                }
            }
            // Strip UNSIGNED
            $cd = preg_replace('/\bUNSIGNED\b/i', '', $cd);
            // Strip ZEROFILL
            $cd = preg_replace('/\bZEROFILL\b/i', '', $cd);
            // Strip CHARACTER SET ... / COLLATE ...
            $cd = preg_replace('/\bCHARACTER\s+SET\s+\w+/i', '', $cd);
            $cd = preg_replace('/\bCOLLATE\s+\w+/i', '', $cd);
            // Strip COMMENT '...'
            $cd = preg_replace("/\bCOMMENT\s+'[^']*'/i", '', $cd);
            // Strip ON UPDATE ...
            $cd = preg_replace('/\bON\s+UPDATE\s+\S+/i', '', $cd);
            // Strip ROW_FORMAT, ENGINE, etc. at the end of whole CREATE (handled later)
            $newCols[] = rtrim(trim($cd), ',');
        }

        // Strip table-level options after closing paren: ENGINE, DEFAULT CHARSET, etc.
        $tableOpts = '';  // we ignore

        $createSql = "CREATE TABLE IF NOT EXISTS \"{$tableName}\" (\n    " . implode(",\n    ", $newCols) . "\n);";
        $converted[] = $createSql;
        continue;
    }

    if (preg_match('/^\s*INSERT\s+INTO\b/i', $stmt)) {
        $insertCount++;
        // Already mostly compatible; backticks converted. Just ensure `\N` -> NULL for SQL
        $stmt = preg_replace('/(?<!\\\\)\\\\N/', 'NULL', $stmt);
        $converted[] = $stmt . ';';
        continue;
    }

    // Unknown statement: keep as-is (may or may not work)
    $converted[] = rtrim($stmt, ';') . ';';
}

// Build final SQL
$finalSql = "PRAGMA journal_mode = WAL;\nPRAGMA foreign_keys = OFF;\nBEGIN TRANSACTION;\n"
    . implode("\n\n", $converted) . "\n"
    . implode("\n", $createIndexes) . "\n"
    . "COMMIT;\n";

// Ensure data dir
$dataDir = dirname($sqliteFile);
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
if (file_exists($sqliteFile)) @unlink($sqliteFile);

echo "Creating SQLite database at: $sqliteFile\n";
$pdo = new PDO('sqlite:' . $sqliteFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("PRAGMA journal_mode = WAL;");
$pdo->exec("PRAGMA synchronous = NORMAL;");

// Execute one-by-one (batch exec is fine for smaller)
echo "Importing schema and data...\n";
try {
    $pdo->exec($finalSql);
} catch (PDOException $e) {
    // Fallback: execute statement by statement
    echo "  Batch exec failed: " . $e->getMessage() . "\n  Falling back to per-statement execution...\n";
    $statements = explode(";\n", $finalSql);
    $errs = 0;
    foreach ($statements as $s) {
        $s = trim($s);
        if (!$s) continue;
        try {
            $pdo->exec($s);
        } catch (PDOException $pe) {
            $errs++;
            if ($errs < 10) echo "    WARN (skip): " . mb_substr($pe->getMessage(), 0, 120) . "\n";
        }
    }
    echo "  Skipped statement errors: $errs\n";
}

// Show summary
echo "\n=== Import Summary ===\n";
echo "Tables declared in SQL: $tableCount\n";
echo "INSERT statements: $insertCount\n";
echo "Extra indexes: " . count($createIndexes) . "\n\n";
$stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Tables created: " . count($tables) . "\n";
foreach ($tables as $t) {
    $c = $pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
    echo "  - $t: $c rows\n";
}
$pdo = null;
echo "\nDB size: " . round(filesize($sqliteFile)/1024, 1) . " KB\nDONE.\n";
