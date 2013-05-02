<?php
/*
 * XIRC2 IRC Bot
 * Copyright (c) 2011-2013 Inuyasha.
 * All rights reserved.
 *
 * include\random.php
 * Handles high-quality randomness and simple randomizing functions.
 */

class random {
	// /dev/urandom
	// Do NOT use /dev/random as that might block if not enough entropy is available
	// Sure, this may be good for cryptography, but we don't need true randomness that bad!
	private static $randstream = NULL;

	public static function initialize() {
		if (!@is_readable('/dev/urandom') || // Not readable
			!(self::$randstream = fopen('/dev/urandom', 'rb'))) { // Open failed
			consoleDebug('/dev/urandom not readable: randomizer will use Mersenne Twister');
			return;
		}
		consoleDebug('Randomizer is using /dev/urandom');
	}

	// Returns a random float
	private static function getUrandom() {
		static $leftEntropy = NULL;
		if ($leftEntropy) {
			$rand = fread(self::$randstream, 6);
			$rand .= chr($leftEntropy).chr(0);
			$leftEntropy = NULL;
		}
		else {
			$rand = fread(self::$randstream, 7);
			$leftEntropy = (ord($rand{6}) & 0xF0) >> 4;
			$rand{6} = chr(ord($rand{6})&0xF);
			$rand .= chr(0);
		}

		// KennyTM's response on StackOverflow was much better than what I was doing before
		// Go figure, that.  Thanks!
		$parts = unpack('V2', $rand);
		$number = $parts[1] + pow(2.0, 32) * $parts[2];
		$number /= pow(2.0, 52);
		return $number;
	}

	// Returns random float from 0 to 1, not including 1
	public static function get() {
		if (!self::$randstream)
			return mt_rand(0,0x7FFFFFFE)/0x7FFFFFFF;
		return self::getUrandom();
	}

	// Returns random range, inclusive
	public static function range($min, $max) {
		$max = (int)$max;
		$min = (int)$min;
		if (!self::$randstream)
			return mt_rand($min, $max);
		return ((int)(self::getUrandom() * ($max-$min+1)) + $min);
	}

	// Returns random key from given array
	public static function key(&$array) {
		$keys = array_keys($array);

		if (!self::$randstream)
			$rand = mt_rand(0,count($keys)-1);
		else
			$rand = (int)(self::getUrandom() * count($keys));
		return $keys[$rand];
	}
}
