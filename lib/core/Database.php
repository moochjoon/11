<?php
/**
 * Database Connection Class
 * MySQL connection and query execution
 */

namespace Namak;

class Database
{
    private $connection = null;
    private $config = [];
    private $last_query = null;
    private $query_count = 0;
    private $queries_log = [];

    /**
     * Constructor
     */
    public function __construct($config = [])
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * Connect to database
     */
    private function connect()
    {
        try {
            $dsn = "mysql:host={$this->config['host']}:{$this->config['port']};dbname={$this->config['database']};charset={$this->config['charset']}";

            $this->connection = new \PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_PERSISTENT => false,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->config['charset']} COLLATE {$this->config['collation']};",
                ]
            );

            // Set timezone
            $this->connection->exec("SET time_zone = '+00:00'");

        } catch (\PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a SELECT query
     */
    public function select($query, $bindings = [])
    {
        return $this->execute($query, $bindings);
    }

    /**
     * Execute an INSERT query
     */
    public function insert($table, $data)
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $query = "INSERT INTO {$this->config['prefix']}{$table} (" . implode(', ', $columns) . ") 
                  VALUES (" . implode(', ', $placeholders) . ")";

        $this->execute($query, array_values($data));

        return $this->connection->lastInsertId();
    }

    /**
     * Execute an UPDATE query
     */
    public function update($table, $data, $where = [], $where_operator = 'AND')
    {
        $set_clauses = [];
        $values = [];

        foreach ($data as $column => $value) {
            $set_clauses[] = "{$column} = ?";
            $values[] = $value;
        }

        $query = "UPDATE {$this->config['prefix']}{$table} SET " . implode(', ', $set_clauses);

        if (!empty($where)) {
            $query .= " WHERE " . $this->buildWhere($where, $where_operator);
            $values = array_merge($values, $this->getWhereValues($where));
        }

        return $this->execute($query, $values);
    }

    /**
     * Execute a DELETE query
     */
    public function delete($table, $where = [], $where_operator = 'AND')
    {
        $query = "DELETE FROM {$this->config['prefix']}{$table}";

        if (!empty($where)) {
            $query .= " WHERE " . $this->buildWhere($where, $where_operator);
            return $this->execute($query, $this->getWhereValues($where));
        }

        return $this->execute($query);
    }

    /**
     * Execute a raw query
     */
    public function execute($query, $bindings = [])
    {
        try {
            $this->last_query = $query;
            $this->query_count++;

            // Log query if enabled
            if ($this->config['logging']['enabled'] ?? false) {
                $this->logQuery($query, $bindings);
            }

            $statement = $this->connection->prepare($query);
            $statement->execute($bindings);

            return $statement;
        } catch (\PDOException $e) {
            throw new \Exception("Database query failed: " . $e->getMessage() . " Query: {$query}");
        }
    }

    /**
     * Get single row
     */
    public function first($query, $bindings = [])
    {
        $result = $this->select($query, $bindings)->fetch();
        return $result ?: null;
    }

    /**
     * Get all rows
     */
    public function get($query, $bindings = [])
    {
        return $this->select($query, $bindings)->fetchAll();
    }

    /**
     * Get count
     */
    public function count($table, $where = [])
    {
        $query = "SELECT COUNT(*) as count FROM {$this->config['prefix']}{$table}";

        if (!empty($where)) {
            $query .= " WHERE " . $this->buildWhere($where, 'AND');
            $result = $this->first($query, $this->getWhereValues($where));
        } else {
            $result = $this->first($query);
        }

        return $result['count'] ?? 0;
    }

    /**
     * Transaction support
     */
    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    public function commit()
    {
        return $this->connection->commit();
    }

    public function rollback()
    {
        return $this->connection->rollBack();
    }

    /**
     * Transaction wrapper
     */
    public function transaction(callable $callback)
    {
        try {
            $this->beginTransaction();
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Build WHERE clause
     */
    private function buildWhere($where, $operator = 'AND')
    {
        $clauses = [];

        foreach ($where as $column => $condition) {
            if (is_array($condition)) {
                // Array condition: ['column', 'operator', 'value']
                $clauses[] = "{$column} {$condition[0]} ?";
            } else {
                // Simple condition: 'column' => 'value'
                $clauses[] = "{$column} = ?";
            }
        }

        return implode(" {$operator} ", $clauses);
    }

    /**
     * Get WHERE values
     */
    private function getWhereValues($where)
    {
        $values = [];

        foreach ($where as $column => $condition) {
            if (is_array($condition)) {
                $values[] = $condition[1];
            } else {
                $values[] = $condition;
            }
        }

        return $values;
    }

    /**
     * Log query
     */
    private function logQuery($query, $bindings = [])
    {
        $start_time = microtime(true);
        $execution_time = microtime(true) - $start_time;

        $log_file = $this->config['logging']['path'] ?? LOG_PATH . '/queries.log';
        $timestamp = date('Y-m-d H:i:s');

        // Replace bindings in query for logging
        $logged_query = $query;
        foreach ($bindings as $binding) {
            $logged_query = preg_replace('/\?/', "'" . addslashes($binding) . "'", $logged_query, 1);
        }

        $slow_query_time = $this->config['logging']['slow_query_time'] ?? 1.0;
        $slow = $execution_time > $slow_query_time ? '[SLOW]' : '';

        $log_message = "[{$timestamp}] {$slow} ({$execution_time}s) {$logged_query}\n";
        @file_put_contents($log_file, $log_message, FILE_APPEND);

        $this->queries_log[] = [
            'query' => $logged_query,
            'time' => $execution_time,
            'slow' => $execution_time > $slow_query_time
        ];
    }

    /**
     * Get last query
     */
    public function getLastQuery()
    {
        return $this->last_query;
    }

    /**
     * Get query count
     */
    public function getQueryCount()
    {
        return $this->query_count;
    }

    /**
     * Get queries log
     */
    public function getQueriesLog()
    {
        return $this->queries_log;
    }

    /**
     * Get connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Escape string
     */
    public function escape($string)
    {
        return $this->connection->quote($string);
    }

    /**
     * Close connection
     */
    public function close()
    {
        $this->connection = null;
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }
}
?>
