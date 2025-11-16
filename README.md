# QuickMyDump

Modern MySQL database export and import tool for PHP 8+ with resume capability for large databases.

## Features

- Memory-efficient streaming (handles unlimited database sizes)
- Resume interrupted exports and imports from checkpoint
- Schema-only and data-only export modes  
- Gzip compression support
- PDO with mysqli fallback
- Extended INSERT statements for faster imports
- Table filtering with patterns
- Binary data HEX encoding
- Single-transaction mode for InnoDB
- Progress tracking and logging
- Configuration file support
- Both CLI and web interfaces

## Requirements

- PHP 8.0+
- PDO_MYSQL or mysqli extension
- MySQL 5.7+ or MariaDB 10.2+
- CLI recommended for databases >1GB

## Installation

```bash
git clone https://github.com/ProgrammerNomad/QuickMyDump.git
cd QuickMyDump
```

## Quick Start

### Export Database (CLI)

```bash
php QuickMyDump.php --db=mydb --outfile=backup.sql.gz --gzip
```

### Import Database (CLI)

```bash
php QuickMyImport.php --db=mydb --file=backup.sql.gz
```

### Import Database (Web Interface)

1. Access via browser: `http://localhost/QuickMyDump/QuickMyImport.php`
2. Upload or select SQL file (.sql or .gz)
3. Configure database connection
4. Click "Start Import"
5. Progress tracked with automatic resume capability

### Resume Interrupted Operations

Export:
```bash
php QuickMyDump.php --resume --checkpoint=/tmp/backup.checkpoint
```

Import:
```bash
php QuickMyImport.php --resume --checkpoint=/tmp/import.checkpoint
```

## Export Options

### Connection

| Parameter | Description | Default |
|-----------|-------------|---------|
| --host | MySQL hostname | 127.0.0.1 |
| --port | MySQL port | 3306 |
| --user | Database user | root |
| --pass | Password | empty |
| --db | Database name | required |

### Output

| Parameter | Description | Default |
|-----------|-------------|---------|
| --outfile | Output file path | stdout |
| --gzip | Enable gzip compression | false |

### Export Modes

| Parameter | Description | Default |
|-----------|-------------|---------|
| --schema-only | Export only structure | false |
| --data-only | Export only data | false |
| --extended-insert | Multi-row INSERTs | true |
| --max-rows | Rows per chunk | 1000 |
| --hex-blob | HEX encode binary | false |
| --single-transaction | InnoDB snapshot | false |

### Table Selection

| Parameter | Description |
|-----------|-------------|
| --tables | Specific tables (comma-separated) |
| --exclude | Exclude patterns (comma-separated) |
| --routines | Export procedures/functions |
| --triggers | Export triggers |
| --views | Export views |
| --drop-table | Add DROP TABLE statements |

### Resume & Logging

| Parameter | Description |
|-----------|-------------|
| --checkpoint | Checkpoint file path |
| --resume | Resume from checkpoint |
| --verbose | Detailed output |
| --logfile | Log file path |
| --config | JSON config file |
| --profile | Config profile name |

## Import Options

| Parameter | Description | Default |
|-----------|-------------|---------|
| --file | SQL file to import | required |
| --batch-size | Statements per commit | 100 |
| --stop-on-error | Stop on first error | false |
| --ignore-table-exists | Ignore table exists errors | false |

## Examples

### Basic Export with Compression

```bash
php QuickMyDump.php \
    --db=production \
    --outfile=prod_backup.sql.gz \
    --gzip \
    --single-transaction \
    --verbose
```

### Export Schema Only

```bash
php QuickMyDump.php --db=mydb --schema-only --outfile=schema.sql
```

### Export Specific Tables

```bash
php QuickMyDump.php \
    --db=mydb \
    --tables=users,orders,products \
    --outfile=critical.sql.gz \
    --gzip
```

### Exclude Cache Tables

```bash
php QuickMyDump.php \
    --db=mydb \
    --exclude=cache_%,tmp_%,sessions \
    --outfile=backup.sql.gz \
    --gzip
```

### Large Database with Resume

```bash
php QuickMyDump.php \
    --db=largedb \
    --outfile=large.sql.gz \
    --gzip \
    --max-rows=5000 \
    --single-transaction \
    --hex-blob \
    --checkpoint=/tmp/large.checkpoint \
    --logfile=/var/log/backup.log \
    --verbose
```

### Import with Resume Support

```bash
php QuickMyImport.php \
    --db=mydb \
    --file=backup.sql.gz \
    --checkpoint=/tmp/import.checkpoint \
    --batch-size=200 \
    --ignore-table-exists \
    --verbose
```

### Using Configuration File

```bash
# Create config from example
cp config.example.json config.json

# Edit config.json with your settings

# Use profile
php QuickMyDump.php --config=config.json --profile=production
```

## Exclude Patterns

### Prefix Match
```bash
--exclude=cache_,tmp_,test_
```
Excludes tables starting with these prefixes

### Suffix Match
```bash
--exclude=%_old,%_backup
```
Excludes tables ending with these suffixes

### Wildcard Match
```bash
--exclude=*temp*,*session*
```
Excludes tables containing these strings

## Resume Capability

QuickMyDump automatically saves progress to checkpoint files. If interrupted:

1. The checkpoint tracks completed tables and current position
2. Use `--resume` to continue from last checkpoint
3. Checkpoint auto-deletes on successful completion

### Export Resume
```bash
# Start export
php QuickMyDump.php --db=bigdb --outfile=big.sql.gz --gzip --checkpoint=/tmp/big.checkpoint

# If interrupted, resume:
php QuickMyDump.php --resume --checkpoint=/tmp/big.checkpoint
```

### Import Resume
```bash
# Start import  
php QuickMyImport.php --db=mydb --file=big.sql.gz --checkpoint=/tmp/import.checkpoint

# If failed, resume:
php QuickMyImport.php --resume --checkpoint=/tmp/import.checkpoint
```

## Configuration File

Create `config.json` for multiple database profiles:

```json
{
  "profiles": {
    "production": {
      "host": "prod.example.com",
      "user": "backup_user",
      "pass": "password",
      "db": "production_db",
      "outfile": "/backups/prod.sql.gz",
      "gzip": true,
      "exclude": "cache_%,sessions",
      "single-transaction": true,
      "hex-blob": true
    },
    "development": {
      "host": "localhost",
      "user": "dev",
      "db": "dev_db",
      "outfile": "/tmp/dev.sql"
    }
  }
}
```

See `config.example.json` for more examples.

## Performance Tips

### Large Databases (100GB+)
- Use `--gzip` to reduce file size
- Increase `--max-rows=10000` for better speed
- Enable `--single-transaction` for consistency
- Use `--checkpoint` for resume capability
- Use `--hex-blob` for binary data

### Optimize Chunk Size
- Small rows (<1KB): `--max-rows=10000`
- Medium rows (1-10KB): `--max-rows=5000`  
- Large rows (>10KB): `--max-rows=100`

### Faster Imports
- Increase `--batch-size=500`
- Use checkpoints for safety
- Use `--ignore-table-exists` when needed

## Automated Backups

Create backup script `/usr/local/bin/daily-backup.sh`:

```bash
#!/bin/bash
DATE=$(date +%Y%m%d)
BACKUP_DIR="/backups/mysql"

php /usr/local/bin/QuickMyDump.php \
    --db=production \
    --outfile="${BACKUP_DIR}/prod_${DATE}.sql.gz" \
    --gzip \
    --single-transaction \
    --hex-blob \
    --checkpoint="/tmp/prod_backup.checkpoint" \
    --logfile="${BACKUP_DIR}/backup_${DATE}.log"

# Keep last 7 days
find ${BACKUP_DIR} -name "prod_*.sql.gz" -mtime +7 -delete
```

Add to crontab:
```bash
0 2 * * * /usr/local/bin/daily-backup.sh
```

## Web Interface (Import Only)

QuickMyImport includes a modern web interface with session-based processing and automatic resume capability.

### Features

- File upload support (.sql and .gz files)
- Real-time progress tracking
- Session-based processing (no timeout issues)
- Automatic resume on connection loss
- Statistics dashboard
- Visual progress bar
- Error tracking

### Setup

1. Copy QuickMyImport.php to your web directory:
```bash
cp QuickMyImport.php /var/www/html/
```

2. Create uploads directory with write permissions:
```bash
mkdir /var/www/html/uploads
chmod 755 /var/www/html/uploads
```

3. Configure PHP settings in php.ini:
```ini
upload_max_filesize = 2G
post_max_size = 2G
max_execution_time = 300
memory_limit = 512M
```

### Usage

1. Access via browser: `http://localhost/QuickMyImport.php`
2. Upload SQL file or select existing file from:
   - Uploads directory (files uploaded via web interface)
   - Main directory (files in same folder as QuickMyImport.php)
3. Configure database connection (host, user, password, database)
4. Adjust batch settings (optional)
5. Click "Start Import"
6. Progress updates automatically
7. Import can be stopped and resumed

### Configuration

- **Batch Size**: Statements per transaction commit (default: 100)
- **Statements per Execution**: Statements processed per HTTP request (default: 300)
- Higher values = faster but more memory usage

### Upload Directory

Default locations for SQL files:
- **Uploads**: `./uploads/` (files uploaded via web interface)
- **Main Directory**: Same folder as QuickMyImport.php (useful for files created by QuickMyDump.php)

Files from both locations are automatically detected and displayed in the web interface with location badges.

Custom upload directory:
```php
// Edit QuickMyImport.php line ~580
$uploadDir = '/path/to/custom/uploads';
```

### Security Recommendations

1. Add HTTP authentication (.htaccess):
```apache
AuthType Basic
AuthName "Restricted Area"
AuthUserFile /path/to/.htpasswd
Require valid-user
```

2. Restrict by IP:
```apache
Order Deny,Allow
Deny from all
Allow from 192.168.1.0/24
```

3. Remove after use:
```bash
rm QuickMyImport.php
```

### Limitations

- Designed for databases up to 10GB
- Larger files work but take longer
- For 50GB+ databases, use CLI mode instead

## Troubleshooting

### Connection Failed
- Verify MySQL credentials
- Check PDO_MYSQL or mysqli extension
- Confirm MySQL server is running
- Check firewall rules (port 3306)

### Cannot Write File
- Check directory permissions
- Verify disk space
- Ensure PHP has write access

### Out of Memory
- Reduce `--max-rows` value
- Use CLI instead of web
- Check memory_limit in php.ini

### Table Already Exists (Import)
- Use `--ignore-table-exists`
- Drop tables manually first
- Check you're importing to correct database

### Resume Not Working
- Verify checkpoint file path is writable
- Check checkpoint file exists
- Ensure correct checkpoint path specified

## Security

1. Never expose web interface without authentication
2. Use environment variables for passwords:
   ```bash
   export MYSQL_PASSWORD="secret"
   php QuickMyDump.php --pass="${MYSQL_PASSWORD}"
   ```
3. Secure backup files:
   ```bash
   chmod 600 /backups/*.sql.gz
   ```
4. Secure config files:
   ```bash
   chmod 600 config.json
   ```
5. Delete backups after transfer
6. Use SSL/TLS for MySQL connections

## Advantages Over mysqldump

- Pure PHP (no mysqldump binary needed)
- Resume capability for failed operations
- Better memory efficiency
- Flexible table filtering
- Built-in web interface
- Single-file deployment
- Configuration file support
- Progress tracking
- Checkpoint recovery

## Limitations

- Does not support incremental backups
- Binary data may need `--hex-blob` flag
- Web interface not suitable for large databases
- One database per operation

## Files

- `QuickMyDump.php` - Export tool
- `QuickMyImport.php` - Import tool  
- `README.md` - This documentation
- `config.example.json` - Configuration examples
- `SUGGESTIONS.txt` - Development roadmap

## Contributing

Contributions welcome! Submit issues and pull requests on GitHub.

Future features:
- Parallel table processing
- Remote storage (S3, FTP)
- Email notifications
- Encryption support
- Alternative compression formats

## License

Open source - see repository for license details.

## Author

ProgrammerNomad

## Repository

https://github.com/ProgrammerNomad/QuickMyDump

## Support

For issues or questions, open an issue on GitHub.
