<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

require __DIR__.'/boot.php';

$app      = new xrstf\oeisApi\Application();
$db       = $app['database'];
$parser   = new xrstf\oeisApi\Sequence\Parser();
$importer = new xrstf\oeisApi\Sequence\Importer($db);

$files = glob(OEIS_ROOT.'/data/incoming/*.bz2');

foreach ($files as $file) {
	logLine('Importing '.basename($file).'...', false);

	$html      = bzdecompress(file_get_contents($file));
	$sequences = $parser->parseDocument($html);

	if ($sequences === false) {
		logLine(' error: parsing failed!', true, false);
		continue;
	}

	if (count($sequences) === 0) {
		logLine(' warning: no sequence table found in markup.', true, false);
		continue;
	}

	$db->query('BEGIN');

	foreach ($sequences as $sequence) {
		$importer->import($sequence);
		$sequence->dump();
	}

	$db->query('COMMIT');

	logLine(' done.', true, false);
	unlink($file);
}
