<?php

$mothership = 'http://oeis.xrstf.de/mothership.php';
// $mothership = 'http://oeis.gg/mothership.php';
$workerID   = 3;

printf("OEIS Crawler agent started.\n");

while (true) {
	/////////////////////////////////////////////////////////////////////////////
	// fetch work

	printf("Fetching work...");
	$job = @file_get_contents("$mothership?task=reserve&worker=$workerID");

	if (!$job) {
		printf(" failed.\n");
		sleep(5);
		continue;
	}

	$ids    = json_decode($job);
	$chunks = array_chunk($ids, 10);
	$result = [];

	printf(" OK, got %d sequences to fetch.\n", count($ids));

	/////////////////////////////////////////////////////////////////////////////
	// fetch each chunk and split it into sequences

	foreach ($chunks as $chunk) {
		$ids = [];

		foreach ($chunk as $id) {
			$ids[]       = sprintf('id:A%06d', $id);
			$result[$id] = null;
		}

		printf("Fetching sequences %s...", str_replace('id:', '', implode(', ', $ids)));

		$ids  = implode('|', $ids);
		$html = @file_get_contents(sprintf('http://oeis.org/search?q=%s&sort=number', urlencode($ids)));

		if (!$html) {
			printf(" failed!\n");
			continue;
		}

		printf(" OK, got %.2F KiB.\n", strlen($html) / 1024.0);

		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;

		if (!@$doc->loadHTML($html)) {
			printf("Error: Could not parse response as HTML.");
			sleep(10);
			continue;
		}

		// find all tables that look like they contain a sequence
		$xpath  = new DOMXPath($doc);
		$tables = $xpath->query('//table[tr[3]//table/tr/td[1]/a]');

		foreach ($tables as $table) {
			$idLinks = $xpath->query('tr[3]//table/tr/td[1]/a', $table);

			if ($idLinks->length > 0) {
				$id = $idLinks[0]->textContent;
				$id = (int) substr($id, 1);

				$result[$id] = base64_encode(bzcompress(toHTML($table)));
			}
		}

		sleep(10);
	}

	/////////////////////////////////////////////////////////////////////////////
	// report results back to the mothership

	$payload = json_encode($result, JSON_UNESCAPED_SLASHES);
	$context = stream_context_create(['http' => [
		'method'  => 'POST',
		'header'  => "Content-Type: application/json; charset=utf-8\r\nContent-Length: ".strlen($payload),
		'content' => $payload
	]]);

	printf("Uploading results to the mothership...");

	$result = @file_get_contents("$mothership?task=finish&worker=$workerID", null, $context);

	if (!$result) {
		printf(" failed.\n");
		sleep(5);
		continue;
	}

	printf(" OK.\n");
}

function toHTML($node) {
	$html = $node->C14N(false, false);
	$html = str_replace("\xC2\xA0", '&nbsp;', $html);

	return $html;
}
