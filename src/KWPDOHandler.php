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
    const VERSION = '0.1.4';

    /**
     * @var bool defines whether the PDO connection is been initialized
     */
    private $initialized = false;

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
    private $statement;

    /**
     * @var string the table to store the logs in
     */
    private $table = 'logs';

    /**
     * @var array default fields that are stored in db
     */
    private $define_default_fields = [
        'id'        =>  'BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'ipv4'      =>  'int(10) unsigned DEFAULT NULL',
        'time'      =>  'TIMESTAMP',
        'level'     =>  'SMALLINT',
        'channel'   =>  'VARCHAR(64)',
        'message'   =>  'LONGTEXT'
    ];

    /**
     * @var array default indexes that are stored in db
     */
    private $define_default_indexes = [
        'channel'   =>  'INDEX(`channel`) USING HASH',
        'level'     =>  'INDEX(`level`) USING HASH',
        'time'      =>  'INDEX(`time`) USING BTREE'
    ];

    private $define_default_indexes_spec = [
        'channel'   =>  '`channel` USING HASH ON %s (`channel`)',
        'level'     =>  '`level` USING HASH ON %s (`level`)',
        'time'      =>  '`time` USING BTREE ON %s (`time`)'
    ];

    /**
     * @var string default table definition
     */
    private $define_table_type = ' ENGINE=MyISAM ';

    /**
     * @var string default table charset
     */
    private $define_table_charset = ' DEFAULT CHARSET=utf8 ';

    /**
     * @var array additional fields
     */
    protected $define_additional_fields = array();

    /**
     * @var array additional indexes
     */
    protected $define_additional_indexes = array();

    /*
     * Methods
     */

    /**
     * Get real IPv4 address
     * @return string
     */
    private function getIP()
    {
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
        if ($pdo instanceof \PDO) {
            $this->pdo = $pdo;
        }
        $this->pdo_driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $this->table = $table;

        $this->define_additional_fields = $additional_fields;
        $this->define_additional_indexes = $additional_indexes;

        $this->additionalFields = $additional_fields;
        parent::__construct($level, $bubbling);
    }

    /**
     * Initializes this handler.
     * Creating the table if it not exists.
     * Prepare the sql statment depending on the fields that should be written to the database
     *
     * @return void
     */
    protected function initialize()
    {
        $fields = $this->define_default_fields;
        $indexes = $this->define_default_indexes;

        if (!empty($this->define_additional_fields)) {
            $fields = array_merge($fields, $this->define_additional_fields);
        }

        if (!empty($this->define_additional_indexes)) {
            $indexes = array_merge($indexes, $this->define_additional_indexes);
        }

        $fields_str = join(', ', array_map(function($key, $value) {
            return "`{$key}` {$value}";
        }, array_keys($fields), $fields));

        $indexes_str = join(', ', array_map(function($key, $value) {
            return "{$value}";
        }, array_keys($indexes), $indexes));

        $query_table_initialization =
            "CREATE TABLE IF NOT EXISTS `{$this->table}` " .
            " ($fields_str, {$indexes_str}) ";

        if ($this->pdo_driver == 'mysql')
            $query_table_initialization .= $this->define_table_type;

        $query_table_initialization .= $this->define_table_charset;

        $insert_keys = [];
        $insert_values = [];

        /*foreach ($fields as $f_key => $f_value) {
            if ($f_key == 'id' || $f_key == 'time') {
                continue;
            } elseif ($f_key == 'ipv4') {
                $insert_keys[] = "`{$f_key}`";
                $insert_values[] = "INET_ATON(:{$f_key})";
            } else {
                $insert_keys[] = "`{$f_key}`";
                $insert_values[] = ":{$f_key}";
            }
        }*/

        // SQLite/PgSQL does not supports INET_ATON() function, so we will use ip2long()
        foreach ($fields as $f_key => $f_value) {
            if ($f_key == 'id' || $f_key == 'time') {
                continue;
            } else {
                $insert_keys[] = "`{$f_key}`";
                $insert_values[] = ":{$f_key}";
            }
        }

        $query_prepared_statement = "INSERT INTO `{$this->table}` (" .
            join(', ', $insert_keys) .
            ") VALUES (" .
            join(', ', $insert_values) .
            ")";

        if ($this->pdo) {
            dump($query_table_initialization);
            dump($query_prepared_statement);
            dump($this->statement);

            // init
            $this->pdo->exec($query_table_initialization);

            // prepare statement
            $this->statement = $this->pdo->prepare( $query_prepared_statement );

            // set flag
            $this->initialized = true;
        } else {
            dump( $query_table_initialization );
            dump( $query_prepared_statement );
        }
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

        dump($insert_array);

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
 
