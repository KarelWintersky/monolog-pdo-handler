<?php
/**
 * User: Karel Wintersky
 * Date: 07.12.2017, time: 6:47
 * Date: 15.06.2018, time: 20:30
 * Date: 29.10.2022, time: 21:42 GMT+3
 */
namespace KarelWintersky\Monolog;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 *
 * Class KWPDOHandler
 * @package KarelWintersky\Monolog
 */
class KWPDOHandler extends AbstractProcessingHandler
{
    /**
     * @var bool defines whether the PDO connection is been initialized
     */
    private $initialized = FALSE;

    /**
     * @var PDO pdo object of database connection
     */
    protected $pdo = NULL;

    /**
     * @var string $driver PDO driver
     */
    protected $pdo_driver;

    /**
     * @var PDOStatement statement to insert a new record
     */
    private $statement = FALSE;

    /**
     * @var string the table to store the logs in
     */
    private $table = 'logs';

    /**
     * @var boolean
     */
    private $include_ipv4 = FALSE;

    /* ============= */

    /**
     * @var array default fields that are stored in db
     */
    private $define_default_fields = [
        'mysql' =>  [
            'id'        =>  'BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'ipv4'      =>  'INT(10) UNSIGNED DEFAULT NULL',
            'time'      =>  'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'level'     =>  'SMALLINT(6) DEFAULT NULL',
            'channel'   =>  'VARCHAR(64) DEFAULT NULL',
            'message'   =>  'LONGTEXT'
        ],
        'sqlite'    =>  [
            'id'        =>  'INTEGER PRIMARY KEY ASC',
            'ipv4'      =>  'UNSIGNED INT(10) DEFAULT NULL',
            'time'      =>  'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'level'     =>  'SMALLINT',
            'channel'   =>  'VARCHAR(64)',
            'message'   =>  'LONGTEXT'
        ],
        'pgsql'     =>  [
            'id'        =>  'BIGSERIAL PRIMARY KEY',
            'ipv4'      =>  'BIGINT DEFAULT NULL',
            'time'      =>  'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'level'     =>  'SMALLINT DEFAULT NULL',
            'channel'   =>  'TEXT DEFAULT NULL',
            'message'   =>  'TEXT'
        ]
    ];

    /**
     * default indexes
     * @var array
     */
    private $define_default_create_indexes = [
        'mysql'     =>  [
            'channel'   =>  "CREATE INDEX channel on `%s` (`channel`) USING HASH",
            'level'     =>  "CREATE INDEX level on `%s` (`level`) USING HASH",
            'time'      =>  "CREATE INDEX time on  `%s` (`time`) USING BTREE",
        ],
        'sqlite'    =>  [
            //see https://www.sqlite.org/lang_createindex.html
            'channel'   =>  "CREATE INDEX 'channel' on %s ('channel')",
            'level'     =>  "CREATE INDEX 'level' on %s ('level')",
            'time'      =>  "CREATE INDEX 'time' on %s ('time')",
        ],
        'pgsql'     =>  [
            'channel'   =>  "CREATE INDEX IF NOT EXISTS channel_idx on \"%s\" (\"channel\")",
            'level'     =>  "CREATE INDEX IF NOT EXISTS level on \"%s\" (\"level\")",
            'time'      =>  "CREATE INDEX IF NOT EXISTS time on \"%s\" (\"time\")", // ?
            //@todo: https://www.postgresql.org/docs/9.1/static/indexes-types.html
        ]
    ];

    /**
     * default table types
     *
     * @var array
     */
    private $define_table_engine = [
        'mysql'     =>  ' ENGINE=MyISAM ',
        'sqlite'    =>  '',
        'pgsql'     =>  ''
    ];

    /**
     * default charsets
     *
     * @var array
     */
    private $define_table_charset = [
        'mysql'     =>  '  DEFAULT CHARSET=utf8 ',
        'sqlite'    =>  '',
        'pgsql'     =>  ''
    ];

    /**
     * default quotation around table names and field names
     *
     * @var array
     */
    private $define_quote = [
        'mysql'     =>  '`',
        'sqlite'    =>  '"',
        'pgsql'     =>  '"'
    ];

    /**
     * @var array additional fields
     */
    protected $define_additional_fields = array();

    /**
     * @var array additional indexes
     */
    protected $define_additional_indexes = array();

    /**
     * @var array
     */
    private $additionalFields = [];

    /**
     * Get real IPv4 address
     * @return string
     */
    private static function getIp():string
    {
        if (getenv('REMOTE_ADDR')) {
            $ipAddress = getenv('REMOTE_ADDR');
        } elseif (getenv('HTTP_CLIENT_IP')) {
            $ipAddress = getenv('HTTP_CLIENT_IP');
        } elseif (getenv('HTTP_X_FORWARDED_FOR')) {
            $ipAddress = getenv('HTTP_X_FORWARDED_FOR');
        } elseif (getenv('HTTP_X_FORWARDED')) {
            $ipAddress = getenv('HTTP_X_FORWARDED');
        } elseif (getenv('HTTP_FORWARDED_FOR')) {
            $ipAddress = getenv('HTTP_FORWARDED_FOR');
        } elseif (getenv('HTTP_FORWARDED')) {
            $ipAddress = getenv('HTTP_FORWARDED');
        } else {
            $ipAddress = '127.0.0.1';
        }

        return explode(',', $ipAddress)[0];
    }

    /**
     * Constructor of this class, sets the PDO, table,
     * additional field, additional indexes,
     * minimum logging level and calls parent constructor
     *
     * @param PDO|null $pdo
     * @param string $table - table for store data
     * @param array $additional_fields - additional fields definition like "['lat'   =>  'DECIMAL(9,6) DEFAULT NULL']"
     * @param array $additional_indexes - additional indexes definition like "['lat'   =>  'INDEX(`lat`) USING BTREE']"
     * @param int|string|Level $level - minimum logging level (Logger constant)
     * @param bool|true $bubbling
     * @param bool|false $include_ipv4
     * @return void
     */
    public function __construct ($pdo = null, $table = 'logs',
                                 array $additional_fields = [],
                                 array $additional_indexes = [],
                                 $level = Logger::DEBUG,
                                 bool $bubbling = true, $include_ipv4 = false)
    {
        if (!($pdo instanceof PDO)) {
            throw new \RuntimeException("KarelWintersky\Monolog\KWPDOHandler reporting: no PDO connection given");
        }

        $this->pdo = $pdo;
        $this->pdo_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->table = $table;

        $this->include_ipv4 = $include_ipv4;

        $this->additionalFields
            = $this->define_additional_fields
            = $additional_fields;

        $this->define_additional_indexes = $additional_indexes;

        parent::__construct($level, $bubbling);
    }

    /**
     * Return CREATE TABLE definition
     *
     * @return string
     */
    private function prepare_table_definition(): string
    {
        $fields = $this->define_default_fields[ $this->pdo_driver ];
        $quote = $this->define_quote[ $this->pdo_driver ];

        if (!empty($this->define_additional_fields)) {
            $fields = array_merge($fields, $this->define_additional_fields);
        }

        $fields_str = implode(', ', array_map(function($key, $value) use ($quote) {
            return "{$quote}{$key}{$quote} {$value}";
        }, array_keys($fields), $fields));

        $query_table_initialization
            = "CREATE TABLE IF NOT EXISTS {$quote}{$this->table}{$quote} ( {$fields_str} ) ";

        $query_table_initialization .= $this->define_table_engine[ $this->pdo_driver ];
        $query_table_initialization .= $this->define_table_charset[ $this->pdo_driver ];

        return $query_table_initialization;
    }

    /**
     * Return CREATE INDEX definitions
     *
     * @return string
     */
    private function prepare_table_indexes(): string
    {
        $indexes = $this->define_default_create_indexes[ $this->pdo_driver ];

        if (!empty($this->define_additional_indexes)) {
            $indexes = array_merge($indexes, $this->define_additional_indexes);
        }

        $indexes_str = '';

        foreach ($indexes as $index_name => $index_def) {

            switch($this->pdo_driver):
                case "pgsql":
                    // PostgreSQL doesn't have a simple way to check for indexes on a column, but the index check can be done in the $define_default_create_indexes
                    $v = false;
                    break;
                case "mysql":
                    $state = $this->pdo->query("SHOW INDEX FROM {$this->table} WHERE key_name = '{$index_name}'; ");
                    $v = $state->fetchColumn();
                    break;
            endswitch;

            if (!$v) {
                $indexes_str .= sprintf($index_def, $this->table) . ' ; ';
            }

        }
        return $indexes_str;
    }

    /**
     * Return PDO Prepared Statement definition
     *
     * @return string
     */
    private function prepare_pdo_statement(): string
    {
        $fields = $this->define_default_fields[ $this->pdo_driver ];
        $quote = $this->define_quote[ $this->pdo_driver ];

        if (!empty($this->define_additional_fields)) {
            $fields = array_merge($fields, $this->define_additional_fields);
        }

        $insert_keys = [];
        $insert_values = [];

        foreach ($fields as $f_key => $f_value) {
            if ($f_key == 'id' || $f_key == 'time') {
                continue;
            } else {
                $insert_keys[] = "{$quote}{$f_key}{$quote}";
                $insert_values[] = ":{$f_key}";
            }
        }

        return "INSERT INTO {$quote}{$this->table}{$quote} (" . join(', ', $insert_keys) . ") VALUES (" . join(', ', $insert_values) . ")";
    }

    /**
     * Initializes this handler.
     *
     * Creating the table if it not exists.
     * Creating indexes that not exists.
     * Prepare the sql statement depending on the fields that should be written to the database
     *
     * @return void
     */
    protected function initialize()
    {
        // init table
        $query_table_initialization = $this->prepare_table_definition();
        if ($query_table_initialization)
            $this->pdo->exec($query_table_initialization);

        // indexes
        $query_table_indexes = $this->prepare_table_indexes();
        if ($query_table_indexes)
            $this->pdo->exec($query_table_indexes);

        // prepare statement
        $query_prepared_statement = $this->prepare_pdo_statement();
        if ($query_prepared_statement)
            $this->statement = $this->pdo->prepare( $query_prepared_statement );

        // set flag
        $this->initialized = true;
    }

    /**
     * Writes the record down to the log
     *
     * @param array|LogRecord $record
     * @return void
     */
    protected function write($record):void
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $insert_array = [
            'ipv4'      =>  $this->include_ipv4 ? ip2long( self::getIP() ) : null,
            'level'     =>  $record['level'],
            'message'   =>  $record['message'],
            'channel'   =>  $record['channel']
        ];

        foreach ($this->define_additional_fields as $f_key=>$f_value) {
            if (array_key_exists($f_key, $record['context'])) {
                // encode certain additional fields as JSON
                if(in_array($f_value, ["jsonb", "json"])):
                    $insert_array[ $f_key ] = json_encode($record['context'][ $f_key ]);
                else:
                    $insert_array[ $f_key ] = $record['context'][ $f_key ];
                endif;
            }
        }

        if (!$this->pdo) {
            throw new RuntimeException('PDO Connection is not established', -1);
        }

        if (!$this->statement) {
            throw new \RuntimeException('PDO Statement not prepared', -2);
        }

        $this->statement->execute($insert_array);
    }
}
