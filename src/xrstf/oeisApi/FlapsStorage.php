<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

namespace xrstf\oeisApi;

use BehEh\Flaps\StorageInterface;

class FlapsStorage implements StorageInterface {
	protected $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	public function setValue($key, $value) {
		$this->db->query('REPLACE INTO flaps (cache_key, value, expires) VALUES (%s, %s, %n)', ["value:$key", $value, null]);
	}

	public function getValue($key) {
		return $this->db->fetch('SELECT value FROM flaps WHERE cache_key = %s AND (expires IS NULL OR expires > %i)', ["value:$key", time()]) ?: 0;
	}

	public function setTimestamp($key, $timestamp) {
		$this->db->query('REPLACE INTO flaps (cache_key, value, expires) VALUES (%s, %s, %n)', ["ts:$key", $timestamp, null]);
	}

	public function getTimestamp($key) {
		return floatval($this->db->fetch('SELECT value FROM flaps WHERE cache_key = %s AND (expires IS NULL OR expires > %i)', ["ts:$key", time()]) ?: 0);
	}

	public function expire($key) {
		$this->db->query('DELETE FROM flaps WHERE cache_key IN (%s, %s)', ["value:$key", "ts:$key"]);
	}

	public function expireIn($key, $seconds) {
		$expires = time() + $seconds;
		$this->db->query('UPDATE flaps SET expires = %s WHERE cache_key IN (%s, %s)', [$expires, "value:$key", "ts:$key"]);
	}
}
