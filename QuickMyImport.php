<?php
/**
 * QuickMyImport v2.0 - MySQL database importer with resume capability
 *
 * Features:
 *  - Resume failed/interrupted imports
 *  - Progress tracking and reporting
 *  - Error handling and logging
 *  - Support for gzipped SQL files
 *  - Batch execution for large files
 *  - Skip already executed statements
 *
 * Usage:
 *   php QuickMyImport.php --host=127.0.0.1 --user=root --pass=secret --db=mydb --file=backup.sql
 *
 * Resume interrupted import:
 *   php QuickMyImport.php --resume --checkpoint=/tmp/import.checkpoint
 *
 * Import gzipped file:
 *   php QuickMyImport.php --db=mydb --file=backup.sql.gz
 */

declare(strict_types=1);

ini_set('memory_limit', '-1');
set_time_limit(0);

define('IMPORT_VERSION', '2.0.0');

/* ------------------------
   Logger Class
   ------------------------ */
class ImportLogger {
    private $logFile = null;
    private bool $verbose = false;
    private bool $quiet = false;
    private int $startTime;
    private array $errors = [];
    
    public function __construct(bool $verbose = false, bool $quiet = false, ?string $logFile = null) {
        $this->verbose = $verbose;
        $this->quiet = $quiet;
        $this->startTime = time();
        
        if ($logFile) {
            $this->logFile = fopen($logFile, 'a');
            if (!$this->logFile) {
                fwrite(STDERR, "Warning: Could not open log file {$logFile}\n");
            }
        }
    }
    
    public function log(string $message, string $level = 'INFO'): void {
        $timestamp = date('Y-m-d H:i:s');
        $formatted = "[{$timestamp}] [{$level}] {$message}\n";
        
        if ($this->logFile) {
            fwrite($this->logFile, $formatted);
        }
        
        if (!$this->quiet) {
            if ($level === 'ERROR') {
                $this->errors[] = $message;
                fwrite(STDERR, $formatted);
            } elseif ($this->verbose || $level === 'ERROR' || $level === 'WARNING') {
                fwrite(STDOUT, $formatted);
            }
        }
    }
    
    public function error(string $message): void {
        $this->log($message, 'ERROR');
    }
    
    public function warning(string $message): void {
        $this->log($message, 'WARNING');
    }
    
    public function info(string $message): void {
        $this->log($message, 'INFO');
    }
    
    public function debug(string $message): void {
        if ($this->verbose) {
            $this->log($message, 'DEBUG');
        }
    }
    
    public function progress(string $message): void {
        if (!$this->quiet && php_sapi_name() === 'cli') {
            fwrite(STDERR, "\r\033[K" . $message);
        }
    }
    
    public function progressDone(): void {
        if (!$this->quiet && php_sapi_name() === 'cli') {
            fwrite(STDERR, "\n");
        }
    }
    
    public function getElapsedTime(): string {
        $elapsed = time() - $this->startTime;
        $hours = floor($elapsed / 3600);
        $minutes = floor(($elapsed % 3600) / 60);
        $seconds = $elapsed % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
    
    public function getErrors(): array {
        return $this->errors;
    }
    
    public function __destruct() {
        if ($this->logFile) {
            fclose($this->logFile);
        }
    }
}

/* ------------------------
   Import Checkpoint Manager
   ------------------------ */
class ImportCheckpoint {
    private string $checkpointFile;
    private array $state = [];
    
    public function __construct(string $checkpointFile) {
        $this->checkpointFile = $checkpointFile;
        $this->load();
    }
    
    private function load(): void {
        if (file_exists($this->checkpointFile)) {
            $content = file_get_contents($this->checkpointFile);
            $this->state = json_decode($content, true) ?? [];
        } else {
            $this->state = [
                'file' => '',
                'position' => 0,
                'statements_executed' => 0,
                'last_statement_hash' => '',
                'started_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }
    }
    
    public function save(): void {
        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $dir = dirname($this->checkpointFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->checkpointFile, json_encode($this->state, JSON_PRETTY_PRINT));
    }
    
    public function setFile(string $file): void {
        $this->state['file'] = $file;
        $this->save();
    }
    
    public function setPosition(int $position): void {
        $this->state['position'] = $position;
        $this->save();
    }
    
    public function incrementStatements(): void {
        $this->state['statements_executed'] = ($this->state['statements_executed'] ?? 0) + 1;
    }
    
    public function setLastStatementHash(string $hash): void {
        $this->state['last_statement_hash'] = $hash;
    }
    
    public function getPosition(): int {
        return $this->state['position'] ?? 0;
    }
    
    public function getStatementsExecuted(): int {
        return $this->state['statements_executed'] ?? 0;
    }
    
    public function getFile(): string {
        return $this->state['file'] ?? '';
    }
    
    public function getState(): array {
        return $this->state;
    }
    
    public function clear(): void {
        if (file_exists($this->checkpointFile)) {
            unlink($this->checkpointFile);
        }
        $this->state = [];
    }
}

/* ------------------------
   SQL File Reader
   ------------------------ */
class SQLFileReader {
    private $handle = null;
    private bool $isGzipped = false;
    private bool $isZipped = false;
    private string $file;
    private int $position = 0;
    private string $buffer = '';
    private ?string $tempFile = null;
    
    public function __construct(string $file) {
        $this->file = $file;
        
        if (!file_exists($file)) {
            throw new RuntimeException("SQL file not found: {$file}");
        }
        
        $this->isGzipped = str_ends_with(strtolower($file), '.gz');
        $this->isZipped = str_ends_with(strtolower($file), '.zip');
        
        // Handle ZIP files - extract first .sql file to temp location
        if ($this->isZipped) {
            $zip = new ZipArchive();
            if ($zip->open($file) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($name), '.sql')) {
                        $this->tempFile = sys_get_temp_dir() . '/quickmyimport_' . uniqid() . '.sql';
                        $content = $zip->getFromIndex($i);
                        file_put_contents($this->tempFile, $content);
                        $zip->close();
                        
                        $this->handle = fopen($this->tempFile, 'r');
                        if (!$this->handle) {
                            throw new RuntimeException("Cannot open extracted SQL file from ZIP");
                        }
                        return;
                    }
                }
                $zip->close();
                throw new RuntimeException("No .sql file found in ZIP archive: {$file}");
            } else {
                throw new RuntimeException("Cannot open ZIP file: {$file}");
            }
        }
        
        if ($this->isGzipped) {
            $this->handle = gzopen($file, 'r');
            if (!$this->handle) {
                throw new RuntimeException("Cannot open gzipped file: {$file}");
            }
        } else {
            $this->handle = fopen($file, 'r');
            if (!$this->handle) {
                throw new RuntimeException("Cannot open file: {$file}");
            }
        }
    }
    
    public function seek(int $position): void {
        if ($this->isZipped) {
            // ZIP files extracted to temp, treat as regular file
            fseek($this->handle, $position);
        } elseif ($this->isGzipped) {
            // Gzip doesn't support seeking, need to read from start
            gzrewind($this->handle);
            $bytesRead = 0;
            while ($bytesRead < $position && !gzeof($this->handle)) {
                $chunk = min(8192, $position - $bytesRead);
                gzread($this->handle, $chunk);
                $bytesRead += $chunk;
            }
        } else {
            fseek($this->handle, $position);
        }
        $this->position = $position;
        $this->buffer = '';
    }
    
    public function readStatement(): ?string {
        $statement = '';
        $inString = false;
        $stringChar = '';
        $escaped = false;
        $inComment = false;
        
        while (true) {
            if (empty($this->buffer)) {
                if ($this->isGzipped) {
                    $this->buffer = gzeof($this->handle) ? '' : gzgets($this->handle, 8192);
                } else {
                    $this->buffer = feof($this->handle) ? '' : fgets($this->handle, 8192);
                }
                
                if ($this->buffer === '' || $this->buffer === false) {
                    // End of file
                    $trimmed = trim($statement);
                    return $trimmed !== '' ? $trimmed : null;
                }
            }
            
            $line = $this->buffer;
            $this->buffer = '';
            $this->position += strlen($line);
            
            // Skip comment lines
            $trimmedLine = ltrim($line);
            if (str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '#')) {
                continue;
            }
            
            // Process character by character for accurate parsing
            for ($i = 0; $i < strlen($line); $i++) {
                $char = $line[$i];
                
                if ($escaped) {
                    $statement .= $char;
                    $escaped = false;
                    continue;
                }
                
                if ($char === '\\' && $inString) {
                    $escaped = true;
                    $statement .= $char;
                    continue;
                }
                
                if (!$inString && ($char === '"' || $char === "'")) {
                    $inString = true;
                    $stringChar = $char;
                    $statement .= $char;
                    continue;
                }
                
                if ($inString && $char === $stringChar) {
                    $inString = false;
                    $statement .= $char;
                    continue;
                }
                
                $statement .= $char;
                
                // Check for statement terminator
                if (!$inString && $char === ';') {
                    $trimmed = trim($statement);
                    if ($trimmed !== '' && $trimmed !== ';') {
                        return $trimmed;
                    }
                    $statement = '';
                }
            }
        }
    }
    
    public function getPosition(): int {
        return $this->position;
    }
    
    public function close(): void {
        if ($this->handle) {
            if ($this->isGzipped) {
                gzclose($this->handle);
            } else {
                fclose($this->handle);
            }
            $this->handle = null;
        }
        
        // Clean up temp file if ZIP was extracted
        if ($this->tempFile && file_exists($this->tempFile)) {
            unlink($this->tempFile);
            $this->tempFile = null;
        }
    }
    
    public function __destruct() {
        $this->close();
    }
}

/* ------------------------
   Database Importer
   ------------------------ */
class DatabaseImporter {
    private ?PDO $pdo = null;
    private ImportLogger $logger;
    private ?ImportCheckpoint $checkpoint;
    private array $config;
    private array $statistics = [
        'statements' => 0,
        'errors' => 0,
        'warnings' => 0,
    ];
    
    public function __construct(array $config, ImportLogger $logger, ?ImportCheckpoint $checkpoint = null) {
        $this->config = $config;
        $this->logger = $logger;
        $this->checkpoint = $checkpoint;
    }
    
    public function connect(): void {
        $dsnParts = [];
        $dsnParts[] = "mysql:host={$this->config['host']}";
        if (!empty($this->config['port'])) {
            $dsnParts[] = "port={$this->config['port']}";
        }
        if (!empty($this->config['socket'])) {
            $dsnParts[] = "unix_socket={$this->config['socket']}";
        }
        $dsn = implode(';', $dsnParts) . ";charset=utf8mb4";
        
        try {
            $this->logger->debug("Connecting to database...");
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
            
            if (!empty($this->config['db'])) {
                $dbName = str_replace('`', '``', $this->config['db']);
                $this->pdo->exec("USE `{$dbName}`");
                $this->logger->info("Connected to database: " . $this->config['db']);
            } else {
                $this->logger->info("Connected to MySQL server");
            }
            
            // Set optimal import settings
            $this->pdo->exec("SET autocommit=0");
            $this->pdo->exec("SET unique_checks=0");
            $this->pdo->exec("SET foreign_key_checks=0");
            
        } catch (Throwable $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }
    
    public function import(string $sqlFile): void {
        $this->logger->info("Starting import from: {$sqlFile}");
        
        $reader = new SQLFileReader($sqlFile);
        
        // Resume from checkpoint if available
        $startPosition = 0;
        if ($this->checkpoint) {
            $startPosition = $this->checkpoint->getPosition();
            if ($startPosition > 0) {
                $this->logger->info("Resuming from position: {$startPosition}");
                $this->logger->info("Statements already executed: " . $this->checkpoint->getStatementsExecuted());
                $reader->seek($startPosition);
                $this->statistics['statements'] = $this->checkpoint->getStatementsExecuted();
            }
        }
        
        $batchSize = $this->config['batch-size'] ?? 100;
        $batchCount = 0;
        $lastCommitTime = time();
        
        $this->pdo->beginTransaction();
        
        try {
            while (($statement = $reader->readStatement()) !== null) {
                $this->statistics['statements']++;
                $batchCount++;
                
                try {
                    $this->executeStatement($statement);
                    
                    if ($this->checkpoint) {
                        $this->checkpoint->setPosition($reader->getPosition());
                        $this->checkpoint->incrementStatements();
                    }
                    
                    // Commit batch periodically
                    if ($batchCount >= $batchSize || (time() - $lastCommitTime) >= 30) {
                        $this->pdo->commit();
                        if ($this->checkpoint) {
                            $this->checkpoint->save();
                        }
                        $this->pdo->beginTransaction();
                        $batchCount = 0;
                        $lastCommitTime = time();
                        
                        $this->logger->progress(
                            "Executed {$this->statistics['statements']} statements, " .
                            "Errors: {$this->statistics['errors']}, " .
                            "Position: " . $reader->getPosition()
                        );
                    }
                    
                } catch (Throwable $e) {
                    $this->statistics['errors']++;
                    $error = "Error executing statement: " . $e->getMessage();
                    $this->logger->error($error);
                    
                    if ($this->config['stop-on-error'] ?? false) {
                        throw $e;
                    }
                    
                    // Log problematic statement
                    if ($this->config['verbose'] ?? false) {
                        $preview = substr($statement, 0, 200);
                        $this->logger->debug("Problematic statement: {$preview}...");
                    }
                }
            }
            
            // Final commit
            $this->pdo->commit();
            if ($this->checkpoint) {
                $this->checkpoint->save();
            }
            
            $this->logger->progressDone();
            
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        } finally {
            $reader->close();
            
            // Restore settings
            $this->pdo->exec("SET autocommit=1");
            $this->pdo->exec("SET unique_checks=1");
            $this->pdo->exec("SET foreign_key_checks=1");
        }
    }
    
    public function executeStatement(string $statement): void {
        // Skip empty or comment-only statements
        $trimmed = trim($statement);
        if (empty($trimmed) || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
            return;
        }
        
        try {
            $this->pdo->exec($statement);
        } catch (PDOException $e) {
            // Provide more context for certain errors
            $errorCode = $e->getCode();
            
            if ($errorCode === '42S01' && stripos($statement, 'CREATE TABLE') !== false) {
                // Table already exists - might be okay in some cases
                if ($this->config['ignore-table-exists'] ?? false) {
                    $this->statistics['warnings']++;
                    $this->logger->warning("Table already exists, continuing...");
                    return;
                }
            }
            
            throw $e;
        }
    }
    
    public function getStatistics(): array {
        return $this->statistics;
    }
    
    public function disconnect(): void {
        $this->pdo = null;
    }
}

/* ------------------------
   Argument Parser
   ------------------------ */
function parse_import_args(array $argv): array {
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
   Web Interface Functions
   ------------------------ */
function render_web_interface(array $config = []): void {
    $error = '';
    $success = '';
    
    // Check if session is active
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Get upload directory
    $uploadDir = $config['upload_dir'] ?? __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Handle file upload
    if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        $filename = basename($_FILES['sql_file']['name']);
        $targetPath = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($_FILES['sql_file']['tmp_name'], $targetPath)) {
            // Handle ZIP files - extract first .sql file
            if (str_ends_with(strtolower($filename), '.zip')) {
                try {
                    $zip = new ZipArchive();
                    if ($zip->open($targetPath) === true) {
                        // Find first .sql file in the archive
                        $extractedFile = null;
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $name = $zip->getNameIndex($i);
                            if (str_ends_with(strtolower($name), '.sql')) {
                                $extractedPath = $uploadDir . '/' . basename($name);
                                if ($zip->extractTo($uploadDir, $name)) {
                                    // Move to correct location if extracted to subdirectory
                                    if (file_exists($uploadDir . '/' . $name)) {
                                        rename($uploadDir . '/' . $name, $extractedPath);
                                    }
                                    $extractedFile = $extractedPath;
                                    break;
                                }
                            }
                        }
                        $zip->close();
                        
                        if ($extractedFile) {
                            $_SESSION['uploaded_file'] = $extractedFile;
                            $success = "ZIP file extracted successfully: " . basename($extractedFile);
                            // Optionally delete the zip file
                            unlink($targetPath);
                        } else {
                            $error = "No .sql file found in ZIP archive";
                            unlink($targetPath);
                        }
                    } else {
                        $error = "Failed to open ZIP file";
                        unlink($targetPath);
                    }
                } catch (Exception $e) {
                    $error = "ZIP extraction error: " . $e->getMessage();
                    if (file_exists($targetPath)) unlink($targetPath);
                }
            } else {
                $_SESSION['uploaded_file'] = $targetPath;
                $success = "File uploaded successfully: {$filename}";
            }
        } else {
            $error = "Failed to upload file";
        }
    }
    
    // Handle delete uploaded file
    if (isset($_POST['delete_file']) && isset($_SESSION['uploaded_file'])) {
        if (file_exists($_SESSION['uploaded_file'])) {
            unlink($_SESSION['uploaded_file']);
        }
        unset($_SESSION['uploaded_file']);
        unset($_SESSION['import_state']);
        $success = "File deleted and import reset";
    }
    
    // Handle reset/clear selection
    if (isset($_POST['clear_selection'])) {
        unset($_SESSION['uploaded_file']);
        unset($_SESSION['import_state']);
        $success = "Selection cleared";
    }
    
    // Get available files from uploads directory
    $availableFiles = [];
    if (is_dir($uploadDir)) {
        $files = glob($uploadDir . '/*.{sql,gz,zip}', GLOB_BRACE);
        foreach ($files as $file) {
            $availableFiles[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'modified' => filemtime($file),
                'location' => 'uploads',
            ];
        }
    }
    
    // Get available files from current directory (where QuickMyImport.php is located)
    $currentDirFiles = glob(__DIR__ . '/*.{sql,gz,zip}', GLOB_BRACE);
    foreach ($currentDirFiles as $file) {
        $availableFiles[] = [
            'name' => basename($file),
            'path' => $file,
            'size' => filesize($file),
            'modified' => filemtime($file),
            'location' => 'current',
        ];
    }
    
    // Sort files by modification time (newest first)
    usort($availableFiles, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
    
    // Get current file
    $currentFile = $_SESSION['uploaded_file'] ?? '';
    if (isset($_POST['selected_file']) && isset($_POST['file_location'])) {
        $selectedFile = basename($_POST['selected_file']);
        
        if ($_POST['file_location'] === 'uploads') {
            $currentFile = $uploadDir . '/' . $selectedFile;
        } else {
            $currentFile = __DIR__ . '/' . $selectedFile;
        }
        
        // Handle ZIP files - extract first .sql file
        if (str_ends_with(strtolower($selectedFile), '.zip')) {
            try {
                $zip = new ZipArchive();
                if ($zip->open($currentFile) === true) {
                    $extractedFile = null;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if (str_ends_with(strtolower($name), '.sql')) {
                            $extractDir = ($_POST['file_location'] === 'uploads') ? $uploadDir : __DIR__;
                            $extractedPath = $extractDir . '/' . basename($name);
                            if ($zip->extractTo($extractDir, $name)) {
                                if (file_exists($extractDir . '/' . $name)) {
                                    rename($extractDir . '/' . $name, $extractedPath);
                                }
                                $extractedFile = $extractedPath;
                                break;
                            }
                        }
                    }
                    $zip->close();
                    
                    if ($extractedFile) {
                        $currentFile = $extractedFile;
                        $success = "ZIP file extracted: " . basename($extractedFile);
                    } else {
                        $error = "No .sql file found in ZIP archive";
                        $currentFile = '';
                    }
                } else {
                    $error = "Failed to open ZIP file";
                    $currentFile = '';
                }
            } catch (Exception $e) {
                $error = "ZIP extraction error: " . $e->getMessage();
                $currentFile = '';
            }
        }
        
        if ($currentFile) {
            $_SESSION['uploaded_file'] = $currentFile;
        }
    }
    
    $fileInfo = '';
    if ($currentFile && file_exists($currentFile)) {
        $size = filesize($currentFile);
        $sizeFormatted = format_bytes($size);
        $fileInfo = basename($currentFile) . " ({$sizeFormatted})";
    }
    
    $version = IMPORT_VERSION;
    
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickMyImport v{$version} - MySQL Database Importer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 8px 8px 0 0; }
        .header h1 { font-size: 28px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .content { padding: 30px; }
        .section { margin-bottom: 30px; padding-bottom: 30px; border-bottom: 1px solid #e0e0e0; }
        .section:last-child { border-bottom: none; }
        .section h2 { color: #333; margin-bottom: 15px; font-size: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #555; font-weight: 500; font-size: 14px; }
        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="number"],
        .form-group select,
        .form-group input[type="file"] { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .btn { padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; }
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-danger { background: #f56565; color: white; }
        .btn-danger:hover { background: #e53e3e; }
        .btn-secondary { background: #718096; color: white; }
        .btn-secondary:hover { background: #4a5568; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; }
        .alert-error { background: #fed7d7; color: #c53030; border: 1px solid #fc8181; }
        .alert-success { background: #c6f6d5; color: #22543d; border: 1px solid #9ae6b4; }
        .alert-info { background: #bee3f8; color: #2c5282; border: 1px solid #90cdf4; }
        .progress-container { background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 20px; margin-top: 20px; }
        .progress-bar { width: 100%; height: 30px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-bottom: 15px; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: 500; font-size: 13px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
        .stat-box { background: #f7fafc; padding: 15px; border-radius: 4px; border: 1px solid #e2e8f0; }
        .stat-label { color: #718096; font-size: 12px; margin-bottom: 5px; }
        .stat-value { color: #2d3748; font-size: 24px; font-weight: 600; }
        .file-list { list-style: none; }
        .file-item { padding: 10px; background: #f7fafc; margin-bottom: 8px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; }
        .file-info { flex: 1; }
        .file-name { font-weight: 500; color: #2d3748; }
        .file-meta { font-size: 12px; color: #718096; margin-top: 3px; }
        .button-group { display: flex; gap: 10px; margin-top: 15px; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>QuickMyImport v{$version}</h1>
            <p>MySQL Database Importer with Resume Capability</p>
        </div>
        <div class="content">
HTML;
    
    if ($error) {
        echo "<div class='alert alert-error'>{$error}</div>";
    }
    if ($success) {
        echo "<div class='alert alert-success'>{$success}</div>";
    }
    
    // Configuration Form
    echo <<<HTML
            <div class="section">
                <h2>Database Configuration</h2>
                <form method="POST" id="configForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Host</label>
                            <input type="text" name="host" value="127.0.0.1" required>
                        </div>
                        <div class="form-group">
                            <label>Port</label>
                            <input type="number" name="port" value="3306" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="user" value="root" required>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" name="pass">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Database Name</label>
                        <input type="text" name="db" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Batch Size</label>
                            <input type="number" name="batch_size" value="100" min="1">
                        </div>
                        <div class="form-group">
                            <label>Statements per Execution</label>
                            <input type="number" name="statements_per_run" value="300" min="1">
                        </div>
                    </div>
                </form>
            </div>
HTML;
    
    // File Upload/Selection
    echo <<<HTML
            <div class="section">
                <h2>SQL File</h2>
HTML;
    
    if ($currentFile && file_exists($currentFile)) {
        echo <<<HTML
                <div class="alert alert-info">
                    Current file: <strong>{$fileInfo}</strong>
                </div>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="delete_file" value="1" class="btn btn-danger">Delete File & Reset</button>
                </form>
                <form method="POST" style="display: inline; margin-left: 10px;">
                    <button type="submit" name="clear_selection" value="1" class="btn btn-secondary">Change File</button>
                </form>
HTML;
    } else {
        echo <<<HTML
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Upload SQL File (.sql, .gz, or .zip)</label>
                        <input type="file" name="sql_file" accept=".sql,.gz,.zip" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload File</button>
                </form>
HTML;
        
        if (!empty($availableFiles)) {
            echo "<h3 style='margin-top: 20px; margin-bottom: 10px; font-size: 16px; color: #555;'>Or select existing file:</h3>";
            echo "<form method='POST'><ul class='file-list'>";
            foreach ($availableFiles as $file) {
                $sizeStr = format_bytes($file['size']);
                $dateStr = date('Y-m-d H:i:s', $file['modified']);
                $locationBadge = $file['location'] === 'uploads' 
                    ? '<span style="background: #4299e1; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;">uploads</span>' 
                    : '<span style="background: #48bb78; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;">main directory</span>';
                echo <<<HTML
                    <li class="file-item">
                        <div class="file-info">
                            <div class="file-name">{$file['name']} {$locationBadge}</div>
                            <div class="file-meta">{$sizeStr} - Modified: {$dateStr}</div>
                        </div>
                        <button type="submit" name="selected_file" value="{$file['name']}" class="btn btn-secondary" 
                                onclick="document.querySelector('input[name=file_location]').value='{$file['location']}'">Select</button>
                    </li>
HTML;
            }
            echo "<input type='hidden' name='file_location' value=''></ul></form>";
        } else {
            echo "<p style='margin-top: 15px; color: #718096; font-style: italic;'>No SQL files found in uploads or main directory. Please upload a file above.</p>";
        }
    }
    
    echo "</div>";
    
    // Import Controls
    if ($currentFile && file_exists($currentFile)) {
        $inProgress = isset($_SESSION['import_state']) && $_SESSION['import_state']['status'] === 'running';
        $isComplete = isset($_SESSION['import_state']) && $_SESSION['import_state']['status'] === 'completed';
        
        echo <<<HTML
            <div class="section">
                <h2>Import Control</h2>
HTML;
        
        if ($isComplete) {
            echo <<<HTML
                <div class="alert alert-success">Import completed successfully!</div>
                <div class="button-group">
                    <button onclick="resetImport()" class="btn btn-secondary">Start New Import</button>
                </div>
HTML;
        } else {
            $buttonText = $inProgress ? 'Continue' : 'Start';
            $stopDisabled = $inProgress ? '' : 'disabled';
            echo <<<HTML
                <div class="button-group">
                    <button onclick="startImport()" class="btn btn-success" id="startBtn">
                        {$buttonText} Import
                    </button>
                    <button onclick="stopImport()" class="btn btn-danger" id="stopBtn" {$stopDisabled}>
                        Stop Import
                    </button>
                </div>
HTML;
        }
        
        echo <<<HTML
                <div id="progressContainer" class="progress-container hidden">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressBar" style="width: 0%">0%</div>
                    </div>
                    <div class="stats-grid" id="statsGrid">
                        <div class="stat-box">
                            <div class="stat-label">Statements</div>
                            <div class="stat-value" id="statStatements">0</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Position</div>
                            <div class="stat-value" id="statPosition">0</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Errors</div>
                            <div class="stat-value" id="statErrors">0</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Time Elapsed</div>
                            <div class="stat-value" id="statTime">00:00:00</div>
                        </div>
                    </div>
                    <div id="statusMessage" style="margin-top: 15px; color: #4a5568; font-size: 14px;"></div>
                </div>
            </div>
HTML;
    }
    
    echo <<<HTML
        </div>
    </div>
    
    <script>
        let importRunning = false;
        let importTimer = null;
        let startTime = null;
        
        function startImport() {
            if (importRunning) return;
            
            const formData = new FormData(document.getElementById('configForm'));
            formData.append('action', 'import');
            
            importRunning = true;
            document.getElementById('startBtn').disabled = true;
            document.getElementById('stopBtn').disabled = false;
            document.getElementById('progressContainer').classList.remove('hidden');
            
            startTime = Date.now();
            runImportStep(formData);
        }
        
        function runImportStep(formData) {
            if (!importRunning) return;
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                updateProgress(data);
                
                if (data.status === 'running' && importRunning) {
                    setTimeout(() => runImportStep(formData), 100);
                } else if (data.status === 'completed') {
                    importRunning = false;
                    document.getElementById('statusMessage').innerHTML = '<strong style="color: #38a169;">Import completed successfully!</strong>';
                    setTimeout(() => location.reload(), 2000);
                } else if (data.status === 'error') {
                    importRunning = false;
                    document.getElementById('statusMessage').innerHTML = '<strong style="color: #e53e3e;">Error: ' + data.message + '</strong>';
                    document.getElementById('startBtn').disabled = false;
                    document.getElementById('stopBtn').disabled = true;
                }
            })
            .catch(err => {
                console.error('Import error:', err);
                importRunning = false;
                document.getElementById('statusMessage').innerHTML = '<strong style="color: #e53e3e;">Connection error. Please check console.</strong>';
                document.getElementById('startBtn').disabled = false;
                document.getElementById('stopBtn').disabled = true;
            });
        }
        
        function stopImport() {
            importRunning = false;
            document.getElementById('startBtn').disabled = false;
            document.getElementById('stopBtn').disabled = true;
            document.getElementById('statusMessage').innerHTML = '<strong style="color: #718096;">Import stopped by user</strong>';
        }
        
        function resetImport() {
            fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=reset'
            })
            .then(() => location.reload());
        }
        
        function updateProgress(data) {
            const percent = data.progress || 0;
            document.getElementById('progressBar').style.width = percent + '%';
            document.getElementById('progressBar').textContent = percent.toFixed(1) + '%';
            
            document.getElementById('statStatements').textContent = (data.statements || 0).toLocaleString();
            document.getElementById('statPosition').textContent = formatBytes(data.position || 0);
            document.getElementById('statErrors').textContent = data.errors || 0;
            
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            const hours = Math.floor(elapsed / 3600);
            const minutes = Math.floor((elapsed % 3600) / 60);
            const seconds = elapsed % 60;
            document.getElementById('statTime').textContent = 
                String(hours).padStart(2, '0') + ':' + 
                String(minutes).padStart(2, '0') + ':' + 
                String(seconds).padStart(2, '0');
            
            if (data.message) {
                document.getElementById('statusMessage').textContent = data.message;
            }
        }
        
        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>
HTML;
}

function format_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

function handle_web_import(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'reset') {
            unset($_SESSION['import_state']);
            echo json_encode(['status' => 'ok']);
            exit;
        }
        
        if ($action !== 'import') {
            throw new RuntimeException('Invalid action');
        }
        
        // Get configuration
        $config = [
            'host' => $_POST['host'] ?? '127.0.0.1',
            'port' => (int)($_POST['port'] ?? 3306),
            'user' => $_POST['user'] ?? 'root',
            'pass' => $_POST['pass'] ?? '',
            'db' => $_POST['db'] ?? '',
            'batch-size' => (int)($_POST['batch_size'] ?? 100),
            'statements-per-run' => (int)($_POST['statements_per_run'] ?? 300),
        ];
        
        $sqlFile = $_SESSION['uploaded_file'] ?? '';
        if (!$sqlFile || !file_exists($sqlFile)) {
            throw new RuntimeException('No SQL file available');
        }
        
        // Initialize or resume import state
        if (!isset($_SESSION['import_state'])) {
            $_SESSION['import_state'] = [
                'status' => 'running',
                'position' => 0,
                'statements' => 0,
                'errors' => 0,
                'file_size' => filesize($sqlFile),
                'started_at' => time(),
            ];
        }
        
        $state = &$_SESSION['import_state'];
        
        if ($state['status'] === 'completed') {
            echo json_encode([
                'status' => 'completed',
                'progress' => 100,
                'statements' => $state['statements'],
                'errors' => $state['errors'],
                'position' => $state['position'],
            ]);
            exit;
        }
        
        // Create logger (web mode - no output)
        $logger = new ImportLogger(false, true, null);
        
        // Create temporary checkpoint
        $checkpointFile = sys_get_temp_dir() . '/quickmyimport_web_' . session_id() . '.checkpoint';
        $checkpoint = new ImportCheckpoint($checkpointFile);
        $checkpoint->setFile($sqlFile);
        $checkpoint->setPosition($state['position']);
        
        // Create importer
        $importer = new DatabaseImporter($config, $logger, $checkpoint);
        $importer->connect();
        
        // Open SQL file reader
        $reader = new SQLFileReader($sqlFile);
        $reader->seek($state['position']);
        
        $statementsToExecute = $config['statements-per-run'];
        $executed = 0;
        
        // Execute batch of statements
        while ($executed < $statementsToExecute && ($statement = $reader->readStatement()) !== null) {
            try {
                if (trim($statement)) {
                    $importer->executeStatement($statement);
                    $state['statements']++;
                    $executed++;
                }
            } catch (Throwable $e) {
                $state['errors']++;
            }
            
            $state['position'] = $reader->getPosition();
        }
        
        $reader->close();
        $importer->disconnect();
        
        // Calculate progress
        $progress = ($state['file_size'] > 0) ? ($state['position'] / $state['file_size']) * 100 : 0;
        
        // Check if completed
        if ($statement === null) {
            $state['status'] = 'completed';
            if (file_exists($checkpointFile)) {
                unlink($checkpointFile);
            }
        }
        
        echo json_encode([
            'status' => $state['status'],
            'progress' => min($progress, 100),
            'statements' => $state['statements'],
            'errors' => $state['errors'],
            'position' => $state['position'],
            'message' => $state['status'] === 'completed' ? 'Import completed' : "Processing... {$state['statements']} statements executed",
        ]);
        
    } catch (Throwable $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
        ]);
    }
    
    exit;
}

/* ------------------------
   Main Execution
   ------------------------ */

$defaults = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'socket' => '',
    'user' => 'root',
    'pass' => '',
    'db' => '',
    'file' => '',
    'checkpoint' => '',
    'resume' => false,
    'verbose' => false,
    'quiet' => false,
    'logfile' => '',
    'batch-size' => 100,
    'stop-on-error' => false,
    'ignore-table-exists' => false,
    'help' => false,
    'version' => false,
];

// Detect CLI vs Web mode
$isCLI = php_sapi_name() === 'cli';

if (!$isCLI) {
    // Web mode
    if (isset($_POST['action'])) {
        handle_web_import();
    } else {
        render_web_interface();
    }
    exit;
}

$args = parse_import_args($argv);

// Show help
if (isset($args['help'])) {
    echo <<<HELP
QuickMyImport v2.0 - MySQL Database Importer with Resume Capability

Usage: php QuickMyImport.php [options]

Connection Options:
  --host=HOST          MySQL host (default: 127.0.0.1)
  --port=PORT          MySQL port (default: 3306)
  --socket=SOCKET      Unix socket path
  --user=USER          Database user (default: root)
  --pass=PASSWORD      Database password
  --db=DATABASE        Database name

Import Options:
  --file=FILE          SQL file to import (required)
  --batch-size=N       Statements per commit (default: 100)
  --stop-on-error      Stop on first error
  --ignore-table-exists Ignore "table exists" errors

Resume Options:
  --checkpoint=FILE    Checkpoint file path
  --resume             Resume from checkpoint

Logging:
  --verbose            Verbose output
  --quiet              Quiet mode (errors only)
  --logfile=FILE       Log file path

Other:
  --help               Show this help
  --version            Show version

Examples:
  Basic import:
    php QuickMyImport.php --db=mydb --file=backup.sql

  Import gzipped file:
    php QuickMyImport.php --db=mydb --file=backup.sql.gz

  Resume failed import:
    php QuickMyImport.php --resume --checkpoint=/tmp/import.checkpoint

  Import with error handling:
    php QuickMyImport.php --db=mydb --file=backup.sql --ignore-table-exists --logfile=import.log

HELP;
    exit(0);
}

// Show version
if (isset($args['version'])) {
    echo "QuickMyImport v" . IMPORT_VERSION . "\n";
    exit(0);
}

foreach ($defaults as $k => $v) {
    if (!array_key_exists($k, $args)) {
        $args[$k] = $v;
    }
}

try {
    // Validate required parameters
    if (empty($args['file']) && !$args['resume']) {
        throw new RuntimeException("SQL file is required (--file parameter)");
    }
    
    // Create logger
    $logger = new ImportLogger(
        (bool)($args['verbose'] ?? false),
        (bool)($args['quiet'] ?? false),
        $args['logfile'] ?: null
    );
    
    $logger->info("QuickMyImport v" . IMPORT_VERSION . " starting...");
    
    // Create checkpoint manager if needed
    $checkpoint = null;
    if ($args['resume'] || $args['checkpoint']) {
        $checkpointFile = $args['checkpoint'] ?: 
                         sys_get_temp_dir() . '/quickmyimport_' . ($args['db'] ?: 'import') . '.checkpoint';
        $checkpoint = new ImportCheckpoint($checkpointFile);
        
        if ($args['resume']) {
            $logger->info("Resuming from checkpoint: {$checkpointFile}");
            $state = $checkpoint->getState();
            $logger->debug("Checkpoint state: " . json_encode($state));
            
            // Get file from checkpoint if not specified
            if (empty($args['file']) && !empty($checkpoint->getFile())) {
                $args['file'] = $checkpoint->getFile();
                $logger->info("Using file from checkpoint: " . $args['file']);
            }
        } else {
            // Clear old checkpoint for new import
            $checkpoint->clear();
        }
        
        $checkpoint->setFile($args['file']);
    }
    
    // Create importer
    $importer = new DatabaseImporter($args, $logger, $checkpoint);
    $importer->connect();
    
    // Execute import
    $importer->import($args['file']);
    
    // Show statistics
    $stats = $importer->getStatistics();
    $logger->info("Import completed!");
    $logger->info("Statements executed: " . $stats['statements']);
    $logger->info("Errors: " . $stats['errors']);
    $logger->info("Warnings: " . $stats['warnings']);
    $logger->info("Elapsed time: " . $logger->getElapsedTime());
    
    // Cleanup
    $importer->disconnect();
    
    if ($checkpoint && $stats['errors'] === 0) {
        $checkpoint->clear();
        $logger->info("Checkpoint cleared (import successful)");
    } elseif ($checkpoint) {
        $logger->warning("Checkpoint preserved due to errors. Use --resume to continue.");
    }
    
    // Exit with error code if there were errors
    if ($stats['errors'] > 0) {
        $logger->warning("Import completed with {$stats['errors']} errors");
        exit(1);
    }
    
    exit(0);
    
} catch (Throwable $e) {
    $msg = "FATAL ERROR: " . $e->getMessage();
    if (isset($logger)) {
        $logger->error($msg);
        if ($args['verbose'] ?? false) {
            $logger->error("Stack trace: " . $e->getTraceAsString());
        }
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    
    exit(1);
}
