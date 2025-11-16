# QuickMyDump

A modern, memory-efficient MySQL database dumper for PHP 8+ designed specifically for handling large databases.

## Overview

QuickMyDump is a single-file PHP utility that provides a lightweight alternative to mysqldump with advanced features for exporting large MySQL databases. It uses streaming output to minimize memory consumption and supports both command-line and web-based interfaces.

## Features

### Core Capabilities
- Memory-efficient streaming output (no database size limits)
- Dual database driver support (PDO with automatic mysqli fallback)
- Gzip compression for reduced file sizes
- Chunked data fetching to prevent memory exhaustion
- Extended INSERT statements for faster imports
- Support for tables, views, triggers, stored procedures, and functions

### Filtering Options
- Include specific tables by name
- Exclude tables by pattern matching (prefix, suffix, or wildcard)
- Selective export of schema components

### Export Options
- Schema export (CREATE TABLE/VIEW statements)
- Data export with configurable chunk sizes
- Stored routines (procedures and functions)
- Triggers
- Views
- Optional DROP TABLE IF EXISTS statements

## Requirements

- PHP 8.0 or higher
- One of the following PHP extensions:
  - PDO_MYSQL (preferred)
  - mysqli (automatic fallback)
- MySQL 5.7+ or MariaDB 10.2+
- Sufficient disk space for export file
- For large databases: CLI access recommended

## Installation

Download the single PHP file to your system:

```bash
wget https://raw.githubusercontent.com/ProgrammerNomad/QuickMyDump/main/QuickMyDump.php
```

Or clone the repository:

```bash
git clone https://github.com/ProgrammerNomad/QuickMyDump.git
cd QuickMyDump
```

No additional installation or dependencies required.

## Usage

### Command Line Interface (Recommended for Large Databases)

#### Basic Usage

```bash
php QuickMyDump.php --host=127.0.0.1 --user=root --pass=yourpassword --db=mydatabase --outfile=backup.sql
```

#### Complete Example with All Options

```bash
php QuickMyDump.php \
    --host=127.0.0.1 \
    --port=3306 \
    --user=root \
    --pass=secret \
    --db=production_db \
    --outfile=backup.sql.gz \
    --gzip \
    --exclude=cache_,tmp_,sessions% \
    --extended-insert \
    --max-rows=1000 \
    --routines \
    --triggers \
    --views \
    --drop-table
```

#### Output to stdout

```bash
php QuickMyDump.php --host=127.0.0.1 --user=root --pass=secret --db=mydb > output.sql
```

#### Using Unix Socket

```bash
php QuickMyDump.php --socket=/var/run/mysqld/mysqld.sock --user=root --db=mydb --outfile=dump.sql
```

### Web Interface (For Testing or Small Databases)

1. Place QuickMyDump.php in your web server document root
2. Navigate to the file in your browser (e.g., http://localhost/QuickMyDump.php)
3. Fill out the form with your database credentials
4. Click "Start Dump" to export the database server-side

Note: Web interface is not recommended for large databases due to PHP execution time limits and browser timeouts.

## Command Line Parameters

### Connection Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| --host | MySQL server hostname or IP address | 127.0.0.1 |
| --port | MySQL server port | 3306 |
| --socket | Unix socket path (overrides host/port) | empty |
| --user | Database username | root |
| --pass | Database password | empty |
| --db | Database name to export | required |

### Output Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| --outfile | Output file path (omit for stdout) | stdout |
| --gzip | Enable gzip compression | false |

### Table Selection

| Parameter | Description | Default |
|-----------|-------------|---------|
| --tables | Comma-separated list of tables to include | all tables |
| --exclude | Comma-separated exclude patterns | empty |

### Export Options

| Parameter | Description | Default |
|-----------|-------------|---------|
| --extended-insert | Use multi-row INSERT statements | true |
| --max-rows | Number of rows to fetch per chunk | 1000 |
| --routines | Export stored procedures and functions | true |
| --triggers | Export triggers | true |
| --views | Export views | true |
| --drop-table | Include DROP TABLE IF EXISTS statements | true |

## Exclude Patterns

The --exclude parameter supports flexible pattern matching:

### Prefix Matching
```bash
--exclude=cache_,tmp_,test_
```
Excludes tables starting with cache_, tmp_, or test_

### Suffix Matching
```bash
--exclude=%_old,%_backup
```
Excludes tables ending with _old or _backup

### Wildcard Matching
```bash
--exclude=*temp*,*session*
```
Excludes tables containing temp or session anywhere in the name

## Performance Optimization

### For Large Databases (100GB+)

1. Use CLI interface instead of web
2. Increase chunk size for faster exports:
   ```bash
   --max-rows=5000
   ```
3. Enable gzip compression to reduce I/O:
   ```bash
   --gzip --outfile=backup.sql.gz
   ```
4. Use extended inserts for faster imports:
   ```bash
   --extended-insert
   ```

### For Very Large Tables

Adjust chunk size based on average row size:
- Small rows (< 1KB): --max-rows=10000
- Medium rows (1-10KB): --max-rows=1000
- Large rows (> 10KB): --max-rows=100

### Memory Considerations

The script sets memory_limit to unlimited and removes execution time limits. For shared hosting environments, you may need to adjust these in php.ini or use CLI with appropriate resource limits.

## Import Exported Files

### Standard SQL File
```bash
mysql -u root -p database_name < backup.sql
```

### Gzipped SQL File
```bash
gunzip < backup.sql.gz | mysql -u root -p database_name
```

Or use zcat:
```bash
zcat backup.sql.gz | mysql -u root -p database_name
```

## Examples

### Export Specific Tables Only
```bash
php QuickMyDump.php --db=mydb --tables=users,orders,products --outfile=partial.sql
```

### Export Without Routines and Triggers
```bash
php QuickMyDump.php --db=mydb --routines=false --triggers=false --outfile=schema_only.sql
```

### Exclude Cache and Temporary Tables
```bash
php QuickMyDump.php --db=mydb --exclude=cache_,tmp_,%temp% --outfile=clean_backup.sql.gz --gzip
```

### Quick Backup with Compression
```bash
php QuickMyDump.php --db=production --gzip --outfile=/backups/prod_$(date +%Y%m%d).sql.gz
```

## Advantages Over mysqldump

1. Pure PHP implementation (no mysqldump binary required)
2. Better memory efficiency through streaming
3. Flexible table filtering with pattern matching
4. Automatic fallback between PDO and mysqli
5. Built-in web interface for convenience
6. Single file deployment

## Limitations

1. Does not support:
   - Remote import (only export)
   - Incremental backups
   - Binary data types may need special handling
   - Cross-database exports in single operation
   
2. Web interface limitations:
   - Subject to PHP max_execution_time
   - Not suitable for databases > 1GB
   - Requires server-side file write permissions

## Security Considerations

1. Never expose the web interface on production systems without authentication
2. Store credentials securely (use environment variables or config files)
3. Ensure export files are written to secure locations with proper permissions
4. Delete or secure backup files after transfer
5. Use SSL/TLS for MySQL connections when exporting over networks

## Troubleshooting

### "DB Connection failed"
- Verify MySQL credentials
- Check if PDO_MYSQL or mysqli extension is enabled
- Confirm MySQL server is running and accessible
- Check firewall rules for MySQL port (3306)

### "Cannot open file for writing"
- Verify directory exists and is writable
- Check disk space availability
- Ensure PHP process has write permissions

### Out of Memory Errors
- Reduce --max-rows value
- Ensure memory_limit setting is adequate
- Use CLI instead of web interface

### Slow Export Performance
- Increase --max-rows for smaller row sizes
- Enable gzip compression
- Check MySQL server load
- Ensure indexes exist on large tables

## Contributing

Contributions are welcome. Please submit issues and pull requests on GitHub.

## License

This project is open source. Check the repository for license details.

## Author

ProgrammerNomad

## Repository

https://github.com/ProgrammerNomad/QuickMyDump