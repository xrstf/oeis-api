<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

namespace xrstf\oeisApi;

class Database {
	protected $conn;
	protected $logfile;

	public function __construct(\mysqli $connection, $logfile = null) {
		$this->conn    = $connection;
		$this->logfile = $logfile;
	}

	public function query($query, array $data = null) {
		if ($data !== null && count($data) > 0) {
			$query = $this->formatQuery($query, $data);
		}

		if ($this->logfile) {
			$before = microtime(true);
		}

		$result = $this->conn->query($query);

		if ($this->logfile) {
			$duration = microtime(true) - $before;
			file_put_contents($this->logfile, sprintf("/* %.4Fs */ %s;\n", $duration, $query), FILE_APPEND);
		}

		if (is_bool($result)) {
			return $result;
		}

		$rows = [];

		while ($row = $result->fetch_assoc()) {
			$rows[] = $row;
		}

		$result->free();

		return $rows;
	}

	public function fetch($query, array $data = null) {
		$rows = $this->query($query, $data);

		if (is_bool($rows)) {
			trigger_error('Received boolean result from query "'.$query.'". Did you mean to use query() instead of fetch()?', E_USER_WARNING);
			return $rows;
		}

		if (count($rows) === 0) {
			return null;
		}

		if (count($rows) > 1) {
			trigger_error('Received more than one row. Use query() if you intend to fetch multiple rows.', E_USER_WARNING);
			return $rows;
		}

		$row = reset($rows);

		return count($row) === 1 ? reset($row) : $row;
	}

	public function fetchColumn($query, array $data = null) {
		$rows = $this->query($query, $data);

		if (is_bool($rows)) {
			trigger_error('Received boolean result from query "'.$query.'". Did you mean to use query() instead of fetchColumn()?', E_USER_WARNING);
			return $rows;
		}

		$result = [];

		foreach ($rows as $row) {
			$result[] = reset($row);
		}

		return $result;
	}

	public function fetchMap($query, array $data = null) {
		$rows = $this->query($query, $data);

		if (is_bool($rows)) {
			trigger_error('Received boolean result from query "'.$query.'". Did you mean to use query() instead of fetchMap()?', E_USER_WARNING);
			return $rows;
		}

		$result = [];

		foreach ($rows as $row) {
			$key   = array_shift($row);
			$value = count($row) === 1 ? reset($row) : $row;

			$result[$key] = $value;
		}

		return $result;
	}

	public function update($table, array $newData, array $where) {
		$params  = [];
		$updates = [];
		$wheres  = [];

		foreach ($newData as $col => $value) {
			$updates[] = sprintf('`%s` = %s', $col, $this->getPlaceholder($value));
			$params[]  = $value;
		}

		list($whereClause, $whereParams) = $this->buildWhereClause($where);
		$params = array_merge($params, $whereParams);

		$query = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $updates), $whereClause);

		return $this->query($query, $params);
	}

	public function insert($table, array $data) {
		$params  = [];
		$values  = [];
		$columns = [];

		foreach ($data as $col => $value) {
			$values[]  = $this->getPlaceholder($value);
			$columns[] = '`'.$col.'`';
			$params[]  = $value;
		}

		$query  = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', $values));
		$result = $this->query($query, $params);

		if (!$result) {
			return false;
		}

		return $this->getInsertedID();
	}

	public function delete($table, array $where) {
		list($whereClause, $params) = $this->buildWhereClause($where);

		$query = sprintf('DELETE FROM `%s` WHERE %s', $table, $whereClause);

		return $this->query($query, $params);
	}

	public function getInsertedID() {
		return $this->conn->insert_id;
	}

	public function escape($s) {
		return $this->conn->real_escape_string($s);
	}

	public function quote($s) {
		return "'".$this->escape($s)."'";
	}

	/**
	 * Formats a query like it was an sprintf() format string
	 *
	 * Dealing with mysqli_bind_param() is painful and has some limitations. This
	 * function allows easier query construction, including handling arrays with
	 * IN() statements.
	 * As a nice side-effect: This is also faster for our usecase, as we never re-issue
	 * the same statement twice during one connection anyway.
	 *
	 * @src http://schlueters.de/blog/archives/155-Escaping-from-the-statement-mess.html
	 */
	public function formatQuery($query, array $args) {
		$modify_funcs = [
			'n' => function($v) { return $v === null ? 'NULL' : $this->quote($v); },
			's' => function($v) { return $this->quote($v);                        },
			'i' => function($v) { return (int) $v;                                },
			'f' => function($v) { return (float) $v;                              }
		];

		return preg_replace_callback(
			'/%(['.preg_quote(implode(array_keys($modify_funcs))).'%])(\+?)/',
			function ($match) use (&$args, $modify_funcs) {
				if ($match[1] == '%') {
					return '%';
				}

				if (!count($args)) {
					throw new \Exception('Missing values!');
				}

				$arg       = array_shift($args);
				$arrayMode = $match[2] === '+';

				if (!$arrayMode && (!is_scalar($arg) && !is_null($arg))) {
					throw new \Exception('List values are not allowed for this placeholder.');
				}
				elseif ($arrayMode && (is_scalar($arg) || is_null($arg))) {
					throw new \Exception('Expected a list value but got '.gettype($arg).' instead.');
				}

				if ($arg instanceof \Traversable) {
					$arg = iterator_to_array($arg);
					$arg = array_map($modify_funcs[$match[1]], $arg);

					return implode(', ', $arg);
				}
				elseif (is_array($arg)) {
					$arg = array_map($modify_funcs[$match[1]], $arg);

					return implode(', ', $arg);
				}
				else {
					$func = $modify_funcs[$match[1]];

					return $func($arg);
				}
			},
			$query
		);
	}

	public function buildWhereClause($where) {
		$wheres = [];
		$params = [];

		if (is_string($where)) {
			return [$where, $params];
		}

		if (count($where) === 0) {
			return ['1', $params];
		}

		foreach ($where as $col => $value) {
			if ($value === null) {
				$wheres[] = sprintf('`%s` IS NULL', $col);
			}
			elseif (is_array($value)) {
				if (empty($value)) {
					$wheres[] = '0'; // empty IN() is forbidden
				}
				else {
					// assuming we deal with strings is probably the safest
					$wheres[] = sprintf('`%s` IN (%%s+)', $col);
					$params[] = $value;
				}
			}
			else {
				$wheres[] = sprintf('`%s` = %s', $col, $this->getPlaceholder($value));
				$params[] = $value;
			}
		}

		return [implode(' AND ', $wheres), $params];
	}

	protected function getPlaceholder($var) {
		if (is_null($var))                 return '%n';
		if (is_int($var) || is_bool($var)) return '%i';
		if (is_float($var))                return '%f';

		return '%s';
	}
}
