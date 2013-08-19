<?php
/*
 * XIRC2 IRC Bot
 * Copyright (c) 2011-2013 Inuyasha.
 * All rights reserved.
 *
 * include\config.php
 * Handles parsing and managing of the config ini files.
 */

abstract class config {
	private static $cdata = FALSE;

	public static function initialize($file) {
		if (self::$cdata !== FALSE) {
			consoleWarn("Requested configuration initialization when configuration already loaded");
			return;
		}

		self::$cdata = @parse_ini_file($file, true);
		if (self::$cdata === FALSE)
			die(consoleError("Configuration file [{$file}] missing or unreadable. Exiting."));

		console("Configuration file [{$file}] loaded.");
	}

	public static function read($section, $option, $default = NULL) {
		if (!isset(self::$cdata[$section][$option])) {
			if ($default !== NULL)
				return $default;
			die(consoleError("Required configuration option [{$section}/{$option}] was not found. Exiting."));
		}
		return self::$cdata[$section][$option];
	}
}
