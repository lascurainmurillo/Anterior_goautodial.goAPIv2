<?php
####################################################
#### Name: MySQLiDB.php                         ####
#### Type: DB Class for Agent UI                ####
#### Version: 0.9                               ####
#### Copyright: GOAutoDial Inc. (c) 2011-2016   ####
#### Written by: Christopher P. Lomuntad        ####
#### License: AGPLv2                            ####
####################################################
//ini_set('display_errors', 'on');
//error_reporting(E_ALL);

class MySQLiDB {
    /**
     * Static instance of self
     *
     * @var MySQLiDB
     */
    protected static $_instance;

    /**
     * Table prefix
     * 
     * @var string
     */
    protected static $_prefix;

    /**
     * MySQLi instance
     *
     * @var mysqli
     */
    protected $_mysqli;

    /**
     * The SQL query to be prepared and executed
     *
     * @var string
     */
    protected $_query;

    /**
     * The previously executed SQL query
     *
     * @var string
     */
    protected $_lastQuery;

    /**
     * An array that holds where joins
     *
     * @var array
     */
    protected $_join = array();

    /**
     * An array that holds where conditions 'fieldname' => 'value'
     *
     * @var array
     */
    protected $_where = array();

    /**
     * Dynamic type list for order by condition value
     */
    protected $_orderBy = array();

    /**
     * Dynamic type list for group by condition value
     */
    protected $_groupBy = array();

    /**
     * Dynamic array that holds a combination of where condition/table data value types and parameter referances
     *
     * @var array
     */
    protected $_bindParams = array(''); // Create the empty 0 index

    /**
     * Variable which holds an amount of returned rows during get/getOne/select queries
     *
     * @var string
     */ 
    public $count = 0;

    /**
     * Variable which holds an amount of filtered rows during get/getOne/select queries, without applying the LIMIT clause.
	 */
	public $unlimitedCount = 0;

    /**
     * Variable which holds last statement error
     *
     * @var string
     */
    protected $_stmtError;

    /**
     * Database credentials
     *
     * @var string
     */
    protected $host;
    protected $username;
    protected $password;
    protected $db;
    protected $port;
    protected $charset;

    /**
     * Is Subquery object
     *
     */
    protected $isSubQuery = false;

    /**
     * An array that holds fetched fields
     *
     * @var array
     */
    protected $_fields = array();

    /**
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string $db
     * @param int $port
     */
    public function __construct($host = NULL, $username = NULL, $password = NULL, $db = NULL, $port = NULL, $charset = 'utf8') {
        $isSubQuery = false;

        // if params were passed as array
        if (is_array ($host)) {
            foreach ($host as $key => $val) {
                $$key = $val;
            }
        }
        // if host were set as mysqli socket
        if (is_object ($host)) {
            $this->_mysqli = $host;
        } else {
            $this->host = $host;
        }

        $this->username = $username;
        $this->password = $password;
        $this->db = $db;
        $this->port = $port;
        $this->charset = $charset;

        if ($isSubQuery) {
            $this->isSubQuery = true;
            return;
        }

        // for subqueries we do not need database connection and redefine root instance
        $connected = false;
        if (!is_object ($host)) {
            if ($this->connect() === true) { $connected = true; }
		}
		// check connection
		if ($connected === false) { throw new \Exception("Unable to connect to the database. Access denied or incorrect parameters."); }
		
        $this->setPrefix();
        self::$_instance = $this;
    }

    /**
     * A method to connect to the database
     *
     */
    public function connect() {
        if ($this->isSubQuery)
            return;

        if (empty ($this->host)) {
            die ('Mysql host is not set');
        }

        try {
	        @$this->_mysqli = new mysqli($this->host, $this->username, $this->password, $this->db, $this->port);
			if ($this->_mysqli->connect_errno) { return false; }
			if ($this->charset) $this->_mysqli->set_charset ($this->charset);
			return true;
        } catch (\Exception $e) {
	        return false;
        }
		return false;        
    }

    /**
     * A method of returning the static instance to allow access to the
     * instantiated object from within another class.
     * Inheriting this class would require reloading connection info.
     *
     * @uses $db = MySQLiDB::getInstance();
     *
     * @return object Returns the current instance.
     */
    public static function getInstance() {
        return self::$_instance;
    }

    /**
     * Reset states after an execution
     *
     * @return object Returns the current instance.
     */
    protected function reset() {
        $this->_where = array();
        $this->_join = array();
        $this->_orderBy = array();
        $this->_groupBy = array(); 
        $this->_bindParams = array(''); // Create the empty 0 index
        $this->_query = null;
        $this->count = 0;
        $this->unlimitedCount = 0;
    }
    
    /**
     * Method to set a prefix
     * 
     * @param string $prefix     Contains a tableprefix
     */
    public function setPrefix($prefix = '') {
        self::$_prefix = $prefix;
        return $this;
    }

	/**
	 * Gets the count of the rows obtained in the last query.
	 */
	public function getRowCount() { return $this->count; }

	/**
	 * Gets the count of rows that would have been obtained if the last query didn't have a LIMIT clause.
	 * This is useful for pagination in datatables.
	 */
	public function getUnlimitedRowCount() { return $this->unlimitedCount; }

	/**
	 * Gets the field names obtained in the last query.
	 */
	public function getFieldNames() { return $this->_fields; }

	/**
	 * Calculates the unlimited filtered results from a previous query that has applied SQL_CALC_FOUND_ROWS
	 * to the SELECT clause. Stores this value in the variable $unlimitedCount. This query is only executed
	 * if the user specifies $countFilteredResults = true in get/getOne/rawQuery.
	 */
	protected function calculateUnlimitedRowCount() {
		$this->_query = "SELECT FOUND_ROWS() AS total";
		$stmt = $this->_prepareQuery();
		if (empty($stmt)) { $this->reset(); return; }
        $stmt->execute();
        $this->reset();
        $this->_stmtError = $stmt->error;

		$results = $this->_dynamicBindResults($stmt);
		$this->unlimitedCount = $results["0"]["total"];
	}
	

    /**
     * Pass in a raw query and an array containing the parameters to bind to the prepaird statement.
     *
     * @param string $query      Contains a user-provided query.
     * @param array  $bindParams All variables to bind to the SQL statment.
     * @param bool   $sanitize   If query should be filtered before execution
     *
     * @return array Contains the returned rows from the query or NULL if an error happened.
     */
    public function rawQuery ($query, $bindParams = null, $sanitize = true, $countFilteredResults = false) {
        $this->_query = $query;
	    if ($countFilteredResults) { $this->_query = str_ireplace("SELECT", "SELECT SQL_CALC_FOUND_ROWS", $this->_query); }
        if ($sanitize) $this->_query = filter_var($query, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        $stmt = $this->_prepareQuery();
        if (empty($stmt)) { $this->reset(); return NULL; }

        if (is_array($bindParams) === true) {
            $params = array(''); // Create the empty 0 index
            foreach ($bindParams as $prop => $val) {
                $params[0] .= $this->_determineType($val);
                array_push($params, $bindParams[$prop]);
            }

            call_user_func_array(array($stmt, 'bind_param'), $this->refValues($params));
        }

        $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();

        $result = $this->_dynamicBindResults($stmt);
		// if $countFilteredResults is true, obtain the total filtered row count
		if ($countFilteredResults) $this->calculateUnlimitedRowCount();

		return $result;
    }

    /**
     *
     * @param string $query   Contains a user-provided select query.
     * @param int    $numRows The number of rows total to return.
     *
     * @return array Contains the returned rows from the query.
     */
    public function query($query, $numRows = null, $countFilteredResults = false) {
        $this->_query = filter_var($query, FILTER_SANITIZE_STRING);
	    if ($countFilteredResults) { $this->_query = str_ireplace("SELECT", "SELECT SQL_CALC_FOUND_ROWS", $this->_query); }
        $stmt = $this->_buildQuery($numRows);
        if (empty($stmt)) { $this->reset(); return NULL; }
        $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();

		// if $countFilteredResults is true, obtain the total filtered row count
		if ($countFilteredResults) $this->calculateUnlimitedRowCount();

        $result = $this->_dynamicBindResults($stmt);
		// if $countFilteredResults is true, obtain the total filtered row count
		if ($countFilteredResults) $this->calculateUnlimitedRowCount();

		return $result;
    }

    /**
     * A convenient SELECT * function. If $countFilteredResults is set, once 
     * the results are obtained, it performs a second mysql query to obtain
     * the total filtered row count (useful for datatables paging).
     *
     * @param string  $tableName The name of the database table to work with.
     * @param integer $numRows   The number of rows total to return.
     *
     * @return array Contains the returned rows from the select query.
     */
    public function get($tableName, $numRows = null, $columns = '*', $countFilteredResults = false) {
        if (empty($columns)) {
            $columns = '*';
        }

		$calcFoundRows = $countFilteredResults ? "SQL_CALC_FOUND_ROWS" : "";
        $column = is_array($columns) ? implode(', ', $columns) : $columns; 
        $this->_query = "SELECT $calcFoundRows $column FROM " . self::$_prefix . $tableName;
        $stmt = $this->_buildQuery($numRows);
        if (empty($stmt)) {
	        $this->reset(); 
	        return null; 
	    }

        if ($this->isSubQuery) { return $this; }

        $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();

        $result = $this->_dynamicBindResults($stmt);
		// if $countFilteredResults is true, obtain the total filtered row count
		if ($countFilteredResults) $this->calculateUnlimitedRowCount();
    
		return $result;
    }

    /**
     * A convenient SELECT * function to get one record.
     *
     * @param string  $tableName The name of the database table to work with.
     *
     * @return array Contains the returned rows from the select query.
     */
    public function getOne($tableName, $columns = '*', $countFilteredResults = false) {
        $res = $this->get($tableName, 1, $columns, $countFilteredResults);
        if (is_object($res)) {
            return $res;
        }

        if (isset($res[0])) {
            return $res[0];
        }

        return null;
    }

    /**
     * A convenient SELECT * function to get one value.
     *
     * @param string  $tableName The name of the database table to work with.
     *
     * @return array Contains the returned column from the select query.
     */
    public function getValue($tableName, $column, $countFilteredResults = false) {
        $res = $this->get($tableName, 1, "{$column} as retval", $countFilteredResults);

        if (isset($res[0]["retval"])) {
            return $res[0]["retval"];
        }

        return null;
    }

    /**
     *
     * @param <string $tableName The name of the table.
     * @param array $insertData Data containing information for inserting into the DB.
     *
     * @return boolean Boolean indicating whether the insert query was completed succesfully.
     */
    public function insert($tableName, $insertData) {
        if ($this->isSubQuery) {
            return;
        }

        $this->_query = "INSERT into " .self::$_prefix . $tableName;
        $stmt = $this->_buildQuery(null, $insertData);
        if (empty($stmt)) { $this->reset(); return null; }
        $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();
        $this->count = $stmt->affected_rows;

        if ($stmt->affected_rows < 1) {
            return false;
        }

        if ($stmt->insert_id > 0) {
            return $stmt->insert_id;
        }

        return true;
    }

    /**
     * A convenient function that returns TRUE if exists at least an element that
     * satisfy the where condition specified calling the "where" method before this one.
     *
     * @param string  $tableName The name of the database table to work with.
     *
     * @return array Contains the returned rows from the select query.
     */
    public function has($tableName) {
        $this->getOne($tableName, '1');
        return $this->count >= 1;
    }

    /**
     * Update query. Be sure to first call the "where" method.
     *
     * @param string $tableName The name of the database table to work with.
     * @param array  $tableData Array of data to update the desired row.
     *
     * @return boolean
     */
    public function update($tableName, $tableData, $numRows = null) {
        if ($this->isSubQuery) {
            return;
        }

        $this->_query = "UPDATE " . self::$_prefix . $tableName ." SET ";

        $stmt = $this->_buildQuery($numRows, $tableData);
        if (empty($stmt)) { $this->reset(); return false; }
        $status = $stmt->execute();
        $this->reset();
        $this->_stmtError = $stmt->error;
        $this->count = $stmt->affected_rows;

        return $status;
    }

    /**
     * Delete query. Call the "where" method first.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param integer $numRows   The number of rows to delete.
     *
     * @return boolean Indicates success. 0 or 1.
     */
    public function delete($tableName, $numRows = null) {
        if ($this->isSubQuery) {
            return;
        }

        $this->_query = "DELETE FROM " . self::$_prefix . $tableName;

        $stmt = $this->_buildQuery($numRows);
        if (empty($stmt)) { $this->reset(); return false; }
        $status = $stmt->execute();
        $this->reset();
        $this->_stmtError = $stmt->error;

        return $status;
    }


    /**
     * DROP table query. Use with caution.
     *
     * @param string  $tableName The name of the database table to drop.
     * @param boolean $cascade if true, drop table in CASCADE mode.
     * @return boolean Indicates success. 0 or 1.
     */
    public function dropTable($tableName, $cascade = false) {
        if ($this->isSubQuery) {
            return false;
        }

        $this->_query = "DROP TABLE IF EXISTS " . self::$_prefix . $tableName.($cascade ? " CASCADE" : "");

        $stmt = $this->_buildQuery();
        if (empty($stmt)) { $this->reset(); return false; }
        $result = $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();

        return $result;
    }

    /**
     * DROP column from table query. Use with caution.
     *
     * @param string  $tableName The name of the database table to drop the column from.
     * @param string  $columnName The name of the column to drop.
     *
     * @return boolean Indicates success. 0 or 1.
     */
    public function dropColumnFromTable($tableName, $columnName) {
        if ($this->isSubQuery) {
            return false;
        }

        $this->_query = "ALTER TABLE " . self::$_prefix . $tableName . " DROP COLUMN $columnName";

        $stmt = $this->_buildQuery();
        if (empty($stmt)) { $this->reset(); return false; }
        $result = $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();

        return $result;
    }
    
    /**
     * ALTER the column type from a table query. 
     *
     * @param string  $tableName The name of the database table to alter the column from.
     * @param string  $columnName The name of the column to alter its type.
     * @param string  $columnNewType The new type for the column.
     *
     * @return boolean Indicates success. 0 or 1.
     */
    public function alterColumnFromTable($tableName, $columnName, $columnNewType) {
        if ($this->isSubQuery) {
            return false;
        }

	    $this->_query = "ALTER TABLE " . self::$_prefix . $tableName . " MODIFY $columnName $columnNewType";

        $stmt = $this->_buildQuery();
        if (empty($stmt)) { $this->reset(); return false; }
        $result = $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();

        return $result;
    }

    /**
     * ADD column to table query. Use with caution.
     *
     * @param string  $tableName The name of the database table to drop the column from.
     * @param string  $columnName The name of the column to drop.
     *
     * @return boolean Indicates success. 0 or 1.
     */
    public function addColumnToTable($tableName, $columnName, $columnType, $defaultValue = null) {
        if ($this->isSubQuery) {
            return false;
        }

		$defaultString = empty($defaultValue) ? "" : "DEFAULT $defaultValue";
        $this->_query = "ALTER TABLE " . self::$_prefix . $tableName . " ADD COLUMN $columnName $columnType $defaultString";
        $stmt = $this->_buildQuery();
        if (empty($stmt)) { $this->reset(); return false; }
        $result = $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();

        return $result;
    }

	/**
	 * ALTER table, adding a unique field to the table.
	 *
     * @param string  $tableName The name of the database table to drop the column from.
     * @param string  $columnName The name of the column to drop.
     *
     * @return boolean Indicates success. 0 or 1.
	 */
    public function setColumnAsUnique($tableName, $columnName) {
        if ($this->isSubQuery) {
            return false;
        }

        $this->_query = "ALTER TABLE " . self::$_prefix . $tableName . " ADD UNIQUE ($columnName)";
        $stmt = $this->_buildQuery();
        if (empty($stmt)) { $this->reset(); return false; }
        $result = $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();

        return $result;
    }
	

	/**
	 * CREATE a table.
	 *
	 * @param string 	$tableName The name of the table to create.
	 * @param array		fields an associative array containing the names of the fields as keys and the data types as values. 
	 *        I.E: ["id" => "INT(11) AUTO_INCREMENT", "phone" => "VARCHAR(80)", "description" => "TEXT"...]
	 * @param array		$unique_keys an array containing the unique keys for the table as strings. I.E: ["passport_number", "name"]
	 *
	 * @return boolean Indicating success. 0 or 1.
	 */
	public function createTable($tableName, $fields, $unique_keys = null) {
		// safety checks
		if ($this->isSubQuery) {
            return false;
		}
		
		// build query
		$this->_query = "CREATE TABLE IF NOT EXISTS `$tableName` (";
		$this->_query .= "`id` int(11) NOT NULL AUTO_INCREMENT,";
		
		// add fields
		foreach ($fields as $key => $value) {
			$fieldName = $this->escape($key);
			$fieldType = $this->escape($value);
			$this->_query .= "\n`$fieldName` $fieldType,";
		}
		
		// add primary key
		$this->_query .= "\nPRIMARY KEY (`id`),";
		
		// add unique keys
		if (isset($unique_keys) && is_array($unique_keys)) {
			$unique_string = "";
			foreach ($unique_keys as $unique_key) { if (!empty($unique_key)) $unique_string .= "`$unique_key`,"; }
			$unique_string = rtrim($unique_string, " ,");
			if (!empty($unique_string) && strlen($unique_string) > 0) {
				$this->_query .= "\nKEY `Unique unique_name` ($unique_string),";
			}
		}
		
		// remove any final commas.
		$this->_query = rtrim($this->_query, ",\n");
		// finish query string.
		$this->_query .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1";
				
		// execute query and retrn the results.
        $stmt = $this->_prepareQuery();
        if (empty($stmt)) { $this->reset(); return false; }
        $result = $stmt->execute();
        $this->_stmtError = $stmt->error;
        $this->reset();
        
        return $result;
	}

	/**
	 * Drops an event from the database if it exists.
	 * @param String $eventName name of the event to drop.
	 * @return bool true if deletion succeed, false otherwise.
	 */
	public function dropEvent($eventName) {
        if ($this->isSubQuery) {
            return false;
        }

		$sanitizedEventName = filter_var($eventName, FILTER_SANITIZE_STRING);
        $this->_query = "DROP EVENT IF EXISTS $sanitizedEventName";
        $result = $this->_mysqli->query($this->_query);
        $this->reset();

        return $result;		
	}

    /**
     * This method allows you to specify multiple (method chaining optional) AND WHERE statements for SQL queries.
     *
     * @uses $MySQLiDB->where('id', 7)->where('title', 'MyTitle');
     *
     * @param string $whereProp  The name of the database field.
     * @param mixed  $whereValue The value of the database field.
     *
     * @return MySQLiDB
     */
    public function where($whereProp, $whereValue = null, $operator = null) {
        if ($operator) {
            $whereValue = Array($operator => $whereValue);
        }

        $this->_where[] = Array("AND", $whereValue, $whereProp);
        return $this;
    }

    /**
     * This method allows you to specify multiple (method chaining optional) OR WHERE statements for SQL queries.
     *
     * @uses $MySQLiDB->orWhere('id', 7)->orWhere('title', 'MyTitle');
     *
     * @param string $whereProp  The name of the database field.
     * @param mixed  $whereValue The value of the database field.
     *
     * @return MySQLiDB
     */
    public function orWhere($whereProp, $whereValue = null, $operator = null) {
        if ($operator) {
            $whereValue = Array($operator => $whereValue);
        }

        $this->_where[] = Array("OR", $whereValue, $whereProp);
        return $this;
    }
    /**
     * This method allows you to concatenate joins for the final SQL statement.
     *
     * @uses $MySQLiDB->join('table1', 'field1 <> field2', 'LEFT')
     *
     * @param string $joinTable The name of the table.
     * @param string $joinCondition the condition.
     * @param string $joinType 'LEFT', 'INNER' etc.
     *
     * @return MySQLiDB
     */
     public function join($joinTable, $joinCondition, $joinType = '') {
        $allowedTypes = array('LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER');
        $joinType = strtoupper(trim($joinType));

        if ($joinType && !in_array($joinType, $allowedTypes)) {
            die ('Wrong JOIN type: '.$joinType);
        }

        if (!is_object ($joinTable)) {
            $joinTable = self::$_prefix . filter_var($joinTable, FILTER_SANITIZE_STRING);
        }

        $this->_join[] = Array($joinType,  $joinTable, $joinCondition);

        return $this;
    }
    /**
     * This method allows you to specify multiple (method chaining optional) ORDER BY statements for SQL queries.
     *
     * @uses $MySQLiDB->orderBy('id', 'desc')->orderBy('name', 'desc');
     *
     * @param string $orderByField The name of the database field.
     * @param string $orderByDirection Order direction.
     *
     * @return MySQLiDB
     */
    public function orderBy($orderByField, $orderbyDirection = "DESC", $customFields = null) {
        $allowedDirection = Array("ASC", "DESC");
        $orderbyDirection = strtoupper(trim($orderbyDirection));
        $orderByField = preg_replace("/[^-a-z0-9\.\(\),_]+/i",'', $orderByField);

        if (empty($orderbyDirection) || !in_array($orderbyDirection, $allowedDirection)) {
            die ('Wrong order direction: '.$orderbyDirection);
        }

        if (is_array($customFields)) {
            foreach ($customFields as $key => $value)
                $customFields[$key] = preg_replace("/[^-a-z0-9\.\(\),_]+/i",'', $value);

            $orderByField = 'FIELD (' . $orderByField . ', "' . implode('","', $customFields) . '")';
        }

        $this->_orderBy[$orderByField] = $orderbyDirection;
        return $this;
    } 

    /**
     * This method allows you to specify multiple (method chaining optional) GROUP BY statements for SQL queries.
     *
     * @uses $MySQLiDB->groupBy('name');
     *
     * @param string $groupByField The name of the database field.
     *
     * @return MySQLiDB
     */
    public function groupBy($groupByField) {
        $groupByField = preg_replace("/[^-a-z0-9\.\(\),_]+/i",'', $groupByField);

        $this->_groupBy[] = $groupByField;
        return $this;
    } 

    /**
     * This methods returns the ID of the last inserted item
     *
     * @return integer The last inserted item ID.
     */
    public function getInsertId() {
        return $this->_mysqli->insert_id;
    }

    /**
     * Escape harmful characters which might affect a query.
     *
     * @param string $str The string to escape.
     *
     * @return string The escaped string.
     */
    public function escape($str) {
        return $this->_mysqli->real_escape_string($str);
    }

    /**
     * Method to call mysqli->ping() to keep unused connections open on
     * long-running scripts, or to reconnect timed out connections (if php.ini has
     * global mysqli.reconnect set to true). Can't do this directly using object
     * since _mysqli is protected.
     *
     * @return bool True if connection is up
     */
    public function ping() {
        return $this->_mysqli->ping();
    }

    /**
     * This method is needed for prepared statements. They require
     * the data type of the field to be bound with "i" s", etc.
     * This function takes the input, determines what type it is,
     * and then updates the param_type.
     *
     * @param mixed $item Input to determine the type.
     *
     * @return string The joined parameter types.
     */
    protected function _determineType($item) {
        switch (gettype($item)) {
            case 'NULL':
            case 'string':
                return 's';
                break;

            case 'boolean':
            case 'integer':
                return 'i';
                break;

            case 'blob':
                return 'b';
                break;

            case 'double':
                return 'd';
                break;
        }
        return '';
    }

    /**
     * Helper function to add variables into bind parameters array
     *
     * @param string Variable value
     */
    protected function _bindParam($value) {
        $this->_bindParams[0] .= $this->_determineType ($value);
        array_push ($this->_bindParams, $value);
    }

    /**
     * Helper function to add variables into bind parameters array in bulk
     *
     * @param Array Variable with values
     */
    protected function _bindParams ($values) {
        foreach ($values as $value) {
            $this->_bindParam($value);
        }
    }

    /**
     * Helper function to add variables into bind parameters array and will return
     * its SQL part of the query according to operator in ' $operator ?' or
     * ' $operator ($subquery) ' formats
     *
     * @param Array Variable with values
     */
    protected function _buildPair ($operator, $value) {
        if (!is_object($value)) {
            $this->_bindParam($value);
            return ' ' . $operator. ' ? ';
        }

        $subQuery = $value->getSubQuery ();
        $this->_bindParams($subQuery['params']);

        return " " . $operator . " (" . $subQuery['query'] . ") " . $subQuery['alias'];
    }

    /**
     * Abstraction method that will compile the WHERE statement,
     * any passed update data, and the desired rows.
     * It then builds the SQL query.
     *
     * @param int   $numRows   The number of rows total to return.
     * @param array $tableData Should contain an array of data for updating the database.
     *
     * @return mysqli_stmt Returns the $stmt object or NULL if an error happened.
     */
    protected function _buildQuery($numRows = null, $tableData = null) {
        $this->_buildJoin();
        $this->_buildTableData ($tableData);
        $this->_buildWhere();
        $this->_buildGroupBy();
        $this->_buildOrderBy();
        $this->_buildLimit ($numRows);

        $this->_lastQuery = $this->replacePlaceHolders ($this->_query, $this->_bindParams);

        if ($this->isSubQuery) {
            return;
        }

        // Prepare query
        $stmt = $this->_prepareQuery();
        if (empty($stmt)) { return NULL; }

        // Bind parameters to statement if any
        if (count ($this->_bindParams) > 1) {
            call_user_func_array(array($stmt, 'bind_param'), $this->refValues($this->_bindParams));
        }

        return $stmt;
    }

    /**
     * This helper method takes care of prepared statements' "bind_result method
     * , when the number of variables to pass is unknown.
     *
     * @param mysqli_stmt $stmt Equal to the prepared statement object.
     *
     * @return array The results of the SQL fetch.
     */
    protected function _dynamicBindResults(mysqli_stmt $stmt) {
        $parameters = array();
        $results = array();

        $meta = $stmt->result_metadata();

        // if $meta is false yet sqlstate is true, there's no sql error but the query is
        // most likely an update/insert/delete which doesn't produce any results
        if(!$meta && $stmt->sqlstate) { 
            return array();
        }

        $row = array();
		$fields = array();
        while ($field = $meta->fetch_field()) {
            $row[$field->name] = null;
            $parameters[] = & $row[$field->name];
			$fields[] = $field->name;
        }
		$this->_fields = $fields;

        // avoid out of memory bug in php 5.2 and 5.3
        //if (version_compare (phpversion(), '5.4', '<'))
        // NOTE: Always store results, because it seems that memory bug in php 5.2 and 5.3 has re-surfaced
        $stmt->store_result();

        call_user_func_array(array($stmt, 'bind_result'), $parameters);

        $this->count = 0;
        while ($stmt->fetch()) {
            $x = array();
            foreach ($row as $key => $val) {
                $x[$key] = $val;
            }
            $this->count++;
            array_push($results, $x);
        }

        return $results;
    }


    /**
     * Abstraction method that will build an JOIN part of the query
     */
    protected function _buildJoin () {
        if (empty ($this->_join)) {
            return;
        }

        foreach ($this->_join as $data) {
            list ($joinType,  $joinTable, $joinCondition) = $data;

            if (is_object($joinTable)) {
                $joinStr = $this->_buildPair("", $joinTable);
            } else {
                $joinStr = $joinTable;
            }

            $this->_query .= " " . $joinType. " JOIN " . $joinStr ." on " . $joinCondition;
        }
    }

    /**
     * Abstraction method that will build an INSERT or UPDATE part of the query
     */
    protected function _buildTableData ($tableData) {
        if (!is_array($tableData)) {
            return;
        }

        $isInsert = strpos($this->_query, 'INSERT');
        $isUpdate = strpos($this->_query, 'UPDATE');

        if ($isInsert !== false) {
            $this->_query .= '(`' . implode(array_keys($tableData), '`, `') . '`)';
            $this->_query .= ' VALUES(';
        }

        foreach ($tableData as $column => $value) {
            if ($isUpdate !== false) {
                $this->_query .= "`" . $column . "` = ";
            }

            // Subquery value
            if (is_object($value)) {
                $this->_query .= $this->_buildPair("", $value) . ", ";
                continue;
            }

            // Simple value
            if (!is_array($value)) {
                $this->_bindParam($value);
                $this->_query .= '?, ';
                continue;
            }

            // Function value
            $key = key($value);
            $val = $value[$key];
            switch ($key) {
                case '[I]':
                    $this->_query .= $column . $val . ", ";
                    break;
                case '[F]':
                    $this->_query .= $val[0] . ", ";
                    if (!empty ($val[1]))
                        $this->_bindParams($val[1]);
                    break;
                case '[N]':
                    if ($val == null)
                        $this->_query .= "!" . $column . ", ";
                    else
                        $this->_query .= "!" . $val . ", ";
                    break;
                default:
                    die ("Wrong operation");
            }
        }
        $this->_query = rtrim($this->_query, ', ');
        if ($isInsert !== false) {
            $this->_query .= ')';
        }
    }

    /**
     * Abstraction method that will build the part of the WHERE conditions
     */
    protected function _buildWhere () {
        if (empty($this->_where)) {
            return;
        }

        //Prepair the where portion of the query
        $this->_query .= ' WHERE ';

        // Remove first AND/OR concatenator
        $this->_where[0][0] = '';
        foreach ($this->_where as $cond) {
            list ($concat, $wValue, $wKey) = $cond;

            $this->_query .= " " . $concat ." " . $wKey;

            // Empty value (raw where condition in wKey)
            if ($wValue === null)
                continue;

            // Simple = comparison
            if (!is_array ($wValue))
                $wValue = Array('=' => $wValue);

            $key = key($wValue);
            $val = $wValue[$key];
            switch (strtolower ($key)) {
                case '0':
                    $this->_bindParams($wValue);
                    break;
                case 'not in':
                case 'in':
                    $comparison = ' ' . $key . ' (';
                    if (is_object ($val)) {
                        $comparison .= $this->_buildPair("", $val);
                    } else {
                        foreach ($val as $v) {
                            $comparison .= ' ?,';
                            $this->_bindParam($v);
                        }
                    }
                    $this->_query .= rtrim($comparison, ',').' ) ';
                    break;
                case 'not between':
                case 'between':
                    $this->_query .= " $key ? AND ? ";
                    $this->_bindParams($val);
                    break;
                case 'not exists':
                case 'exists':
                    $this->_query.= $key . $this->_buildPair ("", $val);
                    break;
                default:
                    $this->_query .= $this->_buildPair ($key, $val);
            }
        }
    }

    /**
     * Abstraction method that will build the GROUP BY part of the WHERE statement
     *
     */
    protected function _buildGroupBy () {
        if (empty ($this->_groupBy)) {
            return;
        }

        $this->_query .= " GROUP BY ";
        foreach ($this->_groupBy as $key => $value) {
            $this->_query .= $value . ", ";
        }

        $this->_query = rtrim($this->_query, ', ') . " ";
    }

    /**
     * Abstraction method that will build the LIMIT part of the WHERE statement
     *
     * @param int   $numRows   The number of rows total to return.
     */
    protected function _buildOrderBy () {
        if (empty ($this->_orderBy)) {
            return;
        }

        $this->_query .= " ORDER BY ";
        foreach ($this->_orderBy as $prop => $value) {
            if (strtolower(str_replace (" ", "", $prop)) == 'rand()') {
                $this->_query .= "rand(), ";
            } else {
                $this->_query .= $prop . " " . $value . ", ";
            }
        }

        $this->_query = rtrim($this->_query, ', ') . " ";
    }

    /**
     * Abstraction method that will build the LIMIT part of the WHERE statement
     *
     * @param int   $numRows   The number of rows total to return.
     */
    protected function _buildLimit ($numRows) {
        if (!isset ($numRows)) {
            return;
        }

        if (is_array ($numRows)) {
            $this->_query .= ' LIMIT ' . (int)$numRows[0] . ', ' . (int)$numRows[1];
        } else {
            $this->_query .= ' LIMIT ' . (int)$numRows;
        }
    }

    /**
     * Method attempts to prepare the SQL query
     * and throws an error if there was a problem.
     *
     * @return mysqli_stmt
     */
    protected function _prepareQuery() {
        if (!$stmt = $this->_mysqli->prepare($this->_query)) {
            //trigger_error("Problem preparing query ($this->_query) " . $this->_mysqli->error, E_USER_ERROR);
			return NULL;
        }
        return $stmt;
    }

    /**
     * Close connection
     */
    public function __destruct() {
        if (!$this->isSubQuery) {
            return;
        }
        if ($this->_mysqli) {
            $this->_mysqli->close();
        }
    }

    /**
     * @param array $arr
     *
     * @return array
     */
    protected function refValues($arr) {
        //Reference is required for PHP 5.3+
        if (strnatcmp(phpversion(), '5.3') >= 0) {
            $refs = array();
            foreach ($arr as $key => $value) {
                $refs[$key] = & $arr[$key];
            }
            return $refs;
        }
        return $arr;
    }

    /**
     * Function to replace ? with variables from bind variable
     * @param string $str
     * @param Array $vals
     *
     * @return string
     */
    protected function replacePlaceHolders ($str, $vals) {
        $i = 1;
        $newStr = "";

        while ($pos = strpos($str, "?")) {
            $val = $vals[$i++];
            if (is_object($val)) {
                $val = '[object]';
            }
            $newStr .= substr($str, 0, $pos) . $val;
            $str = substr($str, $pos + 1);
        }
        $newStr .= $str;
        return $newStr;
    }

    /**
     * Method returns last executed query
     *
     * @return string
     */
    public function getLastQuery () {
        return $this->_lastQuery;
    }

    /**
     * Method returns mysql error
     * 
     * @return string
     */
    public function getLastError () {
        return trim($this->_stmtError . " " . $this->_mysqli->error);
    }

    /**
     * Mostly internal method to get query and its params out of subquery object
     * after get() and getAll()
     * 
     * @return array
     */
    public function getSubQuery () {
        if (!$this->isSubQuery) {
            return null;
        }

        array_shift($this->_bindParams);
        $val = Array('query' => $this->_query,
                      'params' => $this->_bindParams,
                      'alias' => $this->host
                );
        $this->reset();
        return $val;
    }

    /* Helper functions */
    /**
     * Method returns generated interval function as a string
     *
     * @param string interval in the formats:
     *        "1", "-1d" or "- 1 day" -- For interval - 1 day
     *        Supported intervals [s]econd, [m]inute, [h]hour, [d]day, [M]onth, [Y]ear
     *        Default null;
     * @param string Initial date
     *
     * @return string
    */
    public function interval ($diff, $func = "NOW()") {
        $types = Array ("s" => "second", "m" => "minute", "h" => "hour", "d" => "day", "M" => "month", "Y" => "year");
        $incr = '+';
        $items = '';
        $type = 'd';

        if ($diff && preg_match('/([+-]?) ?([0-9]+) ?([a-zA-Z]?)/',$diff, $matches)) {
            if (!empty ($matches[1])) $incr = $matches[1];
            if (!empty ($matches[2])) $items = $matches[2];
            if (!empty ($matches[3])) $type = $matches[3];
            if (!in_array($type, array_keys($types)))
                trigger_error("invalid interval type in '{$diff}'");
            $func .= " ".$incr ." interval ". $items ." ".$types[$type] . " ";
        }
        return $func;

    }
    /**
     * Method returns generated interval function as an insert/update function
     *
     * @param string interval in the formats:
     *        "1", "-1d" or "- 1 day" -- For interval - 1 day
     *        Supported intervals [s]econd, [m]inute, [h]hour, [d]day, [M]onth, [Y]ear
     *        Default null;
     * @param string Initial date
     *
     * @return array
    */
    public function now ($diff = null, $func = "NOW()") {
        return Array("[F]" => Array($this->interval($diff, $func)));
    }

    /**
     * Method generates incremental function call
     * @param int increment amount. 1 by default
     */
    public function inc($num = 1) {
        return Array("[I]" => "+" . (int)$num);
    }

    /**
     * Method generates decrimental function call
     * @param int increment amount. 1 by default
     */
    public function dec ($num = 1) {
        return Array("[I]" => "-" . (int)$num);
    }
    
    /**
     * Method generates change boolean function call
     * @param string column name. null by default
     */
    public function not ($col = null) {
        return Array("[N]" => (string)$col);
    }

    /**
     * Method generates user defined function call
     * @param string user function body
     */
    public function func ($expr, $bindParams = null) {
        return Array("[F]" => Array($expr, $bindParams));
    }

    /**
     * Method creates new mysqlidb object for a subquery generation
     */
    public static function subQuery($subQueryAlias = "") {
        return new MySQLiDB(Array('host' => $subQueryAlias, 'isSubQuery' => true));
    }

    /**
     * Method returns a copy of a mysqlidb subquery object
     *
     * @param object new mysqlidb object
     */
    public function copy () {
        $copy = unserialize(serialize($this));
        $copy->_mysqli = $this->_mysqli;
        return $copy;
    }

    /**
     * Begin a transaction
     *
     * @uses mysqli->autocommit(false)
     * @uses register_shutdown_function(array($this, "_transaction_shutdown_check"))
     */
    public function startTransaction () {
        $this->_mysqli->autocommit(false);
        $this->_transaction_in_progress = true;
        register_shutdown_function(array ($this, "_transaction_status_check"));
    }

    /**
     * Transaction commit
     *
     * @uses mysqli->commit();
     * @uses mysqli->autocommit(true);
     */
    public function commit () {
        $this->_mysqli->commit();
        $this->_transaction_in_progress = false;
        $this->_mysqli->autocommit(true);
    }

    /**
     * Transaction rollback function
     *
     * @uses mysqli->rollback();
     * @uses mysqli->autocommit(true);
     */
    public function rollback () {
        $this->_mysqli->rollback();
        $this->_transaction_in_progress = false;
        $this->_mysqli->autocommit(true);
    }

    /**
     * Shutdown handler to rollback uncommited operations in order to keep
     * atomic operations sane.
     *
     * @uses mysqli->rollback();
     */
    public function _transaction_status_check () {
        if (!$this->_transaction_in_progress) {
            return;
        }
        $this->rollback();
    }
}
?>