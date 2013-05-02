<?php
/*
 * XIRC2 IRC Bot
 * Copyright (c) 2011-2013 Inuyasha.
 * All rights reserved.
 *
 * include\irc.php
 * IRC daemon.  Still a little messy, here there be dragons.
 */

define('STAT_DISCONNECTED', -1);
define('STAT_DROPPED', 0);
define('STAT_NEEDSEND', 1);
define('STAT_INPROGRESS', 2);
define('STAT_CONNECTED', 3);

class irc {
	private static $socket = NULL;
	private static $status = STAT_DISCONNECTED;

	private static $requestedNickname = ''; // If we have to autorename ourselves
	private static $nickname          = '';
	private static $login             = '';
	private static $realname          = '';

	private static $server = '';
	private static $port   = 6667;

	// Information provided to us by the server
	public static $chantypes = array('#');
	public static $prefixes  = array('o'=>'@', 'v'=>'+');
	public static $chanmodes = array('b','e','I','k','l');

	// Channel information
	// (we only keep a few things that we need)
	private static $channels = array();

	private static $_lastDrop = 0;
	private static $_nextSend = 0;
	private static $_rcons    = 0;
	private static $_autoDone = false;
	private static $_messageList = array();
	private static $_lastRecv = 0;
	private static $_regTimeoutCheck = 0;
	private static $_pingSent = false;

	public static $messageTime = 0.125;
	public static $multipleSend = 1;
	public static $reconnectTime = 4;
	public static $maxRetries = 9;
	public static $autoJoin = array();
	public static $autoExecute = array();
	public static $throttleSpeed = 1; // Send lines at 1 second when under load
	public static $throttleAverage = 1; // Check last 1 second of message send log
	public static $throttleAmount = 5; // Check if 5 messages have been sent in the throttle time;

	// Add to here when you want something sent
	private static $sendBuf = array();

 /*
  * These two functions handle the I/O
  */

	private static function handleSendBuf() {
		if (count(self::$sendBuf) == 0) return;

		$curtime = microtime(true);
		$inttime = (int)$curtime;
		if ($curtime < self::$_nextSend) return;

		$count = 0;
		$throttle = false;
		foreach(self::$_messageList as $time=>$num) {
			if ($time < $inttime-self::$throttleAverage)
				unset(self::$_messageList[$time]);
			else
				$count += $num;
		}
		if ($count > self::$throttleAmount)
			$throttle = true;

		$count = 0;
		if ($throttle) {
			$msg = trim(array_shift(self::$sendBuf));
			textlog('T>',$msg);

			$msg .= "\r\n";
			$count = 1;
		}
		else {
			$i = -1;
			$msg = "";
			while (++$i < self::$multipleSend) {
				if (count(self::$sendBuf) == 0) break;

				$newmsg = trim(array_shift(self::$sendBuf));
				textlog('->',$newmsg);

				$msg .= "{$newmsg}\r\n";
				++$count;
			}
		}

		$r = @socket_write(self::$socket, $msg);
		if ($r === false)
			self::dropped();

		if ($throttle)
			self::$_nextSend = microtime(true) + self::$throttleSpeed;
		else
			self::$_nextSend = microtime(true) + self::$messageTime;

		if (self::$throttleAverage) {
			if (!isset(self::$_messageList[$inttime]))
				self::$_messageList[$inttime] = 0;
			self::$_messageList[$inttime] += $count;
		}
	}

	private static function handleRecvBuf() {
		$txt = @socket_read(self::$socket,10240);
		if ($txt === FALSE) { // EEEP?  (wait, it might be a WOULDBLOCK error code, which is no problem)
			$err = socket_last_error(self::$socket);
			if (in_array($err, array(11, 10035)))
				return; // ignore wouldblock; we just don't have anything.

			consoleError(trim(socket_strerror($err)));
			self::dropped();
			return;
		}
		elseif ($txt) {
			self::$_pingSent = false;
			self::$_lastRecv = microtime(true);
			if (self::$status == STAT_INPROGRESS)
				self::$_regTimeoutCheck = microtime(true);

			$x = explode("\n", $txt);

			foreach ($x as $receive) {
				$receive = trim($receive);
				if ($receive == '') continue;

				$sections = explode(' ', $receive);
				if ($sections[0]{0} == ':') $sections[0] = substr($sections[0], 1);

				$arr = array();
				$rex = array();
				$foundcolon = false;

				foreach ($sections as $tmp) {
					if ($foundcolon)
						$arr[] = $tmp;
					elseif ($tmp{0} == ':') {
						$foundcolon = true;
						$arr[] = substr($tmp, 1);
					}
					else
						$rex[] = $tmp;
				}
				if (count($arr) > 0)
					$rex[] = implode(' ', $arr);

				// Numeric code
				if (preg_match('/[0-9]{3}/', $rex[1]))
					self::receivedNumeric($rex);
				else if ($rex[0] == "PING") {
					if (self::$status != STAT_CONNECTED)
					consoleDebug('Pre-connect ping: '.(string)$rex[1]);

					// Don't delay ping replies
					// Just send the damn things and get it over with
					$r = @socket_write(self::$socket, 'PONG :'.(string)$rex[1]."\r\n");
					if ($r === false)
						self::dropped();
				}
				else if ($rex[0] == "ERROR") {
					consoleError($rex[1]);
					self::dropped();
				}
				else if (in_array('received'.$rex[1], get_class_methods('irc'))) {
					textlog('<-',$receive);

					$function = 'received'.$rex[1];
					self::$function($rex);
				}
			}
		}
	}

	private static function dropped() {
		consoleError("Connection to server lost.");
		self::$status = STAT_DROPPED;
		self::$_lastDrop = microtime(true);
		self::$_autoDone = false;

		self::$sendBuf = array();
	}

	private function splitMask($in, &$nick, &$user, &$host) {
		$nick = NULL;
		$user = NULL;
		$host = NULL;

		$tmp = explode('!', $in);
		$nick = $tmp[0];
		if (!$tmp[1]) return;

		$tmp = explode('@', $tmp[1], 2);
		$user = $tmp[0];
		$host = $tmp[1];
	}


 /*
  * Reception functions.
  */
	private static function receivedNumeric(&$messages) {
		switch ($messages[1]) {
			case '001':
				// This is what we use to determine a successful connection
				self::$status = STAT_CONNECTED;
				console("Connection to ".self::$server." established.");
				self::$_rcons = 0;
				break;

			case '005':
				// Retrieve prefixes and chantypes
				for ($i = 3; $i < count($messages)-1; ++$i) {
					if (count($supported = explode('=', $messages[$i])) < 2) continue;

					if ($supported[0] == 'CHANTYPES') {
						self::$chantypes = str_split($supported[1]);
						consoleDebug('Supported channel types: '.implode(', ',self::$chantypes));
					}

					elseif ($supported[0] == 'PREFIX') {
						self::$prefixes = array();
						$chars = array();
						$q = 0;
						foreach(str_split($supported[1]) as $c) {
							if ($c == '(') { $designation = true; continue; }
							if ($c == ')') { $designation = false; continue; }
							if ($designation)
								$chars[] = $c;
							else
								self::$prefixes[$chars[$q++]] = $c;
						}
						consoleDebug('Supported user modes: '.implode('',array_keys(self::$prefixes)));
					}

					elseif ($supported[0] == 'CHANMODES') {
						self::$chanmodes = array();
						$types = explode(',',$supported[1]);
						for ($q = 0; $q < 3; ++$q) // we deliberately ignore the last set
							self::$chanmodes = array_merge(self::$chanmodes, str_split($types[$q]));
						consoleDebug('Channel modes to keep track of: '.implode('',self::$chanmodes));
					}
				}
				break;

			// NAMES
			case '353':
				$dest = strtolower($messages[4]);
				if (!array_key_exists($dest, self::$channels))
					break;
				$channel = &self::$channels[$dest];

				$users = explode(' ', strtolower($messages[5]));
				foreach ($users as $u) {
					$q = -1;
					while (++$q < strlen($u)) {
						if (!in_array($u{$q}, self::$prefixes))
							break;
					}
					$modes = substr($u, 0, $q);
					$uname = substr($u, $q);
					$channel->add($uname, $modes);
				}
				break;

			// Errors
			// Whoa, bad nickname
			case '433': // In use
				self::$nickname = self::$nickname . '_' . mt_rand(0,999);
				consoleWarn('Requested nickname in use, using '.self::$nickname);
				self::$sendBuf[] = 'NICK '.self::$nickname;
				break;

			default:
				break;
		}
	}

	private static function receivedMessage(&$messages, $type) {
		$from = $messages[0];
		$to   = $messages[2];
		$text = $messages[3];

		self::splitMask($from, $nick, $ident, $host);

    // Text to channel
    if (in_array($to{0}, self::$chantypes)) {
      $data = new eventData();
      $data->nick      = $nick;
      $data->ident     = $ident;
      $data->host      = $host;
      $data->message   = $text;
      $data->messageex = explode(' ', $text);
      $data->channel   = $to;

      if     ($type == 0) $eventtype = EVENT_CHANNEL_NOTICE;
      elseif ($type == 1) $eventtype = EVENT_CHANNEL_MESSAGE;
      elseif ($type == 2) $eventtype = EVENT_CTCP;
      elseif ($type == 3) $eventtype = EVENT_CTCPREPLY;
      else                $eventtype = EVENT_CHANNEL_ACTION;
      events::fire($eventtype, $data);
    }
    // Text to US.
    elseif (!strcasecmp($to, self::$nickname)) {
      $data = new eventData();
      $data->nick      = $nick;
      $data->ident     = $ident;
      $data->host      = $host;
      $data->message   = $text;
      $data->messageex = explode(' ', $text);
      $data->channel   = $to;

      if     ($type == 0) $eventtype = EVENT_NOTICE;
      elseif ($type == 1) $eventtype = EVENT_MESSAGE;
      elseif ($type == 2) $eventtype = EVENT_CTCP;
      elseif ($type == 3) $eventtype = EVENT_CTCPREPLY;
      else                $eventtype = EVENT_ACTION;
      events::fire($eventtype, $data);
    }
    // ??? We shouldn't be getting this.
    // Abandon.
  }

	private static function receivedNOTICE(&$messages) {
		if ($messages[3]{0} == "\x01") { // CTCP Reply
			$messages[3] = trim($messages[3], "\x01");
			self::receivedMessage($messages, 3);
		}
		else
		self::receivedMessage($messages, 0);
	}

	private static function receivedPRIVMSG(&$messages) {
		if ($messages[3]{0} == "\x01") { // CTCP
			$messages[3] = trim($messages[3], "\x01");
			self::receivedMessage($messages, (($messages[3] == "ACTION") ? 4 : 2));
		}
		else
			self::receivedMessage($messages, 1);
	}

	private static function receivedJOIN(&$messages) {
		$who   = $messages[0];
		$where = $messages[2];

		self::splitMask($who, $nick, $ident, $host);

		$data = new eventData();
		$data->nick      = $nick;
		$data->ident     = $ident;
		$data->host      = $host;
		$data->channel   = $where;

		$where = strtolower($where);

		// this is US.
		if (!strcasecmp($nick, self::$nickname)) {
			console('Joined channel '.$data->channel);
			self::$channels[$where] = new ircChannel($data->channel);
			events::fire(EVENT_SELF_JOIN, $data);
		}
		else {
			if (!array_key_exists($where,self::$channels)) return;
			self::$channels[$where]->add($nick);
			events::fire(EVENT_JOIN, $data);
		}
	}

	private static function receivedPART(&$messages) {
		$who    = $messages[0];
		$where  = $messages[2];
		$reason = $messages[3];

		self::splitMask($who, $nick, $ident, $host);

		$data = new eventData();
		$data->nick      = $nick;
		$data->ident     = $ident;
		$data->host      = $host;
		$data->channel   = $where;
		$data->message   = $reason;
		$data->messageex = explode(' ', $reason);

		$where = strtolower($where);

		// this is US.
		if (!strcasecmp($nick, self::$nickname)) {
			console('Left channel '.$data->channel);
			unset(self::$channels[$where]);
			events::fire(EVENT_SELF_PART, $data);
		}
		else {
			if (!array_key_exists($where, self::$channels)) return;
			self::$channels[$where]->remove($nick);
			events::fire(EVENT_PART, $data);
		}
	}

	private static function receivedQUIT(&$messages) {
		$who    = $messages[0];
		$reason = $messages[2];

		self::splitMask($who, $nick, $ident, $host);

		// this is US?!!
		if (!strcasecmp($nick, self::$nickname)) {
			consoleWarn('We were disconnected from the server?  Reason = '.$messages[2]);
			self::dropped();
		}
		else {
			foreach (self::$channels as $chan)
				$chan->remove($nick);

			$data = new eventData();
			$data->nick      = $nick;
			$data->ident     = $ident;
			$data->host      = $host;
			$data->message   = $reason;
			$data->messageex = explode(' ', $reason);
			events::fire(EVENT_QUIT, $data);
		}
	}

	private static function receivedMODE(&$messages) {
		// Mode changes can be done by servers too
		// so they don't send anything but a nick for them...
		$from = array_shift($messages);
		array_shift($messages);
		$to   = array_shift($messages);
		$text = implode(' ', $messages);

		$to = strtolower($to);
		if (!array_key_exists($to, self::$channels)) return;
		self::$channels[$to]->modeChange($text);

		if (!strcasecmp($from, self::$nickname)) {
    		// Don't fire events for OUR mode changes
		}
		else {
			$data = new eventData();
			$data->nick      = $from;
			$data->message   = $text;
			$data->messageex = explode(' ', $text);
			$data->channel   = $to;
			events::fire(EVENT_MODE, $data);
		}
	}

	private static function receivedNICK(&$messages) {
		$who = $messages[0];
		$to  = $messages[2];
		self::splitMask($who, $nick, $ident, $host);

		foreach (self::$channels as $chan)
			$chan->rename($nick, $to);

		// We got renamed
		if (!strcasecmp($nick, self::$nickname)) {
			self::$nickname = $to;
		}
		else {
			$data = new eventData();
			$data->nick      = $nick;
			$data->ident     = $ident;
			$data->host      = $host;
			$data->message   = $to;
			events::fire(EVENT_NICK, $data);
		}
	}

	private static function receivedINVITE(&$messages) {
		$from = $messages[0];
		$who  = $messages[2];
		$to   = $messages[3];
		self::splitMask($who, $nick, $ident, $host);

		// In all honesty we shouldn't be getting anyone else's invite shit
		if (!strcasecmp($to, self::$nickname)) {
			$data = new eventData();
			$data->nick      = $nick;
			$data->ident     = $ident;
			$data->host      = $host;
			$data->channel   = $to;
			events::fire(EVENT_INVITE, $data);
		}
	}


	// Called in main loop
	// Handles receiving and reconnecting and anything else
	public static function receive() {
		// We haven't identified, but are in progress
		// Wait for ANY message just to be sure we're connected to an IRC server?
		// (ed: probably not necessary)
		if (self::$status == STAT_NEEDSEND && self::$_lastRecv != 0) {
			self::$sendBuf[] = 'NICK '.self::$nickname;
			self::$sendBuf[] = 'USER ' . self::$login . ' "" "' . self::$server .  '" :'. self::$realname;

			self::$status = STAT_INPROGRESS;
			self::$_regTimeoutCheck = microtime(true);
		}
		elseif (self::$status == STAT_DROPPED) {
			if (self::$_rcons >= self::$maxRetries)
				die(consoleError('Maximum reconnection attempts exceeded.  Exiting.'));

			$lastTime = (microtime(true)-self::$_lastDrop);
			if ($lastTime < self::$reconnectTime) return;

			++self::$_rcons;
			console('Connection retry, attempt '.self::$_rcons);
			self::reconnect();
			return;
		}

		if (self::$status == STAT_CONNECTED && self::$_autoDone == false) {
			foreach(self::$autoExecute as $aex) {
				$aex = str_replace('[me]', self::$nickname, $aex);
				self::$sendBuf[] = $aex;
			}

			$chans = array();
			if (count(self::$autoJoin) > 0) {
				$chans = array_merge($chans, self::$autoJoin);
				self::$autoJoin == array();
			}
			if (count(self::$channels) > 0) {
				$chans = array_merge($chans, array_keys(self::$channels));
				self::$channels == array();
			}
			self::join($chans);

			self::$_autoDone = true;
		}

		// Timeout checks
		if (self::$status == STAT_CONNECTED) {
			if (self::$_pingSent == false && self::$_lastRecv + 240 < microtime(true)) {
				consoleWarn('No messages received in 4 minutes.  Sending PING.');
				self::$sendBuf[] = "PING :TIMEDOUT?";
				self::$_pingSent = true;
			}
			if (self::$_lastRecv + 480 < microtime(true)) {
				consoleError('No messages received in 8 minutes.	Assuming connection is dead.');
				self::dropped();
			}
		}
		if (self::$status == STAT_INPROGRESS) {
			if (self::$_pingSent == false && self::$_regTimeoutCheck + 60 < microtime(true)) {
				consoleWarn('No messages received in one minute.	Sending PING.');
				self::$sendBuf[] = "PING :TIMEDOUT?";
				self::$_pingSent = true;
			}
			if (self::$_regTimeoutCheck + 120 < microtime(true)) {
				consoleError('No messages received in two minutes.	Assuming registration timeout.');
				self::dropped();
			}
		}

		self::handleSendBuf();
		self::handleRecvBuf();
	}

	private static function reconnect() {
		if (self::$socket)
			socket_close(self::$socket);
		self::$_lastRecv = 0;
		self::$_regTimeoutCheck = 0;
		self::$status = STAT_NEEDSEND;

		console('Connecting to '.self::$server.":".self::$port);
		self::$socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!self::$socket) die(consoleError("Could not create a socket."));

		// Nonblock AFTER this.
		$r = @socket_connect(self::$socket, self::$server, self::$port);

		if ($r) {
			socket_set_nonblock(self::$socket);
			console('Link established.');
		}
		else {
			consoleError(trim(socket_strerror(socket_last_error(self::$socket))));
			self::dropped();
		}
	}

	public static function connect() {
		if (self::$status != STAT_DISCONNECTED)
			return;

		self::$server = config::read('Connection', 'server');
		self::$port   = config::read('Connection', 'port', 6667);

		self::$requestedNickname = self::$nickname = config::read('Behavior', 'nickname');
		self::$login = config::read('Behavior', 'username');

		$rntemp = 'XIRC2 IRC Bot | v'.XIRC_VERSION;
		if ($runnername = config::read('Behavior', 'hostname', false))
			$rntemp .= " | Owned by {$runnername}";
		self::$realname = $rntemp;

		self::$maxRetries    = config::read('Behavior', 'maxretries', 4);
		self::$reconnectTime = config::read('Behavior', 'reconnecttime', 5);

		self::$messageTime  = config::read('Behavior', 'messagetime', 0.1);
		self::$multipleSend = config::read('Behavior', 'multiplesend', 1);

		self::$throttleSpeed   = config::read('Behavior', 'throttlespeed', 1);
		self::$throttleAverage = config::read('Behavior', 'throttleaverage', 3);
		self::$throttleAmount  = config::read('Behavior', 'throttleamount', 6);

		self::$autoJoin = config::read('Automatic', 'join', array());
		self::$autoExecute = config::read('Automatic', 'exec', array());

		self::reconnect();
	}

	public static function isConnected() {
		return (self::$status == STAT_CONNECTED);
	}


  /*
   * IRC Commands
   */
	// If nothing else here meets your fancy, use this to send anything.
	public static function rawSend($message) {
		if (self::$status != STAT_CONNECTED)
			return;

		self::$sendBuf[] = $message;
	}

	// Joins an array of channels all at once.	Include keys if you want/need.
	// Note that the auto-reconnection currently doesn't support keys, so be careful.
	public static function join($chans, $keys="") {
		if (self::$status != STAT_CONNECTED)
			return;

		if (is_array($chans))
			$chans = implode(',', $chans);
		if (is_array($keys))
			$keys = implode(',', $keys);

		if (strlen($chans) <= 0)
			return;

		self::$sendBuf[] = "JOIN {$chans} {$keys}";
	}

	// Send a message to a user/channel.
	public static function message($dest, $text) {
		if (self::$status != STAT_CONNECTED)
			return;

		self::$sendBuf[] = "PRIVMSG {$dest} :{$text}";
	}

	// Send a notice to a user/channel.
	public static function notice($dest, $text) {
		if (self::$status != STAT_CONNECTED)
			return;

		self::$sendBuf[] = "NOTICE {$dest} :{$text}";
	}

	// Send an action to a user/channel.
	public static function action($dest, $text) {
		if (self::$status != STAT_CONNECTED)
			return;

		self::$sendBuf[] = "PRIVMSG {$dest} :\x01ACTION {$text}\x01";
	}

	// Send a CTCP reply to a user/channel.
	public static function ctcpReply($dest, $ctcp, $text) {
		if (self::$status != STAT_CONNECTED)
			return;

		self::$sendBuf[] = "NOTICE {$dest} :\x01{$ctcp} {$text}\x01";
	}

	// Send a single mode change to the channel.
	// (In all likelihood you might want to use one of the commands below.)
	public static function mode($dest, $mode, $param = '') {
		if (self::$status != STAT_CONNECTED)
			return;

		if ($mode{0} != '+' && $mode{0} != '-')
			$mode = '+'.$mode;

		self::$sendBuf[] = "MODE {$dest} {$mode} {$param}";
	}

	// Sends multiple mode changes, three at a time.
	public static function multiMode($dest, $p, $m, $paramlist) {
		$ulist = separateList($paramlist);
		foreach ($ulist as $command) {
			$mode = str_repeat($m, count($command));
			self::mode($dest, $p.$mode, implode(' ', $command));
		}
	}

	// Ban one or more users.
	public static function ban($dest, $users) {
		self::multiMode($dest, '+', 'b', $users);
	}

	// Unban one or more users.
	public static function unban($dest, $users) {
		self::multiMode($dest, '-', 'b', $users);
	}

	// Op one or more users.
	public static function op($dest, $users) {
		self::multiMode($dest, '+', 'o', $users);
	}

	// Deop one or more users.
	public static function deop($dest, $users) {
		self::multiMode($dest, '-', 'o', $users);
	}

	// Voice one or more users.
	public static function voice($dest, $users) {
		self::multiMode($dest, '+', 'v', $users);
	}

	// Devoice one or more users.
	public static function devoice($dest, $users) {
		self::multiMode($dest, '-', 'v', $users);
	}

	// Kick one or more users from a channel.
	public static function kick($dest, $users, $reason = '') {
		if (self::$status != STAT_CONNECTED)
			return;

		$users = (array)$users;
		foreach ($users as $u) {
			self::$sendBuf[] = "KICK {$dest} {$u} :{$reason}";
		}
	}

	// Invite one or more users to a channel.
	public static function invite($dest, $users) {
		if (self::$status != STAT_CONNECTED)
			return;

		$users = (array)$users;
		foreach ($users as $u) {
			self::$sendBuf[] = "INVITE {$dest} {$u}";
		}
	}


 /*
  * Misc
  */
	public static function hasPower($chan, $user, $power) {
		if (!array_key_exists(strtolower($chan),self::$channels)) return false;
		$channel = &self::$channels[strtolower($chan)];

		return $channel->hasPower($user, $power);
	}

	public static function hasOp($chan, $user) {
		return self::hasPower($chan, $user, 'o');
	}

	public static function hasVoice($chan, $user) {
		return self::hasPower($chan, $user, 'v');
	}
}

//
// Subclass: ircChannel
// Handles channel management.
//
class ircChannel {
	public $name;
	public $users = array();

	public function __construct($n) {
		$this->name = $n;
	}

	public function hasPower($user, $power) {
		if (!array_key_exists(strtolower($user), $this->users))
			return false;

		$modes = array_keys(irc::$prefixes);
		if (count($usermodes = $this->users[strtolower($user)]) == 0)
			return false;

		foreach ($modes as $m) {
			// Assume anything above +o adopts the powers
			if (in_array($m, $usermodes))
				return true;

			// Assume anything below does not adopt power
			if ($m == $power)
				return false;
		}
		return false;
	}

	public function add($name, $powers='') {
		$name = strtolower($name);
		$this->users[$name] = array();
		foreach (str_split($powers) as $p) {
			if (($needle = array_search($p, irc::$prefixes)) !== FALSE)
			$this->users[$name][] = $needle;
		}
	}

	public function remove($name) {
		$name = strtolower($name);
		if (!array_key_exists($name, $this->users))
			return;

		unset($this->users[$name]);
	}

	public function rename($old, $new) {
		$old = strtolower($old);
		$new = strtolower($new);
		// We don't give a shit about case changes
		if ($old == $new) return;

		if (!array_key_exists($old, $this->users))
			return;
		$this->users[$new] = $this->users[$old];
		unset($this->users[$old]);
	}

	public function modeChange($list) {
		$list = explode(' ', $list, 2);
		$modes = str_split($list[0]);

		// $list is now the parameter list
		// $modes is the list of modes
		$list = explode(' ', strtolower($list[1]));

		$prefixModes = array_keys(irc::$prefixes);

		$adding = true;
		foreach($modes as $m) {
			if ($m == '+') { $adding = true; continue; }
			if ($m == '-') { $adding = false; continue; }
			if (in_array($m, $prefixModes)) { // it is a user mode that we need to track
				$user = array_shift($list);
				if (!array_key_exists($user, $this->users))
					continue;
				if ($adding)
					$this->users[$user][] = $m;
				elseif (($needle = array_search($m, $this->users[$user])) !== FALSE)
					unset($this->users[$user][$needle]);
			}
			// we don't track it but we need to remove a parameter for it
			elseif (in_array($m, irc::$chanmodes))
				array_shift($list);
		}
	}
}
