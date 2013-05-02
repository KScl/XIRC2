<?php
/*
 * XIRC2 IRC Bot
 * Copyright (c) 2011-2013 Inuyasha.
 * All rights reserved.
 *
 * include\libs\timer.php
 * Common library.  Allows simplistic tracking of when X seconds have elapsed.
 */

class Timer
{
	private $time;

	private $seconds;
	private $repeat;
	public $functionname;

	public function __construct($interval = NULL, $repeat = FALSE, $funcname = NULL) {
		$this->seconds = $interval;
		$this->functionname = $funcname;
		$this->repeat = $repeat;
	}

	// start the timer
	public function start() {
		$this->time = microtime(true);
	}

	// stop the timer
	public function stop() {
		$this->time = null;
	}

	// change the interval if needed
	// returns self (for function chaining)
	public function setInterval($seconds) {
		$this->seconds = $seconds;
		return $this;
	}

	// change repeating or not
	// returns self (for function chaining)
	public function setRepeat($repeat) {
		$this->repeat = (($repeat) ? true : false);
		return $this;
	}

	// change the function name that's stored
	// returns self (for function chaining)
	public function setFunction($func) {
		$this->functionname = $func;
		return $this;
	}

	// check if the timer has elapsed (this means it needs to be called during mainloop a lot)
	// returns true if it has elapsed, false if not.
	public function hasElapsed() {
		if(!is_numeric($this->time))
			return false;

		if((microtime(true) - $this->time) >= $this->seconds) {
			$this->time = (($this->repeat) ? microtime(true) : null);
			return true;
		}
		return false;
	}
}
?>
