<?php
/*
 * XIRC2 IRC Bot
 * Copyright (c) 2011-2013 Inuyasha.
 * All rights reserved.
 *
 * include\common.php
 * Miscellaneous functions common to everything.
 */

define('NIX_BLACK',   0);
define('NIX_RED',     1);
define('NIX_GREEN',   2);
define('NIX_YELLOW',  3);
define('NIX_BLUE',    4);
define('NIX_MAGENTA', 5);
define('NIX_CYAN',    6);
define('NIX_WHITE',   7);

// Logging and debugging functions
$logdate = "";
$logstream = NULL;
$clogstream = NULL;

// *nix color
function nixcolor($fg = NIX_WHITE, $bg = NIX_BLACK) {
	return sprintf("%c[0;%d;%dm", 0x1B, $fg + 30, $bg + 40);
}

// Outputs text to the console, automatically timestamped.
// Also automatically logs text.
function console($text, $color = -1) {
	global $logdate, $logstream, $clogstream;
	$logprefix = config::read('Behavior', 'logprefix', '');
	if (config::read('Behavior', 'logfolder', false))
		$logprefix .= DIRECTORY_SEPARATOR;
 	$termcolor = config::read('Behavior', 'terminalcolor', false);

	$newdate = date("Ymd");

	if (!$clogstream) {
		$clogstream = fopen("logs".DIRECTORY_SEPARATOR."{$logprefix}console.txt", 'ab');
		fwrite($clogstream, " *** Opened console log for writing *** \r\n");
	}

	if ($newdate != $logdate) {
		$logdate = $newdate;

		if ($logstream) {
			fwrite($logstream, " *** Closing old log file *** \r\n");
			fclose($logstream);
		}
		$logstream = fopen("logs".DIRECTORY_SEPARATOR.$logprefix.$logdate.".txt", 'ab');

		$dtext = date("F jS Y");
		echo " *** Date changed to {$dtext} *** \r\n";
		fwrite($clogstream, " *** Date changed to {$dtext} *** \r\n");
		fwrite($logstream, " *** Opened log file for {$dtext} *** \r\n");
	}

	$stamp = date("His");
	
	$cs = $ce = '';
	if ($termcolor && $color != -1) {
		$cs = nixcolor($color);
		$ce = nixcolor();
	}

	echo "{$cs}{$stamp}>{$ce} {$text}\r\n";
	fwrite($logstream,  "{$stamp}] {$text}\r\n");
	fwrite($clogstream, "{$stamp}] {$text}\r\n");
}

function consoleWarn($text) {
	console($text, NIX_YELLOW);
}

function consoleError($text) {
	console($text, NIX_RED);
}

function consoleDebug($text) {
	if (config::read('Behavior', 'debug', false))
		console($text, NIX_CYAN);
}

// Logs text to the date specific logs only
// (used for IRC input/output, possibly other things)
function textlog($prefix, $text) {
	global $logdate, $logstream, $clogstream;
	$logprefix = config::read('Behavior', 'logprefix', '');
	if (config::read('Behavior', 'logfolder', false))
		$logprefix .= DIRECTORY_SEPARATOR;

	$newdate = date("Ymd");

	if ($newdate != $logdate) {
		$logdate = $newdate;

		if ($logstream) {
			fwrite($logstream, " *** Closing old log file *** \r\n");
			fclose($logstream);
		}
		$logstream = fopen("logs".DIRECTORY_SEPARATOR.$logprefix.$logdate.".txt", 'ab');

		$dtext = date("F jS Y");
		echo " *** Date changed to {$dtext} *** \r\n";
		fwrite($clogstream, " *** Date changed to {$dtext} *** \r\n");
		fwrite($logstream, " *** Opened log file for {$dtext} *** \r\n");
	}

	$stamp = date("His");
	fwrite($logstream,  "{$stamp} {$prefix} {$text}\r\n");
}

// Close logs on shutdown
function shutdown() {
	global $logstream, $clogstream;

	console('Shutting down -- closing log files.');
	if ($logstream) fclose($logstream);
	if ($clogstream) fclose($clogstream);
}



// Takes an array of items and separates them into n-item chunks
// RFC 1459 prohibits more than three +o commands at once time
// This just helps us get around it by splitting it into multiple commands.
function separateList($array, $limit = 3) {
	$array = (array)$array;
	$i = 0;
	$final = array();
	foreach($array as $element) {
		$final[$i][] = $element;
		if (count($final[$i]) >= $limit)
			++$i;
	}
	return $final;
}



// Color functions and bold and shit
// Because it's a lot easier to just type c()
// than repeating irc::c() multiple times
function r() { return "\x0F"; }
function u() { return "\x1F"; }
function b() { return "\x02"; }
function c($n = -1, $nn = -1) {
	$k = "";
	if ($n != -1)  $k = str_pad($n, 2, 0, STR_PAD_LEFT);
	if ($nn != -1) $k .= ','.str_pad($nn, 2, 0, STR_PAD_LEFT);
	return "\x03". $k;
}


// Gets ordinal suffix from number
function getOrdinalSuffix($n) {
	if ((int)(($n % 100) / 10) == 1) return "th";
	else switch ($n % 10) {
		case 1: return "st";
		case 2: return "nd";
		case 3: return "rd";
	}
	return "th";
}

// Shortcut to get the full ordinal number
function getOrdinal($n) {
	return $n.getOrdinalSuffix($n);
}

// Turns a number into a text string
// "to be fancy"
function getTextNumeral($n) {
	if ($n <= 0) return "";
	if ($n >= 100) return $n;
	if ($n >= 10 && $n <= 19) switch ($n) {
		case 10: return "ten";
		case 11: return "eleven";
		case 12: return "twelve";
		case 13: return "thirteen";
		case 14: return "fourteen";
		case 15: return "fifteen";
		case 16: return "sixteen";
		case 17: return "seventeen";
		case 18: return "eightteen";
		case 19: return "nineteen";
	}
	$first = "";
	switch ($n % 10) {
		case 1: $first = "one"; break;
		case 2: $first = "two"; break;
		case 3: $first = "three"; break;
		case 4: $first = "four"; break;
		case 5: $first = "five"; break;
		case 6: $first = "six"; break;
		case 7: $first = "seven"; break;
		case 8: $first = "eight"; break;
		case 9: $first = "nine"; break;
	}
	if ($n < 10) return $first;
	$second = "";
	switch ((int)($n / 10)) {
		case 2: $second = "twenty"; break;
		case 3: $second = "thirty"; break;
		case 4: $second = "fourty"; break;
		case 5: $second = "fifty"; break;
		case 6: $second = "sixty"; break;
		case 7: $second = "seventy"; break;
		case 8: $second = "eighty"; break;
		case 9: $second = "ninety"; break;
	}
	if ($first == "") return $second;
	else return $second.'-'.$first;
}

// Takes a time in seconds and returns a string
// containing the hours, minutes, and seconds portions of it
function getTextTime($var) {
	$time = array();
	$days    = (int)(($var        ) / 86400);
	$hours   = (int)(($var % 86400) / 3600);
	$minutes = (int)(($var % 3600)  / 60);
	$seconds = $var % 60;

	if ($days)    $time[] = "{$days} day"      .(($days>1)   ?'s':'');
	if ($hours)   $time[] = "{$hours} hour"    .(($hours>1)  ?'s':'');
	if ($minutes) $time[] = "{$minutes} minute".(($minutes>1)?'s':'');
	if ($seconds) $time[] = "{$seconds} second".(($seconds>1)?'s':'');
	return implode(" ", $time);
}

// Only returns first section of string
// "11 days", "50 seconds", etc
function getShortTextTime($var) {
	$days    = (int)(($var        ) / 86400);
	$hours   = (int)(($var % 86400) / 3600);
	$minutes = (int)(($var % 3600)  / 60);
	$seconds = $var % 60;

	if ($days)    return ("{$days} day"      .(($days>1)   ?'s':''));
	if ($hours)   return ("{$hours} hour"    .(($hours>1)  ?'s':''));
	if ($minutes) return ("{$minutes} minute".(($minutes>1)?'s':''));
	if ($seconds) return ("{$seconds} second".(($seconds>1)?'s':''));
	return "";
}

// Turns an array into a list in the form of
// "1, 2, and 3" or "1 and 2" or "1, 2, 3, 4, and 5", etc...
function arrayToFormalList($array) {
	$keys = array_keys($array);

	if (count($array) <= 0)
		return "";
	if (count($array) == 1)
		return $array[$keys[0]];
	if (count($array) == 2)
		return $array[$keys[0]] . " and " . $array[$keys[1]];

	$finalElem = array_pop($keys);
	$array[$finalElem] = "and " . $array[$finalElem];
	return implode(', ', $array);
}
