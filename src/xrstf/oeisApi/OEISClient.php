<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

namespace xrstf\oeisApi;

class OEISClient {
	public function fetch(array $ids) {
		if (count($ids) > 10) {
			throw new \LogicException('Cannot request more than 10 sequences at once.');
		}

		// normalize IDs
		$ids = array_map(function($id) {
			if (is_string($id) && strlen($id) > 0 && $id[0] === 'A') {
				$id = substr($id, 1);
			}

			return sprintf('id:A%06d', $id);
		}, $ids);

		$ids = implode('|', $ids);
		$ctx = stream_context_create(['http' => [
			'header' => 'User-Agent: xsrtf/oeis-api'
		]]);

		return @file_get_contents(sprintf('http://oeis.org/search?q=%s&sort=number', urlencode($ids)), null, $ctx);
	}
}
