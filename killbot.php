<?php
set_time_limit(0);
date_default_timezone_set("UTC");
ini_set('display_errors', 'on');


include 'config.php';

//Don't spam nickserv, it pisses off IRC staff
$joined = 0;
//510 - 64 is the true max message length but we're shaving off a few just incase.
$max_message_length = 300;
$quiet_chan = array();
$validated = array();

class IP_HASH
{
	
	private $hash = array();

	public function add_user($nick, $ip, $naughty)
	{
		if($this->ip_listed($ip)) {
			$this->hash[$ip]["member"][] = $nick;
			$this->hash[$ip]["naughty"] += $naughty;
		}
		$this->hash[$ip] = array("member" => array($nick), "naughty" => $naughty);
	}

	public function remove_user($nick, $ip)
	{
		if($this->ip_listed($ip)) {
			$index = array_search($nick, $this->hash[$ip]["member"]);
			if($index !== FALSE) {
				#remove the user from the member list.
				array_splice($this->hash[$ip]["member"], $index, 1);

				#If Member list is empty delete the ip tracking.
				if(count($this->hash[$ip]["member"]) == 0) {
					unset($this->hash[$ip]);
				}
			}
		}
	}

	public function change_nick($oldnick, $newnick, $ip)
	{
		if($this->ip_listed($ip)) {
			$index = array_search($oldnick, $this->hash[$ip]["member"]);
			if($index !== FALSE) {
				$this->hash[$ip]["member"][$index] = $newnick;
				return true;
			}
		}
		return false;
	}

	public function check_naughty($ip) {
		if($this->ip_listed($ip)) {
			return $this->hash[$ip]["naughty"];
		}
		return 0;
	}

	public function get_members($ip) {
		if($this->ip_listed($ip)) {
			return $this->hash[$ip]["member"];
		}
		return array();
	}

	private function ip_listed($ip) {
		if(array_key_exists($ip, $this->hash)) return true;
		return false;
	}

	public function add_naughty($ip, $naughty) {
		if($this->ip_listed($ip)) {
			$this->hash[$ip]["naughty"] += $naughty;
		}
	}

}

class IRCBot
{

    //This is going to hold our TCP/IP connection

    var $socket;

    // holds the naughty list

    var $active = 1;    

// holds the report to paste to privatepaste.
    var $privpaste = "";

    //This is going to hold all of the messages both server and client

    var $ex = array();
    var $op, $cmd_chan, $sender;
    var $nicklist = array();
    var $kline = array();
    var $join = array();
    var $kline_trigger = array();
    var $last_said = "kljvfdkljdfasoifqewjoicqewonjicqfewohjiqfewohigqfewohiqfew";
    var $last_said_speakers = array();
    var $last_said_count = 0;
    var $last_said_flag = 0;
    var $kline_exempt = array("nickserv", "chanserv", "hostserv", "operserv");
    var $under_attack = 0;
    var $IP_HASH = null; 
    /*

    Construct item, opens the server connection, logs the bot in



    @param array

    */

    function __construct($config)

    {

	$this->IP_HASH = new IP_HASH();
        $this->socket = fsockopen($config['server'], $config['port']);
        stream_set_blocking($this->socket, 0);
        stream_set_timeout($this->socket, 0, 250000); // .5 seconds = 500000 microseconds
        $this->login($config);
        $this->main();


    }


    /*

    Logs the bot in on the server



    @param array

    */

    function login($config)

    {


        if ($config['irc_server_pass'] != '')
            $this->send_data('PASS', $config['irc_server_pass']);
        $this->send_data('USER', $config['nick'] . ' KillBot ' . $config['nick'] . ' :' . $config['name']);

        $this->send_data('NICK', $config['nick']);

    }


    /*

    This is the workhorse function, grabs the data from the server and displays on the

    */

    function main()
    {
        global $config, $joined, $ops, $quiet_chan;
        $next_check = time() + 10;
	$next_reponse = time() +10;
	$last_said = "kljvfdkljdfasoifqewjoicqewonjicqfewohjiqfewohigqfewohiqfew";
        while (1 == 1) {
            if ($this->under_attack <= time()) {
		usleep(250);
	    }
            socket_clear_error();
            $command = "none";
            $con = 0;
            $data = "";
            if ($this->socket != FALSE && $data = fgets($this->socket, 1024)) {
                $con = 1;
            }

            //Handles Reconnection.
            // $i is the timeout before it reconnects
            if ($this->socket == false || feof($this->socket)) {
                $connected = 0;
                while ($connected == 0) {
                    if ($this->reconnect()) $connected = 1;
                }
            }

            //trim($data);

            //This section handles IRC data sent.
            if ($con && $data != "") {
                $this->ex = explode(' ', $data);
                if (count($this->ex) && $this->ex[0] == 'PING') {
                    $this->send_data('PONG', $this->ex[1]); //Plays ping-pong with the server to stay connected.
                    if (!$joined) {
                        $this->join_channel($config['default_channel']);
                        $joined = 1;
                    }
                } else if (count($this->ex) > 1 && $joined == 0 && $this->ex[1] == '376') {
                    $joined = 1;
                    sleep(1);
                    $this->join_channel($config['default_channel']);
                } // Standard line reading, we figure out who sent the message, what the message is, and if they are in the OP list or not.
                else if (count($this->ex) >= 4) {
                    $this->op = 0;
                    $command = strtolower(str_replace(array(chr(10), chr(13)), '', $this->ex[3]));
                    $this->cmd_chan = strtolower(str_replace(array(chr(10), chr(13)), '', $this->ex[2]));
                    $this->sender = str_replace(":", "", strstr($this->ex[0], '!', true));

			//Private Message to Us
                    if ($this->cmd_chan == $config['nick'])
                        $this->cmd_chan = $this->sender;

                    if (in_array(strtolower($this->sender), $ops))
                        $this->op = 1;

		//If the we're looking for joins or not.
		if($this->cmd_chan == "#services" && $this->ex[3] == ":USERS:")
				$this->process_new_join();

		if($this->ex[1] == "NOTICE" && $this->ex[3] == ":***" && $this->ex[9] == "trying" && trim($this->ex[16]) == "spambot") {
				$this->join_channel($this->ex[12], 1);
				$nick = trim($this->ex[7]);
				$this->check_nicklist($nick);
				$this->nicklist[$nick]["naughty"] += 2;
				$this->nicklist[$nick]["reason"] = "Spambot";
				$this->nicklist[$nick]["realReason"] .= " Services Spambot warning";
				$this->nicklist[$nick]["time"] = time();
				$this->check_naughty($nick);
			}
				


                } // For looking at NICK changes / QUITs
                else if (count($this->ex) == 3) {
                    $this->sender = str_replace(":", "", strstr($this->ex[0], '!', true));
                } // For when things don't match up how they should.  Used for debugging.
                else if (count($this->ex) < 3)
		{
                    if ($config['debug'] == 1) {
                        $error = "\n\nERROR: ";
                        foreach ($this->ex as $key => $value)
                            $error .= $key . " = " . $value . "  ";
                        echo $error . "\n";
                    }
                    $command = "shit";
		}


            if ($data) {
		$cleandata = preg_replace('/[\x00-\x1F\x7f-\xFF]/', '', $data);
#[2016-04-01 15:31:53]:HostServ!services@services.sorcery.net PRIVMSG #ops :COMMAND: Discord!Discord@s72-38-174-164.static.comm.cgocable.net (Discord) used REQUEST to request new vhost the.irc.relay.bot
		if(count($this->ex) >= 12 && $this->ex[2] == "#ops" && strtolower($this->sender) == "hostserv" && $this->ex[7] == "REQUEST")
		{
			$nick=explode("!", $this->ex[4])[0];
			$vhost=trim($this->ex[12]);
			$tld=explode(".", $vhost);
			$tld=strtoupper($tld[count($tld)-1]);
			$tldlist= file_get_contents('/root/killbot/tld_list.txt');
                        $searcha=explode("\n", $tldlist);
                        if(in_array(strtoupper($tld), $searcha))
			{
				$this->send_privmsg("#ops", "\x03[ .\x0304".strtolower($tld)."\x03 ] is a valid top level domain.  \x0304Please verify ownership of $vhost \x03for $nick");
				$this->send_privmsg("#ops", "Suggested command: /hs reject $nick That vhost has a valid Top-Level Domain, and thus the domain must be verified as owned by you to be obtained as a vhost. If you own it, please /join #square to talk to an IRCop to verify this. http://data.iana.org/TLD/tlds-alpha-by-domain.txt contains a list of valid TLD's to avoid.");
			} else {
				$this->send_privmsg("#ops", "\x03[ .\x0303".strtolower($tld)."\x03 ] is not a valid top level domain.  \x0303No verification needed to activate $vhost \x03for $nick" ); 
			}

			
		}
		if(count($this->ex) >= 4 && ($this->ex[1] == "NOTICE" || $this->ex[1] == "PRIVMSG") && $this->ex[2] != "#services")
		{	
			$tmparraya = explode(' ', $cleandata);
			unset( $tmparraya[0]);
			unset( $tmparraya[1]);
			unset( $tmparraya[2]);
			$said = implode(" ", $tmparraya);
			if($said == $this->last_said)
			{
				if(!in_array($this->sender, $this->last_said_speakers))
				{
					$this->last_said_speakers[] = $this->sender;
					$this->last_said_flag++;
				}
					
				$this->last_said_count++;
				if($this->last_said_count >= 4 && $this->last_said_flag >= 2)
				{
					$tmparrayb = $this->last_said_speakers;
					foreach ($this->last_said_speakers as $klinekey => $klineme)
					{
	                                        if($this->active && !in_array(strtolower($klineme), $ops))
	                                        {
        	                                        $this->kline($this->sender, "Spammer", "+90d", "Repeating lines.");
							unset($tmparrayb[$klinekey]);
	                                        }
	                                        else
	                                        {
	                                                $this->debug_log("Was going to ban " . $klineme . " for repeating shit.");
							unset($tmparrayb[$klinekey]);
	                                        }

					}						
					$this->last_said_speakers = $tmparrayb;
				}
				if($this->last_said_count >= 4 && $this->last_said_flag == 1 && $this->nicklist[strtolower($this->sender)]['time'] > time() - 300)
				{
                                        $this->check_nicklist($this->sender);
					$this->kline($this->sender, "Spamming (User: ". $this->sender .") This is an automated ban");
				}
					
			} else {
				$this->last_said_speakers = array();
				$this->last_said_count = 0;
				$this->last_said_flag = 0;
			}

			$this->last_said = $said;
		}
			
		$this->log_write($cleandata);
                 echo($cleandata). "\n";
                        if($i = substr_count($data, "\x7f")) {
				if($i > 20) {
					if($this->active) {
						$this->kline($this->sender, "Spammer", "+7d", "$i UTF-8 deletes detected. ($cleandata)");
					}
                                }
	                }
			foreach ($this->kline_trigger as $trigger)
			{
				if(substr_count($data, $trigger))
				{
					if($this->active && !$this->op)
					{
						$this->kline($this->sender, "Spammer", "+90d", "Trigger");
					}
					else
					{
						$this->send_privmsg("#cctest", "[Automated Message] Was going to ban " . $this->sender . " for saying a trigger word $trigger");
					}
			
				}
			}

                        if(count($this->ex) >= 3 && ($this->ex[1] == "JOIN" || $this->ex[1] == "PART" || $this->ex[1] == "QUIT"))
			{
                                $this->handle_joinpart();
			}
            }

                switch ($command) //List of commands the bot responds to from a user.
                {

			/*
			case ":.debugcheckaline":
				$startime = microtime(TRUE);
				$this->kline("CCTest123", "Test123", "+2m", "Test ban, this shouldn't actually ban them", 1);
				$endtime = microtime(TRUE);
				$total = $endtime - $startime;
				$this->send_privmsg($this->cmd_chan, "Testkill took: $total microseconds ($endtime - $startime)");
					
			break;
			*/
			case":my":
				if(count($this->ex) > 7 && strtolower($this->ex[4]) == "new" && strtolower($this->ex[5]) == "hot" && strtolower($this->ex[6]) == "video") {
					$this->kline($this->sender, "Please stop spamming us with your video, thanks in advance. This is an automated ban.", "+7d", "Video spammer");
				}
			break;

			case":allah":
				if(count($this->ex) == 6 && strtolower($this->ex[4]) == "is" && trim(strtolower($this->ex[5])) == "doing" && $this->nicklist[strtolower($this->sender)]['time'] > time() - 300)
				{
					$this->check_nicklist($this->sender);
					//$this->send_privmsg($this->cmd_chan, "Please don't spam, thanks in advance.");
					$this->kline($this->sender, "Joining large channels to spam a message and then leaving is highly discouraged.  This is an automated ban (User: " .$this->sender .")", "+90d", "Allah spammer");
				}
			break;

			case":call":
				if(count($this->ex) == 8 && strtolower($this->ex[6]) == "radio" && strtolower($this->ex[5]) == "l0de" && $this->nicklist[strtolower($this->sender)]['time'] > time() - 1800) {
					$this->kline($this->sender, "Spam elsewhere. (User: " .$this->sender .")", "+90d", "L0DE RADIO spammer");
				}
			break;

/*
			case":9":
				
				if(count($this->ex) == 17 && $this->nicklist[strtolower($this->sender)]['time'] > time() - 300) 
				{
					$check = $this->rebuildParts($this->ex, 4,17);
					if($check = "11 attacks, Did USA do it itself or it just let it happen?"
					$this->check_nicklist($this->sender);
					$this->send_privmsg($this->cmd_chan, "Please don't spam, thanks in advance.");
					$this->kline($this->sender, "Joining large channels to spam a message and then leaving is highly discouraged.  This is an automated ban (User: " .$this->sender .")", "+90d", "Allah spammer");
				}

			break;

*/
			case":a/s/l?":
			case":a/s/l":
			case":asl":
			case":asl?":
		            if ($this->cmd_chan != "#idlerpg" && $next_response <= time()) {
			                $next_response = time() + 30;
					$this->send_privmsg($this->cmd_chan, "14/F/935 Pennsylvania Avenue, NW.  Washington, D.C");
				}
			break;

			case":!gameserv":
			case":!botserv":
				if ($this->cmd_chan != "#idlerpg" && $next_response <= time()) {
					$next_response = time() + 30;
					$this->send_privmsg($this->cmd_chan, "To get botserv to allow you to roll in channel you need to enable fantasy mode on botserv and tell gameserv to not ignore your channel: /msg gameserv set ignore #CHANNEL off");
					$this->send_privmsg($this->cmd_chan, "Then set the appropriate rank to be able to use the command, for everyone type:  /msg chanserv levels #CHANNEL set Fantasia -1");
				}
			break;

			case":!redirect":
					$recepient = trim($this->ex[4]);
				if(count($this->ex) > 4 && ($this->op || ($next_response <= time() && substr($recepient, 0, 1) != "#"))) {
					$next_response = time() + 30;
					$recepient = trim($this->ex[4]);
					$this->send_explanation($recepient);
				}
			break;


			case":!banevade":
				if(strtolower($this->sender) == "ccomp5950") {
                                        $badperson = $this->ex[4];

                                         $this->kline($badperson, "Ban Evasion, please do not join channels you have found yourself unwelcome in", "+7d", "Requested");
                                }
			break;
			case":!akill":
			case":!kline":
				if(strtolower($this->sender) == "ccomp5950") {
					$reason = "";
					$badperson = $this->ex[4];
					$time = "+7d";
					if(count($this->ex) >= 5)
					{
						if(is_numeric(trim($this->ex[5]))) 
						{
							$time = "+". $this->ex[5] . "d";
							if(count($this->ex) > 6)
								$reason = implode(" ", array_slice($this->ex, 6));
						}
						else 
						{
							$time = "+7d";
							if(count($this->ex) > 5)
								$reason = implode(" ",array_slice($this->ex, 5));
						}
					}
					if($reason == "") 
						$reason = "You have been banned";	
					$reason = trim($reason);
					$this->kline($badperson, $reason . " (User: $badperson)", $time, "Requested");
					//$this->send_privmsg($this->cmd_chan, "Badperson: $badperson, Reason: $reason, Time: $time");
					
				}
			break;
                        case":!spambot":
                                if(strtolower($this->sender) == "ccomp5950") {
                                        $badperson = $this->ex[4];

                                         $this->kline($badperson, "Possible Spambot / Proxy Server.  Nick: $badperson", "+90d", "Possible Spambot / Proxy Server. Nick: $badperson");
                                }
                        break;

			case":!ping":
				if((count($this->ex) >= 5)) 
				{
	                                $this->check_nicklist($this->sender);
	                                $this->nicklist[$this->sender]["naughty"] += 1;
	                                $this->nicklist[$this->sender]["reason"] = "Spamming";
	                                $this->nicklist[$this->sender]["realReason"] .= " !ping";
					$this->nicklist[$this->sender]["time"] = time();
	                                $this->check_naughty($this->sender);
				}
			break;

			case ":.modezoff":
				if(strtolower($this->sender) == "kobok") {
					$this->send_data("MODE", "ccomp5950 -z");
					$this->send_privmsg($this->cmd_chan, "Done");
				}
			break;
                        case ":.modezon":
                                if(strtolower($this->sender) == "kobok") {
                                        $this->send_data("MODE", "ccomp5950 +z");
                                        $this->send_privmsg($this->cmd_chan, "Done, thanks.");
                                }
                        break;


			case ":.debugshowlist":
				if(strtolower($this->sender) == "ccomp5950") {
					$message = "\n\n NickList: " . var_export($this->nicklist, TRUE) . "\n\n";
					echo $message;
					$this->debug_log($message); 
				}
			break;

			case ":.fuckingwork":
			case ":.akilltoggle":
			case ":.killtoggle":
			case ":.toggle":
			case ":.toggleakill":			
			case ":.togglekill":
				if($this->op) {
					$message = "Activated";
					if($this->active) $message = "Deactivated";
					$this->send_privmsg($this->cmd_chan, "Autokill is $message");
					$this->active = $this->active ? 0 : 1;
				}
			break;
			
			

                }
                //switch($command);


            } //if(con && $data != "")

            // Here we check if we need to remove naughty folks from the listing.  Checks every 5 minutes.

            if ($next_check <= time()) {
                $next_check = time() + 300;

		foreach($this->nicklist as $nick => $values) {
			if($values['naughty'] > 0 && time() - 1800 > $values['time']) {
				$message = "Removing ". $nick ."'s naughty points, was ". $values['naughty']. " ( ". $this->nicklist[$nick]['realReason'] . " )";
				$this->debug_log($message);
				echo $message;
				$this->nicklist[$nick]['naughty'] = 0;
				$this->nicklist[$nick]['reason'] = "";
				$this->nicklist[$nick]['realReason'] = "";
			}
				
									
		}
		//$this->checkUserCount();
            }

        } //while(1==1);

    } //function main();


    // Functions - here is where the actual work gets done on events.


    

    function kline($nick, $reason, $time="+7d", $realReason = "Bot Net", $dryrun = 0)
    {
			$this->under_attack = time() + 300;
			    global $ops;	
			$nick = strtolower($nick);
			if(in_array($nick, $ops) || in_array($nick, $this->kline_exempt)) return;
			if(array_key_exists($nick, $this->nicklist))
			{
				$ip = $this->nicklist[$nick]['ip'];
			} else {
				if(!$ip = $this->getIP($nick))
					return;
			}
			if(array_key_exists($ip, $this->kline))
				return;
			array_push($this->kline, $ip);

			$reason = trim($reason);
			$this->debug_log("Going to ban $ip // $nick // $realReason");
			$ip = "*@" . $ip;
			$this->send_data("operserv", "AKILL ADD $time $ip $reason.  If you think this is in error or require further assistance, please email kline@sorcery.net with this entire error message.");
                        
    }


/*
    function rebuildParts($array, $start, $stop) {
	    $i = $start;
	    $string = "";
	    for($i = $start, $i <= $stop, $i++) {
		$string .= $array[$i] . " ";
	    }
	    $string = strtolower(trim($string));
	    return $string;
    }
*/

    function check_naughty($nick)
    {
	if($this->nicklist[$nick]["naughty"] >= 5)
		if($this->active) {
			$this->kline($nick, $this->nicklist[$nick]["reason"], "+90d", $this->nicklist[$nick]["realReason"]);
		} else {
			$this->send_privmsg("ccomp5950", "$nick exceeded naughty listing, bot not active to kline");
		}
    }

    function checkUserCount() 
    {
	$this->send_data("LUSERS");
		$local = 0;
		$global = 0;
		$processing = 1;
		$loops = 0;
		while($processing) {
			if($loops++ > 400)
				break;
			if(!$newline = fgets($this->socket, 1024)) {
				usleep(50);
				continue;
			}
			$exploded = explode(' ', $newline);
			if($exploded[1] == "265") 
			{
				$local = $exploded[3];
			}
			else if($exploded[1] == "266")
			{
				$global = $exploded[3];
			}
			else if($exploded[1] == "250") 
			{
				$processing = 0;
			}
		}
	$this->log_users("$local $global");
    }

    function getIP($nick)
    {
	 $this->send_data("WHOIS", $nick);
                        $hostmask = 0;
                        $ipline = 0;
                        $ip = 0;
                        $loops = 0;
                        while($ipline == 0) {
                                if($loops++ > 40000)
                                        break;
                                if(!$newline = fgets($this->socket, 1024)) {
                                        usleep(50);
                                        continue;
                                }
                                $exploded = explode(' ', $newline);
                                if($exploded[1] == "311") {
                                        $hostmask = "*!*@" . $exploded[5];
                                }
                                if($exploded[1] == "378") {
                                        $ip = $exploded[count($exploded)-1];
                                        $ipline = 1;
                                }
                                if($exploded[1] == "318") {
                                        break;
                                }
                        }
                        $ip = trim($ip);
			return $ip;

    }

    function handle_joinpart()
    {
	// array (nick => array( #channel => microtime of last action, 'naughty' => 0));
	$channel = trim($this->ex[2]);
	$microtime = microtime(TRUE);
	if(!isset($this->joined[$this->sender])) {
		$this->joined[$this->sender] = array ("naughty" => 0, "last_infraction" => 0);
	}
	if($this->ex[1]=="QUIT" || $this->joined[$this->sender]["last_infraction"] + 300 < $microtime)
	{
		//Reset naughty if they haven't been naughty in a while.
		$this->joined[$this->sender]["naughty"] = -2;
		$this->joined[$this->sender]["last_infraction"] = 0;
	}
	if(isset($this->joined[$this->sender][$channel])) {
		if($this->joined[$this->sender][$channel] + 3 > $microtime) {
			#If we are being joinpart flooded then we need to speed up reaction times.
			$this->under_attack = time() + 300;

			$this->joined[$this->sender]["naughty"]++;
			$this->joined[$this->sender]["last_infraction"] = $microtime;
			if($this->joined[$this->sender]["naughty"] > 3)
			{
				$this->check_nicklist($this->sender);
				$this->nicklist[$this->sender]["naughty"] += 2;
				$this->nicklist[$this->sender]["reason"] = "Join Spamming";
				$this->nicklist[$this->sender]["realReason"] .= "Join Spamming";
				$this->check_naughty($this->sender);				
			}
		}
	}
	$this->joined[$this->sender][$channel] = $microtime;
	if($this->ex[1] == "JOIN" && strpos($this->sender, "ebchat_") && strpos($channel, "square")) {
		$this->send_explanation($this->sender);
	}
    }
    function send_explanation($recepient) {
                $this->send_notice($recepient, "Hi $recepient, most likely you joined IRC by clicking a link expecting it to take you to a themed channel for say a game or some other interest.  Unfortunately that link did not work and you find yourself in the IRC Network's general help channel.  If you would like to join a different channel please type /join #ChannelNameHere");
                $this->send_notice($recepient, "Channels may be something like #rotmg (realm of the mad god).  You can probably find the channel name listed near the link you clicked.  Otherwise you can use https://search.mibbit.com/channels/Sorcery");

                $this->send_notice($recepient, "This is an automatic message, feel free to ask any questions in #square.  And welcome to SorceryNet IRC.");
                $this->debug_log("[". $recepient ."] just received the welcome message");
    }

    function check_nicklist($nick) 
    {
	$nick = trim(strtolower($nick));
	if(isset($this->nicklist[$nick]))
		return;
	$time = time();
	$this->nicklist[$nick] = array('ip' => $this->getIP($nick), 'time' => $time, 'usv' => '', 'naughty' => 0, 'reason' => "", 'realReason' => "");
    }

    function process_new_join()
    {
	
	$conn_key = 0;
	$usv_start = 6;
	$usv_end = 0;
	if(count($this->ex) < 8)
		return;
	if($conn_key = array_search("connected", $this->ex))
	{
		if(substr($this->ex[$conn_key - 1], 0, 1) == "[")
		{	//IP in brackets
		$hostmask = $this->ex[4];
		$ip = trim($this->ex[$conn_key - 1], "][");
		$usv_end = $conn_key - 2;
		}

		else
		{
		//IP part of hostmask
		$hostmask = $this->ex[4];
		$ip = explode("@", $hostmask)[1];
		$usv_end = $conn_key - 1;
		}
	}
	else if($this->ex[6] == "changed" && $this->ex[7] == "nick")
	{
		$hostmask = strtolower($this->ex[4]);
		$nick = explode("!", $hostmask)[0];
		$newnick = trim(strtolower($this->ex[9]));
		if(array_key_exists($nick, $this->nicklist)) { 
			$this->nicklist[$newnick] = $this->nicklist[$nick];
			unset($this->nicklist[$nick]);
		} else {
			$ip = $this->getIP($newnick);
			$naughty = 0;
			$time = time();
			$reason = "";
			$realReason = "";
			$usv = "";
			$this->nicklist[$newnick] = array('ip' => $ip, 'time' => $time, 'usv' => $usv, 'naughty' => $naughty, 'reason' => $reason, 'realReason' => $realReason);
		}
		return;
	}
	else if($this->ex[6] == "disconnected")
	{
		$hostmask = strtolower($this->ex[4]);
                $nick = explode("!", $hostmask)[0];
		if(array_key_exists($nick, $this->nicklist))	
			unset($this->nicklist[$nick]);
		return;
	}
	else return;

	$realReason = "";
	$reason = "";
	$naughty = 0;
	$time = time();
	
	$usv = "";
	if($usv_start != $usv_end) {
		for($i = $usv_start; $i <= $usv_end; $i++) {
			$usv .= $this->ex[$i];
		}
	} else {
		$usv = $this->ex[$usv_start];
	}

	$usv = strtolower(trim($usv, ")("));
	$nick = trim(explode("!",$hostmask)[0]);	
	$username = explode("!", $hostmask)[1];
	$host_domain = trim(explode("@", $username)[1]);
	$username = trim(explode("@", $username)[0]);

	$spambotCheckPoints = 0;
	if($spambotCheckPoints = $this->spambotcheck($usv, $username, $nick, $host_domain)) 
	{
		if($spambotCheckPoints == 9001) {
			$this->send_privmsg("#services", "Oh hey look it's Saiko again ^^^ ($nick)");
			$reason = "Harrassment and abuse will not be tolerated on SorceryNet.";
			$realReason = "Saiko";
			$nickarray = array ('ip' => $ip, 'time' => $time, 'usv' => $usv, 'naughty' => $naughty, 'reason' => $reason, 'realReason' => $realReason);
			$this->kline($nick, "$reason ($nick)", "+3d", "Bot Net");
		}


                if($spambotCheckPoints == 2001) {

                         $this->send_privmsg("#services", "$nick is a ban evader");
                         $reason = "Ban Evasion";
                         $realReason = "Ban Evasion ($nick)";
                         $nickarray = array ('ip' => $ip, 'time' => $time, 'usv' => $usv, 'naughty' => $naughty, 'reason' => $reason, 'realReason' => $realReason);
			 $this->nicklist[$nick] = $nickarray;
			 $this->kline($nick, "Ban Evasion will not be tolerated.", "+90d", "BanEvasion");
			return;
		}

		if($spambotCheckPoints == 1337) {
		
			 $this->send_privmsg("#services", "$nick IS A BOT SPAMMER");
			 $reason = "Bot Spammer";
                         $realReason = "Bot Spammer";
			 $nickarray = array ('ip' => $ip, 'time' => $time, 'usv' => $usv, 'naughty' => $naughty, 'reason' => $reason, 'realReason' => $realReason);
			 $this->kline($nick, "The IP address associated has been used for suspected Bot Net activity ($nick)", "+90d", "Bot Net");
			 return;
		}
                if($spambotCheckPoints == 9002) {
			$this->send_privmsg("#services", "$nick is very likely a freenode spammer, banning");
			$reason = "Bot Spammer";
			$realReason = "Bot Spammer";
			$nickarray = array ('ip' => $ip, 'time' => $time, 'usv' => $usv, 'naughty' => $naughty, 'reason' => $reason, 'realReason' => $realReason);
			$this->nicklist[$nick] = $nickarray;
			$this->kline($nick, "The IP address associated has been used for suspected Bot Net activity ($nick)", "+90d", "Freenode Spammer");
			return;
		}
			
		if($spambotCheckPoints > 6)
			$naughty += 4;
		$this->send_privmsg("#services", "$nick IS A POSSIBLE SPAMMER // Match: username and realname match pattern of recent spambots.  (SpambotCheckScore: $spambotCheckPoints)");
		$naughty += 4;
		$reason = "Spammer (2).";
		$realReason = "username/realname ($username / $usv) (spambotcheck score of $spambotCheckPoints)";
		if($this->active && strlen($nick) == 10 && !substr_count($nick, "guest") && $nick != "hrolfphone" && $nick !="tarikgarro") {
			$nickarray = array ('ip' => $ip, 'time' => $time, 'usv' => $usv, 'naughty' => $naughty, 'reason' => $reason, 'realReason' => $realReason);
			$this->kline($nick, "The IP address associated has been used for suspected Bot Net activity ($nick)", "+90d", "Bot Net");
			return;
			}

	}

	$nickarray = array ('ip' => $ip, 'time' => $time, 'usv' => $usv, 'naughty' => $naughty, 'reason' => $reason, 'realReason' => $realReason);

	$this->nicklist[$nick] = $nickarray;
	$this->check_naughty($nick);

    }

    function count_capitals($s) {
	$lowercase = mb_strtolower($s);
	return strlen($lowercase) - similar_text($s, $lowercase);
    }

    function spambotcheck($usv, $username, $nick, $domain)
    {

        if($usv === $username && strpos($domain, "compute.amazonaws.com")) return 9002;

	//echo "\n\n$usv // $username // $nick // $domain\n". strpos($usv, "rcerynetkiwiwebchat")  ." // ".$this->count_capitals($nick) ." // ". strlen($username)  ."\n\n\n";	

/*	
	if(strlen($username) == 11 && (strpos(strtolower($username), "elth") !== FALSE || $this->count_capitals($nick) >= 3) && strpos($usv, "rcerynetkiwiwebchat") !== FALSE)
		return 2001;
*/
	if(strpos($nick, "[linux]") === 0)
		return 1337;
/*	if(strpos($username, "saiko") === 0)
		return 9001;
*/
	if(strpos($nick, "penny_lane") === 0)
		return 2001; // Ban evasion.
	if(strpos($nick, "D33P-B00K-1WM_SU_GK") === 0)
		return 42;
	$points = 0;
	$usv = strtolower($usv);
	$username = strtolower($username);
	if(strlen($usv) == 2 && strlen($username) == 2 && $usv != $username) 
	{	
		return 5;  // automatic 5 points
	}
	if($usv =='""' && $username == "rebel")
	{
		return 10;
	}
	if($usv=="*?*") return 10;


	$tests = array("sexy", "hot", "red", "one", "super", "blue", "the", "its", "di", "i", "la");
	foreach($tests as $test)
	{
		if($username == $test) $points++;
		if($usv == $test) $points++;
	}
	if($points && strlen($usv) == 2) $points++;
	if($points && strlen($username) == 2) $points++;


	return $points;
    }


    function send_privatepaste($sendme)
    {
        global $config;
        $sendme = rawurlencode(utf8_encode($sendme));
        $post = "?paste_lang=text&paste_data=$sendme&mode=json&api_submit=1";
        $data_length = strlen($post);

        if ($config['debug'] == 2) echo "Sending $post \n";

        $connection = fsockopen('paste.alwaysdedicated.net', 80);

        //sending the data
        fputs($connection, "POST / HTTP/1.1\r\n");
        fputs($connection, "Host: paste.alwaysdedicated.net \r\n");
        fputs($connection, "Origin: http://paste.alwaysdedicated.net\r\n");
        fputs($connection, "Content-Type: application/x-www-form-urlencoded\r\n");
        fputs($connection, "Content-Length: " . $data_length . "\r\n");
        fputs($connection, "Connection: close\r\n\r\n");
        fputs($connection, $post);

        $gotit = 0;
        $json = "";
        while ($response = fgets($connection)) {
            if ($config['debug'] == 2) var_dump($response);
            if (strlen($response) == 2 && substr($response, 0, 1) == "{") {
                $json = $response;
                $gotit = 1;
            } else if ($gotit) {
                $json .= $response;
            }
        }
        //closing the connection
        fclose($connection);
        if (!$gotit)
            return "None";
        $response = json_decode($json, TRUE);
        $response = "http://paste.alwaysdedicated.net/" . $response["result"]["id"];
        return $response;


    }

    function send_privmsg($chan, $msg = null)
    {
        global $quiet_chan;
        if ((!empty($quiet_chan) && in_array($chan, $quiet_chan)) || is_null($msg))
            return;
        $this->send_data("PRIVMSG", $chan . " :" . $msg);

    }

    function send_notice($chan, $msg = null)
    {
        $this->send_data("NOTICE", $chan . " :" . $msg);
    }

    function send_data($cmd, $msg = null) //Sends data to the IRC server
    {
        global $config;

        if ($msg == null) {

            fputs($this->socket, $cmd . "\r\n");

            if ($config['debug']) echo $cmd . "\n";

        } else {

            fputs($this->socket, $cmd . ' ' . $msg . "\r\n");

            if ($config['debug']) echo $cmd . " " . $msg . "\r\n";

        }

    }


    function join_channel($channel, $force=0) //Joins a channel, used in the join function.

    {
	$join = "JOIN";
	if($force) $join = "OJOIN";
        if (is_array($channel)) {

            foreach ($channel as $chan) {

                $this->send_data($join, $chan);

            }

        } else {

            $this->send_data($join, $channel);

        }

    }


    function protect_user($user = '')

    {

        if ($user == '') {

            $user = strstr($this->ex[0], '!', true);

        }


        $this->send_data('MODE', $this->ex[2] . ' +a ' . $user);

    }


    function identify($password)
    {
        $this->send_data('PRIVMSG', 'nickserv :identify ' . $password);
    }


    function op_user($channel = '', $user = '', $op = true)
    {

        global $ops;

        if ($channel == '' || $user == '') {

            if ($channel == '') {

                $channel = $this->ex[2];

            }


            if ($user == '') {

                $user = strstr($this->ex[0], '!', true);
            }


        }


        $clean_sender = str_replace(":", "", strstr($this->ex[0], '!', true));
        $clean_user = str_replace(":", "", $user);

        if ($op) {
            if (in_array(strtolower($clean_sender), $ops)) {
                $this->send_data('MODE', $channel . ' +o ' . $user);
            } else {
                $this->send_data('PRIVMSG', $channel . " : Cannot op " . $clean_user . ", " . $clean_sender . " is not in my whitelist.");
            }

        } else {

            if (in_array(strtolower($clean_sender), $ops)) {
                $this->send_data('MODE', $channel . ' -o ' . $user);
            } else {
                $this->send_data('PRIVMSG', $channel . " : Cannot deop " . $clean_user . ", " . $clean_sender . " is not in my whitelist.");
            }

        }

    }

    function clean_string($msg = ":,")
    {
        $msg = str_replace(":", "", $msg);
        $msg = str_replace(",", "", $msg);
        $msg = str_replace("\n", "", $msg);
        $msg = str_replace("\r", "", $msg);
        return $msg;
    }

    function clean_array($msg = null, $start = 0, $stop = 0)
    {
        if (is_array($msg)) {
            if (!$stop)
                $stop = count($msg) - 1;

            $output = array_slice($msg, $start, $stop);
            return $output;
        } else {
            return NULL;
        }

    }

    function format_ops($ops = null)
    {
        global $max_message_length;
        $i = 0;
        $j = 1;
        $message = array("The following people have operator status: ", "", "");
        sort($ops);
        foreach ($ops as $player) {
            if (strlen($message[$i] . $player . ", ") > $max_message_length) {
                if ($i == 2) {
                    $message[$i] .= "...";
                    break;
                } else {
                    $j = 1;
                    $i++;
                }
            }
            if ($j != 1) {
                $message[$i] .= ", ";
            }
            $message[$i] .= "(" . substr($player, 0, 1) . ")" . substr($player, 1);
            $j++;
        }
        return $message;
    }


    function reconnect()
    {
        global $config, $identified;
        echo "ERROR: Lost connection to IRC server\n\n\n";
        $i = 30;
        while ($i != 0) {
            echo "Reconnecting after " . $i . " seconds...                   \r";
            $i--;
            sleep(1);
        }
        echo "                                                                    \r\n\n";
        if ($this->socket != false) {
            socket_shutdown($this->socket);
            socket_close($this->socket);
        }
        if ($this->socket = fsockopen($config['server'], $config['port'])) ;
        {
            stream_set_timeout($this->socket, 0, 5000); // .5 seconds = 500000 microseconds
            $this->login($config);
            $identified = 0;
            return 1;
        }
    }

    function log_write($logEntry) {
        global $config;
        $file = $config['logfile'];
        $logEntry = trim($logEntry);
        $logEntry .= "\n";
        $logEntry = "[". date("Y-m-d H:i:s") ."]". $logEntry;
        file_put_contents($file, $logEntry, FILE_APPEND);
    }

    function log_users($logEntry) {
        $file = "usercount.log";
        $logEntry = trim($logEntry);
        $logEntry .= "\n";
        $logEntry = "[". date("Y-m-d H:i:s") ."]". $logEntry;
        file_put_contents($file, $logEntry, FILE_APPEND);
    }

    function debug_log($logEntry) {
        $file = "debug.log";
        $logEntry = trim($logEntry);
        $logEntry .= "\n";
        $logEntry = "[". date("Y-m-d H:i:s") ."]". $logEntry;
        file_put_contents($file, $logEntry, FILE_APPEND);
    }

}

echo "KillBot starting\n\n";
$bot = new IRCBot($config);
$bot->log_write("Started KillBot");
file_put_contents("debug.log", "[". date("Y-m-d H:i:s") ."] Started Killbot\n");
