monolog.KW-PDO-Handler
======================

PDO Handler for Monolog, which allows to store log messages in a MySQL Database via PDO handler.

Handler can log text messages to a specific table and creates the table automatically if it does not exist.

# Installation
`karelwintersky/monolog-pdo-handler` is available via composer.

```
composer require karelwintersky/monolog-pdo-handler
```

Minimum PHP version is 7.1

# Usage

Just use it as any other Monolog Handler, push it to the stack of your Monolog Logger instance.
The Handler however needs some parameters:

- `$pdo` - PDO Instance of your database. Pass along the PDO instantiation of your database connection with your database selected.
- `$table` - The table name where the logs should be stored.
- `$additional_fields` - associative array of additional database fields definitions. All additional columns are created
automatically and the fields can later be used in the extra context section of a record. See examples below. _Default is empty array_.
- `$additional_indexes` - associative array of additional database indexes definitions. _Default is empty array_
- `$level` - The minimum logging level at which this handler will be triggered. Must be any of standard Monolog logging levels (default: Logger::DEBUG)

# Default fields at logging table

- `id` - defined as `BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY`;
- `ipv4` - defined as `int(10) unsigned DEFAULT NULL`, will contain client IPv4 or 127.0.0.1 for console scripts;
- `time` - defined as `TIMESTAMP`, will contain current timestamp;
- `level` - defined as `SMALLINT`, logging level;
- `channel` - defined as `VARCHAR(64)`, channel name,
- `message` - defined as `LONGTEXT`, message

# Examples

Given that $pdo is your database instance, you could use the class as follows:

```php
//Import class
use Monolog\Logger;
use KarelWintersky\Monolog\KWPDOHandler;

// Create log handler
// using table `log` with additional fields
// `filename`, `filesize`, `filetime`
// and index at field `filename`
// minimum logging level is INFO.

$log_handler = new KWPDOHandler($pdo_handler, 'log', [
    'filename'  =>  'VARCHAR(32)',
    'filesize'  =>  'BIGINT(20) DEFAULT NULL',
    'filetime'  =>  'DATETIME'
], [
    'filename'  =>  'CREATE INDEX filename on `%s` (`filename`) USING HASH',
], Logger::INFO);

// Create logger
$monologger = new \Monolog\Logger($monolog_channel);

// Set handler
$monologger->pushHandler($log_handler);

// Now you can use the logger, and further attach additional information
$monologger->notice("File information", [
    'filename'  =>  $data['filename'],
    'filesize'  =>  $data['filesize'],
    'filetime'  =>  $data['filetime']
]);

```
Note: SQLite does not support 'USING method' for indexes;

# ToDo

- [ ] Check and override default field definitions
- [ ] Update readme : how to write custom indexes
- [ ] Update readme : about SQLite.
- [ ] Implement default indexes for PostgreSQL


# License

This tool is free software and is distributed under the MIT license. Please have a look at the LICENSE file for further information.
