<?php
/**
 * User: Arris
 * Date: 07.12.2017, time: 6:47
 */
namespace KWPDOHandler;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use PDO;
use PDOStatement;

/**
 * Class KWPDOHandler
 * @package KWPDOHandler
 */
class KWPDOHandler extends AbstractProcessingHandler {

    /**
     * @var bool defines whether the PDO connection is been initialized
     */
    private $initialized = false;

    /**
     * @var PDO pdo object of database connection
     */
    protected $pdo;

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

    /**
     * @var string default table definition
     */
    private $define_default_table = 'ENGINE=MyISAM DEFAULT CHARSET=utf8';

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
    private function getRealIP()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } else { // possible cli launched
            $ip = '127.0.0.1';
        }
        return $ip;
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
        if (!is_null($pdo)) {
            $this->pdo = $pdo;
        }
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
            "CREATE TABLE IF NOT EXISTS `{$this->table}`" .
            " ($fields_str, {$indexes_str}) {$this->define_default_table};";

        $insert_keys = [];
        $insert_values = [];

        foreach ($fields as $f_key => $f_value) {
            if ($f_key == 'id' || $f_key == 'time') {
                continue;
            } elseif ($f_key == 'ipv4') {
                $insert_keys[] = "`{$f_key}`";
                $insert_values[] = "INET_ATON(:{$f_key})";
            } else {
                $insert_keys[] = "`{$f_key}`";
                $insert_values[] = ":{$f_key}";
            }
        }

        $query_prepared_statement = "INSERT INTO {$this->table} (" .
            join(', ', $insert_keys) .
            ") VALUES (" .
            join(', ', $insert_values) .
            ");";

        if ($this->pdo instanceof \PDO) {
            // init
            $this->pdo->exec($query_table_initialization);

            // prepare statement
            $this->statement = $this->pdo->prepare( $query_prepared_statement );

            // set flag
            $this->initialized = true;
        } else {
            var_dump( $query_table_initialization );
            var_dump( $query_prepared_statement );
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
            'ipv4'      =>  $this->getRealIP(),
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
            if ($this->pdo instanceof \PDO) {
                $this->statement->execute($insert_array);
            } else {
                throw new \Exception('PDO Connection is not established', -1);
            }
        } catch (\PDOException $e) {
            var_dump($e->getCode(), $e->getMessage());
        } catch (\Exception $e) {
            var_dump($e->getCode(), $e->getMessage());
        }

    }

}
 
