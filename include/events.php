<?php
/*
 * XIRC2 IRC Bot
 * Copyright (c) 2011-2013 Inuyasha.
 * All rights reserved.
 *
 * include\events.php
 * Handles the event structure, the IRC structure will fire hooks automatically
 */

class events {
	public static $bots = array();

	private static $handlers = array();

	private static $hooks = array(
		100 => 'EVENT_MESSAGE',
		101 => 'EVENT_CTCP',
		102 => 'EVENT_CTCPREPLY',
		103 => 'EVENT_NOTICE',
		104 => 'EVENT_ACTION',

		200 => 'EVENT_CHANNEL_MESSAGE',
		// Channel CTCPs treated like regular CTCPs
		// Channel CTCP replies treated like regular CTCP replies (WTF who does this anyway)
		203 => 'EVENT_CHANNEL_NOTICE',
		204 => 'EVENT_CHANNEL_ACTION',

		300 => 'EVENT_MODE',
		301 => 'EVENT_NICK',
		302 => 'EVENT_JOIN',
		303 => 'EVENT_PART',
		304 => 'EVENT_KICK',
		305 => 'EVENT_QUIT',

		402 => 'EVENT_SELF_JOIN',
		403 => 'EVENT_SELF_PART',
	);

	public static function initialize() {
		// Setup defines
		foreach (self::$hooks as $k => $t) {
			define($t, $k);
			self::$handlers[$k] = array();
		}
	}

	public static function hook($botName, $type, $callback) {
		if (!array_key_exists($botName, self::$bots)) {
			consoleError("Hook for nonexistant bot {$botName} was attempted");
			return;
		}

		$type = (int)$type;
		if (!array_key_exists($type, self::$hooks)) {
			consoleError("{$botName} tried to add hook with bad type {$type}");
			return;
		}

		$bot = &self::$bots[$botName];
		if (!method_exists($bot, $callback)) {
			consoleError("{$botName} tried to add hook with nonexistant callback {$callback}");
			return;
		}

		self::$handlers[$type][] = array($botName, $callback);
		consoleDebug("Added hook: {$botName}->{$callback}()");
	}

	public static function fire($event, &$data) {
		if (!count(self::$handlers[$event]))
			return;

		//consoleDebug("Firing event: ".$event);
		$data->type = $event;

		foreach (self::$handlers[$event] as $c) {
			$bot = &self::$bots[$c[0]];
			$func = $c[1];
			$bot->$func($data);
			unset($bot);
		}
	}
}

class eventData {
	public $type;

	public $nick;
	public $ident;
	public $host;

	public $channel;
	public $message;
	public $messageex = array();
}
