<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

define('OEIS_ROOT', dirname(__DIR__));
require OEIS_ROOT.'/vendor/autoload.php';

$task   = isset($_GET['task']) ? $_GET['task'] : null;
$worker = isset($_GET['worker']) ? intval($_GET['worker']) : 100;

if ($task === 'reserve') {
	$app = new xrstf\oeisApi\Application();
	$db  = $app['database'];

	$db->query('UPDATE sequence SET worker = %i WHERE worker IS NULL ORDER BY updated_at ASC, id ASC LIMIT 50', [$worker]);

	$sequences = $db->fetchColumn('SELECT id FROM sequence WHERE worker = %i', [$worker]);
	$sequences = array_map('intval', $sequences);

	print json_encode($sequences);
}
elseif ($task === 'finish') {
	$app      = new xrstf\oeisApi\Application();
	$db       = $app['database'];
	$parser   = new xrstf\oeisApi\Sequence\Parser();
	$importer = new xrstf\oeisApi\Sequence\Importer($db);

	$body = file_get_contents('php://input');
	$data = json_decode($body, true);

	$db->query('BEGIN');

	foreach ($data as $id => $blob) {
		$oeis = sprintf('A%06d', (int) $id);

		if ($blob) {
			$html      = bzdecompress(base64_decode($blob));
			$sequences = $parser->parseDocument($html);

			// should always only be one
			foreach ($sequences as $sequence) {
				$importer->import($sequence);
				$sequence->dump();
			}
		}
		else {
			$db->query('UPDATE sequence SET worker = -worker WHERE id = %i', [$id]);
		}
	}

	// in case some reserved ID is not reported back
	$db->query('UPDATE sequence SET worker = NULL WHERE worker = %i', [$worker]);
	$db->query('COMMIT');

	print 'Thanks!';
}
