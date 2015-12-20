<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

define('OEIS_ROOT', dirname(__DIR__));
require OEIS_ROOT.'/vendor/autoload.php';

function logLine($text, $linebreak = true, $date = true) {
	if ($date) {
		$text = sprintf('[%s] %s', date('r'), $text);
	}

	if ($linebreak) {
		$text .= "\n";
	}

	print $text;
}
