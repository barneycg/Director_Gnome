#!/usr/bin/php5
<?php
/**
 * Director Gnome
 *
 * Copyright (c) 2012-2013, Barney Garrett.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * * Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright
 * notice, this list of conditions and the following disclaimer in
 * the documentation and/or other materials provided with the
 * distribution.
 *
 * * Neither the name of Barney Garrett nor the names of his
 * contributors may be used to endorse or promote products derived
 * from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRIC
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 */

error_reporting(E_ALL ^ E_NOTICE);

$config = parse_ini_file("dg.ini", true);


// initialize JAXL object with initial config

require_once './JAXL/jaxl.php';

$client = new JAXL(array(
	'jid' => $config['authentication']['jid'],
	'pass' => $config['authentication']['password'],
	'priv_dir' => './JAXL/.jaxl',
	'resource' => 'Director Gnome',
	'auth_type' => 'DIGEST-MD5',
	'log_level' => JAXL_INFO,
	'log_path' => '/var/log/jaxl.log',
	'strict'=>FALSE	
));

$client->manage_subscribe = "mutual";

// callback functions

$client->add_cb('on_auth_success', function() {
	global $client;
	echo "got on_auth_success cb, jid ".$client->full_jid->to_string()."\n";

	// set status
	$client->set_status("available!", "Online", 10);

	// fetch roster list
	$client->get_roster();
});

$client->add_cb('on_auth_failure', function($reason) {
	global $client;
	$client->send_end_stream();
	echo "got on_auth_failure cb with reason $reason\n";
});

$client->add_cb('on_chat_message', function($stanza) {
	global $client,$config;

	$from=preg_replace('/\/.*$/', '', $stanza->from);
	$from=preg_replace('/@.*$/','',$from);
	
	if (strlen($stanza->body)>0 && $from!='director_gnome')
	{
		$now = gmdate('Y-m-d H:i:s EVE');
		$pdo = new PDO("mysql:host=".$config['mysql_openfire']['host'].";dbname=".$config['mysql_openfire']['db_name'], $config['mysql_openfire']['user'], $config['mysql_openfire']['password']);
		$pdo2 = new PDO("mysql:host=".$config['mysql_misc']['host'].";dbname=".$config['mysql_misc']['db_name'], $config['mysql_misc']['user'], $config['mysql_misc']['password']);
		
		$groups_sql = $pdo->prepare('select groupName from ofGroupUser where username = :un');
		$groups_sql->execute(array(':un'=>$from));
		$group_list = $groups_sql->fetchAll(PDO::FETCH_COLUMN, 0);
		
		$a_groups_sql = $pdo2->prepare('select groupName from of_broadcast_mapping where username = :un');
		$a_groups_sql->execute(array(':un'=>$from));
		$a_group_list = $a_groups_sql->fetchAll(PDO::FETCH_COLUMN, 0);
		
		$ignore_sql = $pdo2->prepare('select username from of_broadcast_ignore where username = :un');
		$ignore_sql->execute(array(':un'=>$from));
		$ignore = $ignore_sql->fetchAll(PDO::FETCH_COLUMN, 0);
		
		$pdo = null;
		$pdo2 = null;
		
		if (empty($ignore) && (array_search("alliance_officers",$group_list) || array_search("fc",$group_list) || array_search("hc",$group_list) ))
		{
			$allowed = TRUE;
		}
		else
		{
			$allowed = FALSE;
		}

		if (preg_match("/::/",$stanza->body) != 0)
		{
			list($group,$body) = explode("::",$stanza->body,2);

			$g_allowed=array_search($group,$group_list);
			
			$a_allowed=array_search("hc",$group_list) ? TRUE : array_search($group,$a_group_list);
			if ($a_allowed!==FALSE)
				$allowed = TRUE;
		}
		else
		{
			$body = $stanza->body;
			$group="all";
			$g_allowed = TRUE;
		}

		if($allowed!==FALSE && ($g_allowed!==FALSE || $a_allowed!==FALSE) ) 
		{
			// echo back the incoming message

			if (preg_match('/^\?OTR/',$stanza->body))
			{
				$message = "**** Turn off OTR you muppet ****\n";
				$stanza->to = $from;
			}
			else
			{
				$from=preg_replace('/@.*$/','',$from);
				$now = gmdate('Y-m-d H:i:s EVE');
				list($group,$body) = explode("::",$stanza->body,2);
				if (empty($body))
				{
					$body = $group;
					$group="all";
				}	
				$message = "**** This was broadcast by " . $from . " at " . $now . " ****\n\n";
				$message .= $body;
				$message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');
				$message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8',false);
				$message = preg_replace('/(&nbsp;)*/', '', $message);
				$message = preg_replace('/(&hellip;)*/', '', $message);
				$message .= "\n\n**** Message sent to the ".$group." Group ****\n";
				$stanza->to = $group.'@broadcast.lawnalliance.org';
			}
			if (preg_match('/\*\*\*\* This was broadcast by (.*)? \*\*\*\*\n\\\\reconnect-dg$/',$message))
			{
				$client->end_stream();
			}
			else
			{
				$msg = new XMPPMsg(array('type'=>'chat', 'to'=>$stanza->to, 'from'=>$stanza->from), $message);
				$client->send($msg);
			}
			if ($a_allowed !== FALSE)
			{
				$message = "Message sent to the ".$group." group\n";
				$msg = new XMPPMsg(array('type'=>'chat', 'to'=>$stanza->from, 'from'=>$stanza->from), $message);
				$client->send($msg);
			}
		}
		else
		{
			$message = "Sorry you are not authorised to do that\n";
			$msg = new XMPPMsg(array('type'=>'chat', 'to'=>$stanza->from, 'from'=>$stanza->from), $message);
			$client->send($msg);
		}
	}
});


$client->add_cb('on_disconnect', function() {
	echo "got on_disconnect cb\n";
});

// finally start configured xmpp stream

$client->start();
?>
