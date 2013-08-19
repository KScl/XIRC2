<?php
/*
 * XIRC2 IRC Bot
 * Copyright (c) 2011-2013 Inuyasha.
 * All rights reserved.
 *
 * x.php
 * Entry point.
 */

error_reporting(E_ALL & ~(E_NOTICE|E_STRICT));

define('XIRC_VERSION', "2.1.3");
define('INCLUDE_DIR', 'include'.DIRECTORY_SEPARATOR);
define('BOT_DIR',     'bots'.DIRECTORY_SEPARATOR);
define('COMMON_LIBS', INCLUDE_DIR.'libs'.DIRECTORY_SEPARATOR);

/*
 * Startup sequence.
 */
if (!function_exists('socket_create')) {
	echo "XIRC2 IRC Bot requires sockets to be enabled.\r\n";
	echo "Please enable 'php_sockets.dll' in php.ini.\r\n\r\n";
	die();
}

require_once(INCLUDE_DIR.'base.php');
require_once(INCLUDE_DIR.'config.php');
require_once(INCLUDE_DIR.'common.php');
require_once(INCLUDE_DIR.'events.php');
require_once(INCLUDE_DIR.'random.php');
require_once(INCLUDE_DIR.'irc.php');
register_shutdown_function('shutdown');

$XIRC_OPTFILE = ($argv[1] ? $argv[1] : 'options.txt');
config::initialize('settings'.DIRECTORY_SEPARATOR.$XIRC_OPTFILE);
unset($XIRC_OPTFILE);

console('Startup...');
random::initialize();
events::initialize();

console('Loading bots...');
$listedbots = config::read('Bots', 'load');

foreach ($listedbots as $bot) {
	require_once(BOT_DIR.$bot.DIRECTORY_SEPARATOR.$bot.'.php');

	$b = new $bot();
	if (!$b instanceof XIRC_MODULE)
		die(consoleError("$bot: Not a proper XIRC2 module"));

	events::$bots[$bot] = &$b;
	unset($b);

	console("$bot loaded");
}
unset($listedbots);

// basic CTCP replies (VERSION, PING)
require_once(INCLUDE_DIR.'replies.php');
events::$bots['commonReplies'] = new commonReplies();

irc::connect();

while (!irc::isConnected()) {
	// We don't need to be running often. Sleep for a while between loops.
	usleep(1000);

	irc::receive();
	// Bots are not initialized yet.
}

// Connection confirmed, allow bots to add hooks, etc.
foreach (events::$bots as $k=>&$bot)
	$bot->onIrcInit($k);

while(true) { //main loop
	// We don't need to be running often. Sleep for a while between loops.
	usleep(1000);

	irc::receive();
	foreach (events::$bots as &$bot)
		$bot->onMainLoop();
}
