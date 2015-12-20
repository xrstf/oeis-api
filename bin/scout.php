<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

require __DIR__.'/boot.php';

$app    = new xrstf\oeisApi\Application();
$client = new xrstf\oeisApi\OEISClient();

$db    = $app['database'];
$start = $db->fetch('SELECT MAX(id) FROM sequence') + 1;

logLine('Scouting starts at '.$start.'...', false);

$a    = microtime(true);
$html = $client->fetch(range($start, $start + 9));
$time = microtime(true) - $a;

if (!$html) {
	$error = error_get_last();

	logLine(' scout died: '.$error['message'], true, false);
	exit(1);
}

logLine(sprintf(' scout returned with %.2F KiB markup after %.2F s.', strlen($html) / 1024.0, $time), true, false);
logLine('Parsing loot and searching sequences...', false);

$parser    = new xrstf\oeisApi\Sequence\Parser();
$importer  = new xrstf\oeisApi\Sequence\Importer($db);
$sequences = $parser->parseDocument($html);

if ($sequences === false) {
	logLine(' error: parsing failed!', true, false);
	exit(1);
}

if (count($sequences) === 0) {
	logLine(' no new sequences found in this chunk :(', true, false);
	exit(1);
}

logLine(' oh boy, found '.count($sequences).' new sequences.', true, false);

$db->query('BEGIN');

foreach ($sequences as $sequence) {
	$importer->import($sequence);
	$sequence->dump();

	logLine('Added sequence '.$sequence->getOEIS().'.');
}

$db->query('COMMIT');
