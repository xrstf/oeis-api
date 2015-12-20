<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

require __DIR__.'/boot.php';

function bail($db) {
	// mark sequences as failed
	$db->query('UPDATE sequence SET worker = -%i WHERE worker = %i', [$workerID]);
}

$app      = new xrstf\oeisApi\Application();
$client   = new xrstf\oeisApi\OEISClient();
$db       = $app['database'];
$workerID = 1;

while (true) {
	$db->query('UPDATE sequence SET worker = %i WHERE worker IS NULL ORDER BY updated_at ASC, id ASC LIMIT 10', [$workerID]);

	$sequences   = $db->fetchMap('SELECT id, imported_at FROM sequence WHERE worker = %i', [$workerID]);
	$sequenceIDs = array_keys($sequences);

	if (count($sequenceIDs) === 0) {
		logLine('Could not acquire any sequences to update. Waiting for another turn.');
		sleep(3);
		continue;
	}

	$ids = array_map(function($sequenceID) {
		return sprintf('A%06d', $sequenceID);
	}, $sequenceIDs);

	logLine('Fetching sequences '.str_replace('id:', '', implode(', ', $ids)).'...', false);

	$a    = microtime(true);
	$html = $client->fetch($sequenceIDs);
	$time = microtime(true) - $a;

	if (!$html) {
		$error = error_get_last();
		logLine(' error: HTTP request failed: '.$error['message'], true, false);
		bail($db);

		sleep(3);
		continue;
	}

	logLine(sprintf(' fetched %.2F KiB markup in %.2F s.', strlen($html) / 1024.0, $time), true, false);
	logLine('Parsing document and searching sequences...', false);

	$parser    = new xrstf\oeisApi\Sequence\Parser();
	$importer  = new xrstf\oeisApi\Sequence\Importer($db);
	$sequences = $parser->parseDocument($html);

	if ($sequences === false) {
		logLine(' error: parsing failed!', true, false);
		bail($db);

		sleep(3);
		continue;
	}

	if (count($sequences) === 0) {
		logLine(' error: could not find any sequences in the markup!', true, false);
		bail($db);

		sleep(3);
		continue;
	}

	logLine(' okay, found '.count($sequences).' sequences.', true, false);

	$db->query('BEGIN');

	foreach ($sequences as $sequence) {
		logLine('Importing '.$sequence->getOEIS(), false);

		$importer->import($sequence);
		$sequence->dump();

		logLine(' done.', true, false);
	}

	// mark all sequences that haven't been updated as missing
	$db->query('UPDATE sequence SET worker = -worker WHERE worker = %i', [$workerID]);
	$db->query('COMMIT');

	logLine('Finished. Sleeping for 10 seconds...');

	sleep(10);
}
