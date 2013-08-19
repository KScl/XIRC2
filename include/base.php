<?php
/*
 * XIRC2 IRC Bot
 * Copyright (c) 2011-2013 Inuyasha.
 * All rights reserved.
 *
 * include\base.php
 * Abstract base for an XIRC module that must be implemented.
 */

interface XIRC_Module {
	public function onIrcInit($myName);
	public function onMainLoop();
}
