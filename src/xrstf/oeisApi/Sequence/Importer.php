<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

namespace xrstf\oeisApi\Sequence;

use xrstf\oeisApi\Database;

class Importer {
	private $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	public function import(Sequence $sequence) {
		$id   = $sequence->getNumericID();
		$data = $sequence->getData();

		$imported = $this->db->fetch('SELECT imported_at FROM sequence WHERE id = %i', [$id]);
		$imported = $imported ?: date('Y-m-d H:i:s');

		$this->db->delete('sequence', ['id' => $id]);

		$this->db->insert('sequence', [
			'id'          => $id,
			'name'        => $data['name'],
			'eois_id'     => isset($data['identification']['EOIS']) ? $data['identification']['EOIS'] : null,
			'hois_id'     => isset($data['identification']['HOIS']) ? $data['identification']['HOIS'] : null,
			'offset_a'    => isset($data['offset'][0]) ? $data['offset'][0] : 0,
			'offset_b'    => isset($data['offset'][1]) ? $data['offset'][1] : 0,
			'imported_at' => $imported,
			'updated_at'  => date('Y-m-d H:i:s'),
			'worker'      => null
		]);

		$this->db->insert('sequence_blob', [
			'sequence'  => $id,
			'name'      => $data['name'],
			'beginning' => implode(',', $data['beginning']),
			'fulltext'  => implode("\n", $sequence->getHTMLStrings())
		]);

		foreach (array_keys($data['keywords']) as $keyword) {
			$this->db->insert('sequence_keyword', [
				'sequence' => $id,
				'keyword'  => $keyword
			]);
		}

		foreach ($sequence->getUsers() as $slug => $name) {
			$this->db->insert('person', [
				'id'   => $slug,
				'name' => $name
			]);

			$this->db->insert('person_sequence', [
				'person'   => $slug,
				'sequence' => $id
			]);
		}

		foreach ($sequence->getSequences() as $mention) {
			$this->db->insert('mention', [
				'sequence' => $id,
				'mentions' => $mention
			]);
		}

		foreach ($sequence->getCrossrefSequences() as $ref) {
			$this->db->insert('crossreference', [
				'from_sequence' => $id,
				'to_sequence'   => $ref
			]);
		}
	}
}
