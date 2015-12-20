<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

namespace xrstf\oeisApi\Sequence;

class Sequence {
	private $data;
	private $raw;

	public function __construct(array $data) {
		$this->data = $data;
	}

	public function getData() {
		return $this->data;
	}

	public function setRawHTML($html) {
		$this->raw = $html;
	}

	public function getRawHTML() {
		return $this->raw;
	}

	public function getOEIS() {
		return $this->data['identification']['OEIS'];
	}

	public function getNumericID() {
		return (int) substr($this->getOEIS(), 1);
	}

	public function getHTMLStrings() {
		return $this->collectHTML($this->data);
	}

	public function getUsers() {
		return $this->collectUsers($this->data);
	}

	public function getSequences() {
		return $this->collectSequences($this->data);
	}

	public function getCrossrefSequences() {
		if (isset($this->data['crossrefs'])) {
			return $this->collectSequences($this->data['crossrefs']);
		}

		return [];
	}

	public function dump() {
		$id       = $this->getNumericID();
		$filename = sprintf('%s/data/%03d/A%06d.json', OEIS_ROOT, $id / 1000, $id);
		$dir      = dirname($filename);

		if (!is_dir($dir)) {
			mkdir($dir);
		}

		$data = $this->data;

		if ($this->raw) {
			$data['raw'] = $this->raw;
		}

		return file_put_contents("$filename.bz2", bzcompress(json_encode($data, JSON_UNESCAPED_SLASHES))) > 0;
	}

	protected function collectHTML(array $data) {
		$htmlLines = [];

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				if (isset($value['html'])) {
					$htmlLines[] = $value['html'];
				}
				else {
					$htmlLines = array_merge($htmlLines, $this->collectHTML($value));
				}
			}
		}

		return $htmlLines;
	}

	protected function collectUsers(array $data) {
		$users = [];

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				if (isset($value['rel']) && $value['rel'] === 'user') {
					$users[$value['slug']] = $value['name'];
				}
				else {
					$users = array_merge($users, $this->collectUsers($value));
				}
			}
		}

		return $users;
	}

	protected function collectSequences(array $data) {
		$sequences = [];

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				if (isset($value['rel']) && $value['rel'] === 'sequence') {
					$sequences[] = (int) substr($value['id'], 1);
				}
				else {
					$sequences = array_merge($sequences, $this->collectSequences($value));
				}
			}
		}

		return $sequences;
	}
}
