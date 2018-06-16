<?php
/**
 * User: Karel Wintersky
 * Date: 07.12.2017, time: 6:47
 * Date: 15.06.2018, time: 20:30
 */
namespace KarelWintersky\Monolog;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use PDO;
use PDOStatement;
use Symfony\Component\VarDumper;

/**
 *
 * Class KWPDOHandler
 * @package KarelWintersky\Monolog
 */
class KWPDOHandler extends AbstractProcessingHandler {
    const VERSION = '0.1.12';

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
            'ipv4'      =>  'INT(10) UNSIGNED DEFAULT NULL',
            'time'      =>  'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', //@todo: check availability
            'level'     =>  'SMALLINT DEFAULT NULL',
            'channel'   =>  'VARCHAR(64) DEFAULT NULL',
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
            'channel'   =>  "CREATE INDEX channel on `%s` (`channel`)",
            'level'     =>  "CREATE INDEX level on `%s` (`level`) USING B-tree",
            'time'      =>  "CREATE INDEX time on  `%s` (`time`) USING B-tree", // ?
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
     * @var array additional fields
     */
    protected $define_additional_fields = array();

    /**
     * @var array additional indexes
     */
    protected $define_additional_indexes = array();

    /*
     * ===============================  Methods ========================
     */

    /**
     * Get real IPv4 address
     * @return string
     */
    private function getIP() {
        if (getenv('HTTP_CLIENT_IP')) {
            $ipAddress = getenv('HTTP_CLIENT_IP');
        } elseif (getenv('HTTP_X_FORWARDED_FOR')) {
            $ipAddress = getenv('HTTP_X_FORWARDED_FOR');
        } elseif (getenv('HTTP_X_FORWARDED')) {
            $ipAddress = getenv('HTTP_X_FORWARDED');
        } elseif (getenv('HTTP_FORWARDED_FOR')) {
            $ipAddress = getenv('HTTP_FORWARDED_FOR');
        } elseif (getenv('HTTP_FORWARDED')) {
            $ipAddress = getenv('HTTP_FORWARDED');
        } elseif (getenv('REMOTE_ADDR')) {
            $ipAddress = getenv('REMOTE_ADDR');
        } else {
            $ipAddress = '127.0.0.1';
        }

        return $ipAddress;
    }

    /**
     * Constructor of this class, sets the PDO, table,
     * additional field, additional indexes,
     * minimim logging level and calls parent constructor
     *
     * @param PDO|null $pdo
     * @param string $table - table for store data
     * @param array $additional_fields - additional fields definition like "['lat'   =>  'DECIMAL(9,6) DEFAULT NULL']"
     * @param array $additional_indexes - additional indexes definition like "['lat'   =>  'INDEX(`lat`) USING BTREE']"
     * @param int $level - minimum logging level (Logger constant)
     * @param bool|true $bubbling
     * @return KWPDOHandler
     */
    public function __construct (\PDO $pdo = null, $table = 'log', $additional_fields = array(),
                                 $additional_indexes = array(), $level = Logger::DEBUG,
                                 $bubbling = true)
    {
        if (!($pdo instanceof \PDO)) {
            dd(__METHOD__ . ' > throws critical exception: no PDO connection given');
        }

        $this->pdo = $pdo;
        $this->pdo_driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $this->table = $table;

        $this->define_additional_fields = $additional_fields;
        $this->define_additional_indexes = $additional_indexes;

        $this->additionalFields = $additional_fields;

        parent::__construct($level, $bubbling);
    }

    /**
     * Return CREATE TABLE definition
     *
     * @return string
     */
    private function prepare_table_definition() {
        $fields = $this->define_default_fields[ $this->pdo_driver ];

        if (!empty($this->define_additional_fields)) {
            $fields = array_merge($fields, $this->define_additional_fields);
        }

        $fields_str = join(', ', array_map(function($key, $value) {
            return "`{$key}` {$value}";
        }, array_keys($fields), $fields));

        $query_table_initialization
            = "CREATE TABLE IF NOT EXISTS `{$this->table}` ( {$fields_str} ) ";

        $query_table_initialization .= $this->define_table_engine[ $this->pdo_driver ];
        $query_table_initialization .= $this->define_table_charset[ $this->pdo_driver ];

        return $query_table_initialization;
    }

    /**
     * Return CREATE INDEX definitions
     *
     * @return string
     */
    private function prepare_table_indexes()
    {
        $indexes = $this->define_default_create_indexes[ $this->pdo_driver ];

        if (!empty($this->define_additional_indexes)) {
            $indexes = array_merge($indexes, $this->define_additional_indexes);
        }

        $indexes_str = '';

        foreach ($indexes as $index_name => $index_def) {

            $state = $this->pdo->query("SHOW INDEX FROM {$this->table} WHERE key_name = '{$index_name}'; ");
            $v = $state->fetchColumn();

            if ($v == false) {
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
    private function prepare_pdo_statement() {
        $fields = $this->define_default_fields[ $this->pdo_driver ];

        if (!empty($this->define_additional_fields)) {
            $fields = array_merge($fields, $this->define_additional_fields);
        }

        $insert_keys = [];
        $insert_values = [];

        foreach ($fields as $f_key => $f_value) {
            if ($f_key == 'id' || $f_key == 'time') {
                continue;
            } else {
                $insert_keys[] = "`{$f_key}`";
                $insert_values[] = ":{$f_key}";
            }
        }

        $query_prepared_statement =
            "INSERT INTO `{$this->table}` (" . join(', ', $insert_keys) . ") VALUES (" . join(', ', $insert_values) . ")";

        return $query_prepared_statement;
    }

    /**
     * Initializes this handler.
     *
     * Creating the table if it not exists.
     * Creating indexes that not exists.
     * Prepare the sql statment depending on the fields that should be written to the database
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
     * @param array $record
     * @return void
     */
    protected function write(array $record)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $insert_array = [
            'ipv4'      =>  ip2long($this->getIP()),
            'level'     =>  $record['level'],
            'message'   =>  $record['message'],
            'channel'   =>  $record['channel']
        ];

        foreach ($this->define_additional_fields as $f_key=>$f_value) {
            if (array_key_exists($f_key, $record['context'])) {
                $insert_array[ $f_key ] = $record['context'][ $f_key ];
            }
        }

        try {
            if (!$this->pdo)
                throw new \Exception('PDO Connection is not established', -1);

            if (!$this->statement)
                throw new \Exception('PDO Statement not prepared', -2);

            $this->statement->execute($insert_array);

        } catch (\PDOException $e) {
            dump($e->getCode(), $e->getMessage());
        } catch (\Exception $e) {
            dump($e->getCode(), $e->getMessage());
        }

    }



}
 
