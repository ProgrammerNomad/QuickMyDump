<?php
/**
 * QuickMyDump - modern large-database dumper for PHP 8+
 *
 * Features:
 *  - Uses PDO (preferred) with fallback to mysqli
 *  - Streams output (memory efficient)
 *  - gzip support
 *  - include/exclude tables
 *  - export schema (tables, views), data, triggers, routines
 *  - CLI and minimal web UI
 *
 * Usage (CLI):
 *   php QuickMyDump.php --host=127.0.0.1 --port=3306 --user=root --pass=secret --db=mydb \
 *       --outfile=mydb.sql.gz --gzip --exclude=cache_,tmp_ --extended-insert --max-rows=1000
 *
 * Example (web):
 *   Place file in webroot and open in browser. Fill form and click "Dump".
 *
 * Notes:
 *  - Run from CLI for big DBs.
 *  - Ensure PDO_MYSQL or mysqli extension is enabled.
 */

declare(strict_types=1);

ini_set('memory_limit', '-1');
set_time_limit(0);

/* ------------------------
   Simple argument parser
   ------------------------ */
function parse_args(array $argv): array {
    $args = [];
    foreach ($argv as $a) {
        if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) {
            $args[$m[1]] = $m[2];
        } elseif (preg_match('/^--(.+)$/', $a, $m)) {
            $args[$m[1]] = true;
        }
    }
    return $args;
}

/* ------------------------
   Defaults
   ------------------------ */
$defaults = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'socket' => '',
    'user' => 'root',
    'pass' => '',
    'db'   => '',
    'outfile' => '',    // default stdout
    'gzip' => false,    // gzip output
    'extended-insert' => true,
    'max-rows' => 1000, // rows per chunk fetch
    'exclude' => '',    // comma separated patterns (prefix allowed)
    'tables' => '',     // comma separated table list (if you want specific)
    'routines' => true,
    'triggers' => true,
    'views' => true,
    'drop-table' => true,
];

if (php_sapi_name() === 'cli') {
    $args = parse_args($argv);
    // fill defaults
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $args)) $args[$k] = $v;
    }
} else {
    // Web form defaults + simple UI
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $args = array_merge($defaults, array_intersect_key($_POST, $defaults));
        // boolean checkboxes
        foreach (['gzip','routines','triggers','views','drop-table','extended-insert'] as $b) {
            $args[$b] = isset($_POST[$b]) ? ($_POST[$b] === '1' || $_POST[$b] === 'on') : $defaults[$b];
        }
    } else {
        // show form
        echo web_form();
        exit;
    }
}

if (empty($args['db'])) {
    fwrite(STDERR, "Error: --db parameter required\n");
    if (php_sapi_name() === 'cli') exit(1);
}

/* ------------------------
   Output stream handling
   ------------------------ */
$outfile = $args['outfile'] ?: '';
$useGzip = filter_var($args['gzip'], FILTER_VALIDATE_BOOLEAN);

$outStream = null;
if ($outfile !== '') {
    if ($useGzip) {
        $gz = gzopen($outfile, 'wb9');
        if (!$gz) { fwrite(STDERR, "Cannot open {$outfile} for writing\n"); exit(1); }
        $outStream = $gz; // use gz functions
        $writeFn = function($s) use ($outStream) { gzwrite($outStream, $s); };
        $closeFn = function() use ($outStream) { gzclose($outStream); };
    } else {
        $fp = fopen($outfile, 'wb');
        if (!$fp) { fwrite(STDERR, "Cannot open {$outfile} for writing\n"); exit(1); }
        $outStream = $fp;
        $writeFn = function($s) use ($outStream) { fwrite($outStream, $s); };
        $closeFn = function() use ($outStream) { fclose($outStream); };
    }
} else {
    // stdout
    $outStream = STDOUT;
    $writeFn = function($s) use ($outStream) { fwrite($outStream, $s); };
    $closeFn = function() {};
}

/* ------------------------
   DB connection (PDO preferred)
   ------------------------ */
$dsnParts = [];
$dsnParts[] = "mysql:host={$args['host']}";
if (!empty($args['port'])) $dsnParts[] = "port={$args['port']}";
if (!empty($args['socket'])) $dsnParts[] = "unix_socket={$args['socket']}";
$dsn = implode(';', $dsnParts) . ";charset=utf8mb4";

$pdo = null;
$mysqli = null;
try {
    $pdo = new PDO($dsn, $args['user'], $args['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    // select DB
    $pdo->exec("USE `".str_replace('`','``',$args['db'])."`");
} catch (Throwable $e) {
    // try mysqli as fallback
    try {
        $mysqli = mysqli_init();
        if (!empty($args['socket'])) {
            mysqli_real_connect($mysqli, $args['host'], $args['user'], $args['pass'], $args['db'], (int)$args['port'], $args['socket']);
        } else {
            mysqli_real_connect($mysqli, $args['host'], $args['user'], $args['pass'], $args['db'], (int)$args['port']);
        }
        if (mysqli_connect_errno()) {
            throw new RuntimeException("MySQL connect failed: " . mysqli_connect_error());
        }
        mysqli_set_charset($mysqli, 'utf8mb4');
    } catch (Throwable $e2) {
        fwrite(STDERR, "DB Connection failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

/* ------------------------
   Helpers
   ------------------------ */
function esc_ident(string $s): string {
    return '`' . str_replace('`', '``', $s) . '`';
}

function escape_sql_value($v): string {
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    // Use addslashes (works) - prefer PDO::quote if available in real usage
    return '\'' . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . '\'';
}

function write_line(string $s) {
    global $writeFn;
    $writeFn($s . PHP_EOL);
}

/* ------------------------
   Header
   ------------------------ */
write_line('-- QuickMyDump SQL dump');
write_line('-- Generated: ' . (new DateTime())->format(DateTime::ATOM));
write_line('-- Server version: placeholder (runtime)');
write_line('SET NAMES utf8mb4;');
write_line('SET time_zone = "+00:00";');
write_line('SET sql_mode = "NO_AUTO_VALUE_ON_ZERO";');
write_line('SET FOREIGN_KEY_CHECKS = 0;');
write_line('');

/* ------------------------
   Table list
   ------------------------ */
$includeTables = array_filter(array_map('trim', explode(',', $args['tables'] ?: '')));
$excludePatterns = array_filter(array_map('trim', explode(',', $args['exclude'] ?: '')));

function should_exclude(string $table, array $includeTables, array $excludePatterns): bool {
    if (!empty($includeTables)) {
        return !in_array($table, $includeTables, true);
    }
    if (empty($excludePatterns)) return false;
    foreach ($excludePatterns as $p) {
        if ($p === '') continue;
        // prefix match
        if (str_ends_with($p, '%')) {
            $pref = substr($p, 0, -1);
            if (str_starts_with($table, $pref)) return true;
        } elseif (str_starts_with($p, '%')) {
            $suf = substr($p, 1);
            if (str_ends_with($table, $suf)) return true;
        } elseif (strpos($p, '*') !== false) {
            $regex = '/^' . str_replace('\*','.*',preg_quote($p, '/')) . '$/i';
            if (preg_match($regex, $table)) return true;
        } else {
            // simple prefix
            if (str_starts_with($table, $p)) return true;
        }
    }
    return false;
}

$tables = [];
if ($pdo) {
    $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type IN ('BASE TABLE','VIEW')");
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $r[0];
    }
} else {
    $res = mysqli_query($mysqli, "SHOW FULL TABLES WHERE Table_type IN ('BASE TABLE','VIEW')");
    while ($r = mysqli_fetch_row($res)) {
        $tables[] = $r[0];
    }
}

// filter
$tables = array_values(array_filter($tables, function($t) use($includeTables,$excludePatterns){
    return !should_exclude($t, $includeTables, $excludePatterns);
}));

/* ------------------------
   Export schema and data
   ------------------------ */
foreach ($tables as $table) {
    // dump CREATE statement
    $isView = false;
    if ($pdo) {
        $r = $pdo->query("SHOW FULL TABLES WHERE Table_type='VIEW' AND `Tables_in_".str_replace('`','', $GLOBALS['args']['db'])."` = " . $pdo->quote($table))->fetch();
        if ($r) $isView = true;
    } else {
        $res = mysqli_query($mysqli, "SHOW FULL TABLES WHERE Table_type='VIEW' AND `Tables_in_".mysqli_real_escape_string($mysqli, $GLOBALS['args']['db'])."` = '" . mysqli_real_escape_string($mysqli, $table) . "'");
        if ($res && mysqli_num_rows($res)) $isView = true;
    }

    write_line('');
    write_line('-- --------------------------------------------------');
    write_line('-- Table structure for ' . esc_ident($table));
    write_line('-- --------------------------------------------------');

    if ($args['drop-table']) {
        write_line('DROP TABLE IF EXISTS ' . esc_ident($table) . ';');
    }

    // Get CREATE statement
    $createSql = '';
    if ($pdo) {
        $st = $pdo->query("SHOW CREATE TABLE " . esc_ident($table));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // SHOW CREATE TABLE returns array keys vary: 'Table' and 'Create Table' or 'Create View'
            $c = null;
            foreach ($row as $k => $v) {
                if (stripos($k, 'create') !== false) { $c = $v; break; }
            }
            $createSql = $c ?: '';
        }
    } else {
        $res = mysqli_query($mysqli, "SHOW CREATE TABLE " . esc_ident($table));
        if ($res) {
            $row = mysqli_fetch_assoc($res);
            foreach ($row as $k => $v) {
                if (stripos($k, 'create') !== false) { $createSql = $v; break; }
            }
        }
    }

    if ($createSql === '') {
        // For views, use SHOW CREATE VIEW
        if ($args['views']) {
            if ($pdo) {
                $st = $pdo->query("SHOW CREATE VIEW " . esc_ident($table));
                $row = $st->fetch(PDO::FETCH_ASSOC);
                foreach ($row as $k => $v) {
                    if (stripos($k, 'create') !== false) { $createSql = $v; break; }
                }
            } else {
                $res = mysqli_query($mysqli, "SHOW CREATE VIEW " . esc_ident($table));
                if ($res) {
                    $row = mysqli_fetch_assoc($res);
                    foreach ($row as $k => $v) {
                        if (stripos($k, 'create') !== false) { $createSql = $v; break; }
                    }
                }
            }
        }
    }

    if ($createSql !== '') {
        write_line($createSql . ';');
    } else {
        write_line('-- Could not get CREATE statement for ' . esc_ident($table));
    }

    // If it's a view, skip data export
    if ($isView || !$args['max-rows'] || (int)$args['max-rows'] <= 0) {
        continue;
    }

    // Dump data in chunks
    write_line('');
    write_line('-- Dumping data for ' . esc_ident($table));
    $rowCount = 0;
    $offset = 0;
    $chunk = (int)$args['max-rows'];
    $columns = [];

    // get column names
    if ($pdo) {
        $st = $pdo->query("SELECT * FROM " . esc_ident($table) . " LIMIT 1");
        $columns = array_keys($st->fetch(PDO::FETCH_ASSOC) ?: []);
    } else {
        $res = mysqli_query($mysqli, "SELECT * FROM " . esc_ident($table) . " LIMIT 1");
        $row = mysqli_fetch_assoc($res);
        $columns = $row ? array_keys($row) : [];
    }
    $colList = implode(', ', array_map('esc_ident', $columns));

    $extended = filter_var($args['extended-insert'], FILTER_VALIDATE_BOOLEAN);
    if ($extended) {
        // we'll build multi-row INSERTs
    }

    // streaming select
    while (true) {
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT * FROM " . esc_ident($table) . " LIMIT :off, :lim");
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $chunk, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        } else {
            $res = mysqli_query($mysqli, "SELECT * FROM " . esc_ident($table) . " LIMIT {$offset}, {$chunk}");
            $rows = [];
            while ($r = mysqli_fetch_row($res)) $rows[] = $r;
        }

        if (!$rows || count($rows) === 0) break;

        $rowCount += count($rows);

        if ($extended) {
            // build multi-row insert
            $insertHeader = 'INSERT INTO ' . esc_ident($table) . ' (' . $colList . ') VALUES ';
            $valuesParts = [];
            foreach ($rows as $row) {
                $escaped = array_map(function($v){
                    if ($v === null) return 'NULL';
                    // binary safe: base64? better to escape
                    // simple escape:
                    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
                }, $row);
                $valuesParts[] = '(' . implode(', ', $escaped) . ')';
            }
            write_line($insertHeader . PHP_EOL . implode(",\n", $valuesParts) . ';');
        } else {
            // single inserts
            foreach ($rows as $row) {
                $escaped = array_map(function($v){
                    if ($v === null) return 'NULL';
                    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
                }, $row);
                write_line('INSERT INTO ' . esc_ident($table) . ' (' . $colList . ') VALUES (' . implode(', ', $escaped) . ');');
            }
        }

        $offset += $chunk;
        // progress output to STDERR (CLI)
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, "Dumped {$rowCount} rows from {$table}\r");
        }
    }

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, PHP_EOL);
    }
}

/* ------------------------
   Routines and triggers
   ------------------------ */
if (filter_var($args['routines'], FILTER_VALIDATE_BOOLEAN) && $pdo) {
    // routines (procedures + functions)
    try {
        $st = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = " . $pdo->quote($args['db']));
        $procs = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($procs as $p) {
            $name = $p['Name'];
            $row = $pdo->query("SHOW CREATE PROCEDURE " . esc_ident($name))->fetch(PDO::FETCH_ASSOC);
            foreach ($row as $k => $v) {
                if (stripos($k, 'create') !== false) {
                    write_line($v . ';');
                    break;
                }
            }
        }
        // functions
        $st = $pdo->query("SHOW FUNCTION STATUS WHERE Db = " . $pdo->quote($args['db']));
        $funcs = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($funcs as $f) {
            $name = $f['Name'];
            $row = $pdo->query("SHOW CREATE FUNCTION " . esc_ident($name))->fetch(PDO::FETCH_ASSOC);
            foreach ($row as $k => $v) {
                if (stripos($k, 'create') !== false) {
                    write_line($v . ';');
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        // ignore if permissions lacking
        write_line('-- Could not dump routines: ' . $e->getMessage());
    }
}

if (filter_var($args['triggers'], FILTER_VALIDATE_BOOLEAN)) {
    // triggers
    try {
        if ($pdo) {
            $tr = $pdo->query("SHOW TRIGGERS");
            foreach ($tr->fetchAll(PDO::FETCH_ASSOC) as $t) {
                // building CREATE TRIGGER is not always available via SHOW CREATE TRIGGER pre-8.0; use SHOW CREATE TRIGGER if available
                $name = $t['Trigger'];
                try {
                    $row = $pdo->query("SHOW CREATE TRIGGER " . esc_ident($name))->fetch(PDO::FETCH_ASSOC);
                    foreach ($row as $k => $v) {
                        if (stripos($k, 'create') !== false) {
                            write_line($v . ';');
                            break;
                        }
                    }
                } catch (Throwable $e) {
                    // fallback to SHOW TRIGGERS output
                    $line = sprintf("-- Trigger %s: %s %s ON %s", $name, $t['Timing'], $t['Event'], $t['Table']);
                    write_line($line);
                }
            }
        } else {
            $res = mysqli_query($mysqli, "SHOW TRIGGERS");
            while ($t = mysqli_fetch_assoc($res)) {
                $name = $t['Trigger'];
                write_line("-- Trigger: $name");
                // No easy SHOW CREATE TRIGGER fallback in mysqli
            }
        }
    } catch(Throwable $e) {
        write_line('-- Could not dump triggers: ' . $e->getMessage());
    }
}

/* ------------------------
   Footer
   ------------------------ */
write_line('');
write_line('SET FOREIGN_KEY_CHECKS = 1;');
write_line('-- End of dump');

$closeFn();

/* ------------------------
   Web form function
   ------------------------ */
function web_form(): string {
    $html = <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"><title>QuickMyDump</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial;padding:20px;max-width:900px;margin:auto}
label{display:block;margin-top:8px}
input[type=text],input[type=password],select{width:100%;padding:8px}
textarea{width:100%;height:120px}
.btn{display:inline-block;padding:8px 12px;margin-top:10px;background:#007bff;color:#fff;border-radius:6px;text-decoration:none}
.small{font-size:0.9rem;color:#666}
</style>
</head>
<body>
<h1>QuickMyDump (web)</h1>
<form method="post">
<label>Host<input type="text" name="host" value="127.0.0.1"></label>
<label>Port<input type="text" name="port" value="3306"></label>
<label>Socket<input type="text" name="socket" value=""></label>
<label>User<input type="text" name="user" value="root"></label>
<label>Password<input type="password" name="pass" value=""></label>
<label>Database<input type="text" name="db" value=""></label>
<label>Output file (on server)<input type="text" name="outfile" placeholder="/tmp/mydump.sql.gz"></label>
<label><input type="checkbox" name="gzip" value="1" checked> Gzip output</label>
<label>Tables (comma separated) <span class="small">(leave empty to dump all)</span><input type="text" name="tables"></label>
<label>Exclude patterns (comma separated prefixes, * wildcards allowed)<input type="text" name="exclude"></label>
<label><input type="checkbox" name="extended-insert" checked> Use extended inserts (multi-row)</label>
<label>Chunk size (rows per fetch)<input type="text" name="max-rows" value="1000"></label>
<label><input type="checkbox" name="routines" checked> Dump routines</label>
<label><input type="checkbox" name="triggers" checked> Dump triggers</label>
<label><input type="checkbox" name="views" checked> Dump views</label>
<label><input type="checkbox" name="drop-table" checked> Include DROP TABLE IF EXISTS</label>
<button class="btn" type="submit">Start Dump (server-side)</button>
</form>
<p class="small">This web UI is intended for small databases or testing. For very large databases use CLI.</p>
</body>
</html>
HTML;
    return $html;
}