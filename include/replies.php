<?php
/*
 * XIRC2 IRC Bot
 * Copyright (c) 2011-2013 Inuyasha.
 * All rights reserved.
 *
 * include\replies.php
 * Handles common CTCP replies to certain actions, treated as an extra hidden module
 */

class commonReplies implements XIRC_Module {
	public function onIrcInit($me) {
		events::hook($me, EVENT_CTCP, 'GetCTCP');
	}

	public function onMainLoop(){}

	public function GetCTCP(&$data) {
		if ($data->messageex[0] == 'VERSION')
			irc::ctcpReply($data->nick, 'VERSION', 'XIRC2 Custom IRC bot, v'.XIRC_VERSION);
		elseif ($data->messageex[0] == 'PING')
			irc::ctcpReply($data->nick, 'PING', $data->messageex[1]);
		elseif ($data->messageex[0] == 'TIME')
			irc::ctcpReply($data->nick, 'TIME', date('D M d H:i:s Y'));
	}
}
