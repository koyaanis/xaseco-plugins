<?php
/*
=======================================================================
Description: Displays best Sectors
Author: DarkKnight, amgreborn
Version: v1.5
Dependencies: plugin.localdatabase.php

Changelog 1.5:
- Added pages to secrecs, fixes issue of displaying a too large window on challenges with many checkpoints
- Removed small/normal window size configuration, secrecs window is now set to a reasonable size
- Removed unecessary code

Changelog 1.4:
- Added /secrecs_cleanupdb chat command to remove duplicate secrecs, old secrecs and faulty secrecs that were caused by previous versions of this plugin

Changelog 1.3:
- Fixed a major bug where it would assume that the gameservers database name is called "aseco"
- Fixed a severe bug where deleting secrecs wouldn't delete the entries from MySQL
- Fixed a heavy bug where improving your own secrecs would overwrite everyone elses own secrecs for that sector with your name and time (many duplicates in database)
- Fixed a minor bug where incorrect times would register (known as the sector 0 bug)

- MySQL entries of a track will now be deleted if the track was removed from the server, can be turned off in bessecs.xml

- added /delsec chat command to delete single or multiple secrecs at once

=======================================================================
*/

Aseco::registerEvent("onCheckpoint", "checkCP");
Aseco::registerEvent("onNewChallenge", "init_sec");
Aseco::registerEvent("onPlayerFinish", "sec_finish");
Aseco::registerEvent('onSync', 'secrecs_sync');
Aseco::addChatCommand('secrecs', 'Shows Sector Records');
Aseco::addChatCommand('mysecrecs', 'Shows own Sector Records');
Aseco::addChatCommand('delsecs','Deletes all records on this challenge (both own and all secrecs)');
Aseco::addChatCommand('delsec','Deletes 1 record or a range of records on this challenge (both own and all secrecs)');
Aseco::addChatCommand('secrecs_cleanupdb','Deletes all database secrecs whos tracks are deleted from server, deletes empty ChallengeIDs and removes secrecs_own duplicates. Could take some minutes to execute');
Aseco::registerEvent('onPlayerConnect', 'secrecs_npl');
Aseco::registerEvent('onPlayerConnect', 'nouseButtonOn');
Aseco::registerEvent('onPlayerManialinkPageAnswer', 'nouseButtonHandleClick');
Aseco::registerEvent('onTracklistChanged', 'removeDbSecsOnTrackDeletion');

// fastest player of each sector is stored in here as object. example $tabs_sec_recs[2]->login yields login of player who has fastest sector 2 time
$tab_sec_recs;
// used to save info about previous CP. differences between checkpoints are calculated this way
$lastCP = array();
// simple array which just lists the best sector times of all players.
$challengeNow = "";
// contains XML infos
$secrecsconfig;
$chatz;
$chatz_own;
// all driven sector times of all players. this is bigger than $tab_sec_recs. example $tabs_sec_recs[$login][2]->time yields time of player $login at sec 2
$tab_own_recs;
// amount of checkpoints on Challenge. used to properly show secrecs ingame and to prevent false parameters to be given when deleting secrecs
$checkpointAmount;
// enable or disable button widgets "Secrecs" and "My Secrecs". if disabled, players must use /secrecs or /mysecrecs to view the secrecs
$showsecrecs;

class secrecs
{
	var $time;
	var $login;
	var $cp;
	function secrecs($time, $login, $cp)
	{
		$this->time = $time;
		$this->login =  $login;
		$this->cp = $cp;
	}
}

function secrecs_npl($aseco,$command)
{
	global $challengeNow,$aseco;
	load_mysql_own($challengeNow,$command->login);
}

function SecToTime($oldTime,$prefix)
{
	$pre = "";
	if($oldTime < 0 && $prefix)
	{
		$pre = "-";
	}
	else if($prefix)
	{
		$pre = "+";
	}
	$oldTime = abs($oldTime);
	$m = (int) (($oldTime) / 60000);
	$s = (int) ((($oldTime) - $m * 60000) / 1000);
	if(strlen($s) == 1){$s = "0".$s;}
	$cs = (int) (($oldTime - $m*60000 - $s*1000) / 10);
	if(strlen($cs) == 1){$cs = "0".$cs;}
	return $pre."".$m.":".$s.".".$cs;
}

function secrecs_sync($aseco)
{
	global $secrecsconfig;
	$secrecsconfig = simplexml_load_file('bestsecs.xml');
	mysql_query("CREATE TABLE IF NOT EXISTS `secrecs_all` ( `ID` int(255) NOT NULL AUTO_INCREMENT,`ChallengeID` varchar(1000) NOT NULL,`Sector` int(255) NOT NULL,`PlayerNick` varchar(255) NOT NULL,`Time` int(255) NOT NULL,PRIMARY KEY (`ID`)) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=latin1;");
	mysql_query("CREATE TABLE IF NOT EXISTS `secrecs_own` ( `ID` int(255) NOT NULL AUTO_INCREMENT,`ChallengeID` varchar(1000) NOT NULL,`Sector` int(255) NOT NULL,`PlayerNick` varchar(255) NOT NULL,`Time` int(255) NOT NULL,PRIMARY KEY (`ID`)) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=latin1;");
}

function checkCP($aseco, $param)
{
	global $tab_sec_recs,$timez,$cpz,$loginz,$lastCP,$challengeNow,$tab_own_recs,$chatz,$chatz_own;
	
	$loginz = $param[1];
	$timez = $param[2];
	$cpz = $param[4];
	$time2 = 0;
	
	// on xaseco restart, if player is in middle of race and crosses a checkpoint, ignore that sector
	$doUpdate = true;
		
	// successfully registered restart/finish, $lastCP[$loginz] will be empty
	if (!isset($lastCP[$loginz]) && $cpz == 0)
	{
		$time2 = $timez;
	}
	// happens when xaseco restarts and players are in the middle of the race (beyond checkpoint 0). we cannot calculate that secrec
	// unless there are no checkpoints on the map
	else if (!isset($lastCP[$loginz]) && $cpz != 0)
	{
		$doUpdate = false;
		
		$aseco->client->query('GetCurrentChallengeInfo');
		$checkpointAmount = $aseco->client->getResponse()["NbCheckpoints"];
		// no checkpoints on map
		if ($checkpointAmount == 1)
		{
			$time2 = $timez;
			$doUpdate = true;
		}
	}
	// special case scenario, if player has passed a checkpoint, respawns to checkpoint and then restarts track within 5 seconds, the game will not have
	// triggered the onPlayerFinish event, thus $lastCP couldn't properly be cleared
	else if (isset($lastCP[$loginz]) && $cpz == 0)
	{
		$time2 = $timez;
	}
	else if (isset($lastCP[$loginz]) && $cpz != 0)
	{
		$time2 = $timez - $lastCP[$loginz]["cpTime"];
	}
	
	if ($doUpdate)
	{
		// player has claimed a sector
		if((!$tab_sec_recs[$cpz]) || (($time2 < $tab_sec_recs[$cpz]->time) && ($time2 > 0)))
		{
			$oldtime_old = $time2 - $tab_sec_recs[$cpz]->time;
			$tab_sec_recs[$cpz] = new secrecs($time2,$loginz,$cpz);
			$nickt = (mysql_fetch_object(mysql_query("SELECT `NickName` FROM `players` WHERE `Login` = '".$loginz."';")));
			if ($chatz)
			{
				$aseco->client->query('ChatSendServerMessage', $nickt->NickName." \$z\$29fclaimed the record in sector ".$cpz.". Time: ".SecToTime($time2,false));
			}
			update_mysql_all($time2,$cpz,$challengeNow,$loginz,$aseco);
		}
	
		// player has improved a sector
		if(($time2 < $tab_own_recs[$loginz][$cpz]->time  && ($time2 > 0)) || !$tab_own_recs[$loginz][$cpz])
		{
			$tab_own_recs[$loginz][$cpz] = new secrecs($time2,$loginz,$cpz);
			$nickt = (mysql_fetch_object(mysql_query("SELECT `NickName` FROM `players` WHERE `Login` = '".$loginz."';")));
			if($chatz_own)
			{
				$aseco->client->query('ChatSendServerMessageToLogin', "> You improved your record in sector ".$cpz.". Time: ".SecToTime($time2,false),$loginz);
			}
			update_mysql_own($time2,$cpz,$challengeNow,$loginz,$aseco);
		}
	}
	
	$lastCP[$loginz] = array("cpIndex" => $cpz, "cpTime" => $timez);
}

function init_sec($aseco,$ch)
{
	global $tab_sec_recs,$challengeNow,$secrecsconfig,$tab_own_recs,$chatz,$checkpointAmount;
	$challengeNow=$ch->uid;
	
	// update $tabs_sec_recs and $tab_own_recs
	loadDbInfo($aseco);
	
	$aseco->client->query('GetCurrentChallengeInfo');
	$checkpointAmount = $aseco->client->getResponse()["NbCheckpoints"];
	
	setshowsecrecs($aseco);
	setChatEnabled($aseco);
}

function setshowsecrecs($aseco)
{
	global $showsecrecs,$secrecsconfig;
	
	$enabled = $secrecsconfig->window_enabled[0];
	$gmode = $aseco->server->gameinfo->mode;
	$ta = $enabled->TA;
	$rn = $enabled->Rounds;
	$tm = $enabled->Team;
	$cu = $enabled->Cup;
	$la = $enabled->Laps;
	$st = $enabled->Stunts;
	
	if($ta==1 && $gmode==1)
	{
		$showsecrecs = true;
	}
	else if($tm==1 && $gmode==2)
	{
		$showsecrecs = true;
	}
	else if($rn==1 && $gmode==0)
	{
		$showsecrecs = true;
	}
	else if($la==1 && $gmode==3)
	{
		$showsecrecs = true;
	}
	else if($st==1 && $gmode==4)
	{
		$showsecrecs = true;
	}
	else if($cu==1 && $gmode==5)
	{
		$showsecrecs = true;
	}
	else
	{
		$showsecrecs = false;
	}
	
	nouseButtonOn($aseco);
}

// CAREFUL, THE PLAYERFINISH EVENT CAN ONLY UPDATE ONCE EVERY ~5 SECONDS, THIS CAN EASILY LEAD TO MISCALCULATIONS
// FURTHER LOGIC WAS USED ABOVE TO HOTFIX THIS
function sec_finish($aseco, $finish)
{
	global $lastCP;
	$lastCP[$finish->player->login] = null;
}
function update_mysql_all($recordTime,$sector,$challenge,$playerNick,$aseco)
{
	$query = "";
	if(mysql_num_rows(mysql_query("SELECT * FROM secrecs_all WHERE ChallengeID='".$challenge."' AND Sector='".$sector."' LIMIT 1;")) < 1)
	{
		$query = "INSERT INTO secrecs_all (ID,ChallengeID,Sector,PlayerNick,Time) VALUES ('0','".$challenge."','".$sector."','".$playerNick."','".$recordTime."');";
	}
	else
	{
		$query = "UPDATE `secrecs_all` SET `PlayerNick` = '".$playerNick."', `Time` = '".$recordTime."' WHERE ChallengeID='".$challenge."' AND Sector='".$sector."';";
	}
	mysql_query($query);	
}

// the secrecs_own table has several duplicates, this might be the reason for it. for looking into it is required
function update_mysql_own($recordTime,$sector,$challenge,$playerNick,$aseco)
{
	$query = "";
	if(mysql_num_rows(mysql_query("SELECT * FROM secrecs_own WHERE ChallengeID='".$challenge."' AND Sector='".$sector."' AND PlayerNick='".$playerNick."' LIMIT 1;")) < 1)
	{
		$query = "INSERT INTO `secrecs_own` (ID,ChallengeID,Sector,PlayerNick,Time) VALUES ('0','".$challenge."','".$sector."','".$playerNick."','".$recordTime."');";
	}
	else
	{
		$query = "UPDATE `secrecs_own` SET `Time` = '".$recordTime."' WHERE ChallengeID='".$challenge."' AND Sector='".$sector."' AND PlayerNick='".$playerNick."';";
	}
	mysql_query($query);	
}
function load_mysql_all($challenge)
{
	global $tab_sec_recs;
	$tab_sec_recs = array();
	$query = "SELECT * FROM `secrecs_all` WHERE ChallengeID='".$challenge."' ORDER BY Sector;";
	$result = mysql_query($query);
	while($row = mysql_fetch_object($result))
	{
		$tab_sec_recs[$row->Sector] = new secrecs($row->Time,$row->PlayerNick,$row->Sector);
	}
}
function load_mysql_own($challenge,$player)
{
	global $tab_own_recs;
	$tab_own_recs[$player] = array();
	$query = "SELECT * FROM `secrecs_own` WHERE ChallengeID='".$challenge."' AND PlayerNick='".$player."' ORDER BY Sector;";
	$result = mysql_query($query);
	while($row = mysql_fetch_object($result))
	{
		$tab_own_recs[$row->PlayerNick][$row->Sector] = new secrecs($row->Time,$row->PlayerNick,$row->Sector);
	}
}

function chat_secrecs($aseco, $command) {
	global $tab_sec_recs, $challengeNow;
	$player = $command[ "author" ];

	if ( $aseco->server->getGame() == 'TMF' ) {
		load_mysql_all($challengeNow);
		
		$Header = "Sector Records on this Map: ";
		$Message = array( array( 1, $Header, array( 1.25 ), array( "Icons64x64_1", "TrackInfo" ) ) ); // element 0 are header information

		$RecsSize = count( $tab_sec_recs );
		
		if ($RecsSize > 0)
		{
			$Pages = ceil( $RecsSize / 15 ); // page 1 shows sector 0 to 14
			$OverallTime = 0;
			
			$sectorsRead = 0; // because secrecs can be deleted, we must determine on which page we are moving ourself by using this counter
			foreach( $tab_sec_recs as $value )
			{
				$Sector = $value->cp; // "cp" is not the checkpoint, but the sector, and the first sector is 0
				$Page = floor( $sectorsRead / 15 ) + 1; // Page[0] are header infos, Page[1] will be sec 0 to 14, Page[2] 15 to 29..
				$OverallTime += $value->time;

				if ( strlen( $Sector ) == 1 ) {
					$Sector = "0" . $Sector;	
				}

				$PlayerNick = mysql_fetch_object( mysql_query( "SELECT `NickName` FROM `players` WHERE `Login` = '" . $value->login . "';" ) );
				// $Page minimum value is 1.
				$Message[ $Page ][] = array( $aseco->formatColors( "{#highlite}Sec" . $Sector . ": " . SecToTime( $value->time, false ) . " by " . $PlayerNick->NickName . "\n" ) );
				
				$sectorsRead++;
			}

			for ( $i = 0; $i < $Pages; $i++ ) {
				$Message[ 1 + $i ][] = array( "" );
				$Message[ 1 + $i ][] = array( $aseco->formatColors( "{#highlite}Total Time: " . SecToTime( $OverallTime, false ) ) );
			}

		}
		else
		{
			$Message[1][] = array( "" );
		}
		$player->msgs = $Message;
		display_manialink_multi( $player );
	}
}

function chat_mysecrecs($aseco, $command) {
	global $tab_own_recs, $tab_sec_recs, $challengeNow;
	$player = $command["author"];
	
	if ($aseco->server->getGame() == 'TMF') {
		load_mysql_all($challengeNow);
		load_mysql_own($challengeNow, $player->login);
		
		$Header = "Your own Sector Records on this Map: ";
		$Message = array( array( 1, $Header, array( 1.25 ), array( "Icons64x64_1", "TrackInfo" ) ) );

		$RecsSize = count( $tab_own_recs[$player->login] );		
		
		if ($RecsSize > 0)
		{
			$Pages = ceil( $RecsSize / 15 );
			$OverallTime = 0;
			
			$sectorsRead = 0; // because secrecs can be deleted, we must determine on which page we are moving ourself by using this counter
			foreach( $tab_own_recs[$player->login] as $value )
			{
				$Sector = $value->cp;
				$Page = floor( $sectorsRead / 15 ) + 1;
				$OverallTime += $value->time;
				
				$RecTime = $tab_sec_recs[$Sector]->time;
				$RecLogin = $tab_sec_recs[$Sector]->login;
				$RecNick = mysql_fetch_object( mysql_query( "SELECT `NickName` FROM `players` WHERE `Login` = '" . $RecLogin . "';" ) );

				$Diff = ( $value->time - $RecTime );
				if( strlen( $Sector ) == 1 ) { 
					$Sector = "0" . $Sector;					
				}

				$Message[ $Page ][] = array( $aseco->formatColors( "{#highlite}Sec" . $Sector . ": " . SecToTime( $value->time, false ) . " ( " . SecToTime( $Diff, true ) . " to TOP1 " . $RecNick->NickName . " {#highlite}) \n" ) );
				
				$sectorsRead++;
			}
			
			for ( $i = 0; $i < $Pages; $i++ ) {
				$Message[ 1 + $i ][] = array( "" );
				$Message[ 1 + $i ][] = array( $aseco->formatColors( "{#highlite}Total Time: " . SecToTime( $OverallTime, false ) ) );
			}
		}
				else
		{
			$Message[1][] = array( "" );
		}

		$player->msgs = $Message;
		display_manialink_multi( $player );
	}
}

function chat_delsecs($aseco, $command)
{
	global $challengeNow,$tab_sec_recs,$tab_own_recs;
	$admin = $command['author'];
	$login = $admin->login;
	if($aseco->isMasterAdmin($admin) || $aseco->isAdmin($admin))
	{
		mysql_query("DELETE FROM secrecs_all WHERE ChallengeID = '".$challengeNow."';");
		mysql_query("DELETE FROM secrecs_own WHERE ChallengeID = '".$challengeNow."';");
		$tab_sec_recs = array();
		$tab_own_recs = array();
		$aseco->client->query('ChatSendServerMessageToLogin', "> All SecRecs deleted !" , $login);
		
		// update $tabs_sec_recs and $tab_own_recs
		loadDbInfo($aseco);
	}
	else
	{
		$aseco->client->query('ChatSendServerMessageToLogin', "> You must be an Admin to use this command" , $login);
	}
	
}

function chat_delsec($aseco, $command)
{
	global $challengeNow,$tab_sec_recs,$tab_own_recs,$checkpointAmount;
	$admin = $command['author'];
	$login = $admin->login;
	if($aseco->isMasterAdmin($admin) || $aseco->isAdmin($admin))
	{
		$regexMatches = array();
		preg_match_all('/(\d+)/', $command["params"], $regexMatches);
		
		// 0 matches, show quick help
		// 1 match, 1 number found in parameters, delete 1 sector
		// 2 matches, 2 numbers found, delete sectors from number 1 to number 2
		// 3 matches, too many
		$matchesAmount = count($regexMatches[0]);
		
		// get amount of checkpoints on Challenge (finish counts as checkpoint too)
		$aseco->client->query('GetCurrentChallengeInfo');
		$checkpointAmount = $aseco->client->getResponse()["NbCheckpoints"];
		
		if ($matchesAmount == 0)
		{
			$aseco->client->query('ChatSendServerMessageToLogin', "> Usage: /delsec 2; /delsec 3-5" , $login);
		}
		else if ($matchesAmount == 1)
		{
			if ($command["params"] === $regexMatches[0][0])
			{
				$sectorToDelete = $regexMatches[0][0];
				if ($sectorToDelete >= 0 && $sectorToDelete < $checkpointAmount)
				{
					// delete secrec from database
					mysql_query("DELETE FROM secrecs_all WHERE ChallengeID = '".$challengeNow."' AND Sector = ". $sectorToDelete .";");
					mysql_query("DELETE FROM secrecs_own WHERE ChallengeID = '".$challengeNow."' AND Sector = ". $sectorToDelete .";");
					
					// update $tabs_sec_recs and $tab_own_recs
					loadDbInfo($aseco);
					
					$aseco->client->query('ChatSendServerMessage', '> Sector '. $sectorToDelete . ' deleted.');
				}
				else
				{
					$aseco->client->query('ChatSendServerMessageToLogin', "> Please choose a valid sector.", $login);
				}
			}
			else
			{
				$aseco->client->query('ChatSendServerMessageToLogin', "> Usage: /delsec 2; /delsec 3-5", $login);
			}
		}
		else if ($matchesAmount == 2)
		{
			$isCorrectParamFormat = array();
			preg_match('/(\d+\-\d+)/', $command["params"], $isCorrectParamFormat);
			if ($isCorrectParamFormat[1] === $command["params"])
			{
				$deleteFromThisSector = $regexMatches[0][0];
				$deleteToThisSector = $regexMatches[0][1];
				// swap if first sector given is bigger than second in sector range
				if ($deleteFromThisSector > $deleteToThisSector)
				{
					$tempvar = $deleteFromThisSector;
					$deleteFromThisSector = $deleteToThisSector;
					$deleteToThisSector = $tempvar;
					if ($deleteFromThisSector >= 0 && $deleteToThisSector < $checkpointAmount)
					{
						// delete secrecs from database
						for ($i = $deleteFromThisSector; $i <= $deleteToThisSector; $i++) {
							mysql_query("DELETE FROM secrecs_all WHERE ChallengeID = '".$challengeNow."' AND Sector = ". $i .";");
							mysql_query("DELETE FROM secrecs_own WHERE ChallengeID = '".$challengeNow."' AND Sector = ". $i .";");
						}
						
						// update $tabs_sec_recs and $tab_own_recs
						loadDbInfo($aseco);
						
						$aseco->client->query('ChatSendServerMessage', '> Sectors '. $deleteFromThisSector . '-'. $deleteToThisSector . ' deleted.');
					}
					else
					{
						$aseco->client->query('ChatSendServerMessageToLogin', "> Please choose a valid sector range.", $login);
					}
				}
				else if ($deleteFromThisSector == $deleteToThisSector)
				{
					if ($deleteFromThisSector >= 0 && $deleteToThisSector < $checkpointAmount)
					{
						// delete secrec from database
						mysql_query("DELETE FROM secrecs_all WHERE ChallengeID = '".$challengeNow."' AND Sector = '". $deleteFromThisSector ."';");
						mysql_query("DELETE FROM secrecs_own WHERE ChallengeID = '".$challengeNow."' AND Sector = '". $deleteFromThisSector ."';");
						
						
						// update $tabs_sec_recs and $tab_own_recs
						loadDbInfo($aseco);
						
						$aseco->client->query('ChatSendServerMessage', '> Sector '. $deleteFromThisSector .' deleted.');
					}
					else
					{
						$aseco->client->query('ChatSendServerMessageToLogin', "> Please choose a valid sector range.", $login);
					}
				}
				else
				{
					if ($deleteFromThisSector >= 0 && $deleteToThisSector < $checkpointAmount)
					{
						// delete secrecs from database
						for ($i = $deleteFromThisSector; $i <= $deleteToThisSector; $i++)
						{
							mysql_query("DELETE FROM secrecs_all WHERE ChallengeID = '".$challengeNow."' AND Sector = ". $i .";");
							mysql_query("DELETE FROM secrecs_own WHERE ChallengeID = '".$challengeNow."' AND Sector = ". $i .";");
						}
						
						// update $tabs_sec_recs and $tab_own_recs
						loadDbInfo($aseco);
						
						$aseco->client->query('ChatSendServerMessage', '> Sectors '. $deleteFromThisSector . '-'. $deleteToThisSector . ' deleted.');
					}
					else
					{
						$aseco->client->query('ChatSendServerMessageToLogin', "> Please choose a valid sector range.", $login);
					}
				}
			}
			else
			{
				$aseco->client->query('ChatSendServerMessageToLogin', "> Usage: /delsec 2; /delsec 3-5", $login);
			}
		}
		else
		{
			$aseco->client->query('ChatSendServerMessageToLogin', "> Usage: /delsec 2; /delsec 3-5", $login);
		}
	}
	else
	{
		$aseco->client->query('ChatSendServerMessageToLogin', "> You must be an Admin to use this command" , $login);
	}
}

// Deletes all database secrecs whos tracks are deleted from server, deletes empty ChallengeIDs and removes secrecs_own duplicates. Could take some minutes to execute
function chat_secrecs_cleanupdb($aseco, $command)
{
	$author = $command['author'];
	$login = $author->login;
	if($aseco->isMasterAdmin($author))
	{
		// counting variables of entries that are deleted
		$amountOfFaultyIdsAllTotal = mysql_result(mysql_query("select count(*) from secrecs_all where ChallengeID = '';"), 0);
		$amountOfFaultyIdsOwnTotal = mysql_result(mysql_query("select count(*) from secrecs_own where ChallengeID = '';"), 0);
		$amountOfFaultyIdsTotal = $amountOfFaultyIdsAllTotal + $amountOfFaultyIdsOwnTotal;
		$amountOfOldIdsTotal = 0;
		$amountOfDuplicateIdsTotal = 0;
		
		/* remove db entries where UID is empty */
		mysql_query("DELETE from secrecs_own where ChallengeID = '';");
		mysql_query("DELETE from secrecs_all where ChallengeID = '';");
		
		/* remove old secs of removed tracks */
		// get challengelist on server
		$aseco->client->query('GetChallengeList', 2650, 0);
		$serverChallenges = $aseco->client->getResponse();
		// array of challenges uid that are currently available to play on on the server, in the challenges table you might see more tracks, those are ignored
		$challengesList = array();
		foreach($serverChallenges as $challengesIndex)
		{
			$challengesList[] = $challengesIndex["UId"];
		}

		// get secrecs of all challenges, compare with above and delete the ones that aren't in $challengesList
		// we query for secrecs_all, but we might aswell query secrecs_own, both yield the same result
		$dbSecsResult = mysql_query("select DISTINCT ChallengeID from secrecs_all;");
		// array of secrecs tracks uid, from which some tracks have already been deleted
		$secrecList = array();
		while ($row = mysql_fetch_object($dbSecsResult))
		{
			$secrecList[] = $row->ChallengeID;
		}
		
		// find old secrecs that can be removed by comparing $challengesList and $secrecList
		if (count($secrecList) > 0 && count($challengesList) > 0)
		{
			// to display remaining UIDs that are to be deleted ingame when executing the command
			$countOldUidDeleted = 0;
			$UidOldToDeleteTotal = count($secrecList) - count($challengesList);
			foreach($secrecList as $secuid)
			{
				if (!in_array($secuid, $challengesList))
				{
					$amountOfOldIdsPerTrackAll = mysql_result(mysql_query("SELECT COUNT(*) FROM secrecs_all WHERE ChallengeID = '".$secuid."';"), 0);
					$amountOfOldIdsPerTrackOwn = mysql_result(mysql_query("SELECT COUNT(*) FROM secrecs_own WHERE ChallengeID = '".$secuid."';"), 0);
					$amountOfOldIdsPerTrackTotal = $amountOfOldIdsPerTrackAll + $amountOfOldIdsPerTrackOwn;
					mysql_query("DELETE FROM secrecs_all WHERE ChallengeID = '". $secuid ."';");
					mysql_query("DELETE FROM secrecs_own WHERE ChallengeID = '". $secuid ."';");
					$countOldUidDeleted++;
					$percentDoneOld = round(($countOldUidDeleted*100)/$UidOldToDeleteTotal);
					$aseco->client->query('ChatSendServerMessageToLogin', "[". $percentDoneOld ."%] Deleting ".$amountOfOldIdsPerTrackTotal." entries of old secrecs on UID ". $secuid, $login);
					$aseco->console("[". $percentDoneOld ."%] Deleting ".$amountOfOldIdsPerTrackTotal." entries of old secrecs on UID ". $secuid);
					$amountOfOldIdsTotal += $amountOfOldIdsPerTrackTotal;
				}
			}
		}
		else
		{
			$aseco->client->query('ChatSendServerMessageToLogin', "Error. Either the secrecs or the tracklist on server is empty.", $login);
		}
		$aseco->client->query('ChatSendServerMessageToLogin', "Done deleting old secrecs", $login);
		
		
		/* remove duplicates in secrecs_own. duplicates were created prior to version 1.3 and can be cleaned up using this. */
		
		$countDuplicateUidDeleted = 0;
		$UidDuplicateToDeleteTotal = count($challengesList);
		
		foreach($challengesList as $challenge)
		{
			$amountOfDuplicateIdsPerTrackTotal = 0;
			
			$maxSectorQuery = mysql_query("select MAX(Sector) from secrecs_own where ChallengeID = '". $challenge ."';");
			$maxSector;
			if (mysql_num_rows($maxSectorQuery) > 0) { $maxSector = mysql_result($maxSectorQuery, 0); }
			else { $maxSector = -1; }
			
			for ($sector = 0; $sector <= $maxSector; $sector++)
			{
				$duplicatesResult = mysql_query("select ID as duplicateid from secrecs_own where ChallengeID = '".$challenge."' and Sector = ".$sector." and PlayerNick = ANY (select playerlogin as login from (select PlayerNick as playerlogin, COUNT(*) as countrecs from secrecs_own where ChallengeID = '".$challenge."' and Sector = ".$sector." group by playerlogin) as countedTable where countrecs > 1) order by ID DESC;");
				$duplicatesIds = array();
				while ($row = mysql_fetch_assoc($duplicatesResult))
				{
					$duplicatesIds[] = $row["duplicateid"];
				}
				
				// we subtract 1 because we want to leave 1 record behind
				$tmpDuplicateCount = count($duplicatesIds)-1;
				for ($id = 0; $id < $tmpDuplicateCount; $id++)
				{
					$deleteId = $duplicatesIds[$id];
					mysql_query("DELETE FROM secrecs_own WHERE ID=".$deleteId.";");
				}
				
				
				if ($tmpDuplicateCount > 0) { $amountOfDuplicateIdsPerTrackTotal += $tmpDuplicateCount; }
			}
			
			$countDuplicateUidDeleted++;
			if ($amountOfDuplicateIdsPerTrackTotal > 0)
			{
				$percentDoneDuplicate = round(($countDuplicateUidDeleted*100)/$UidDuplicateToDeleteTotal);
				$aseco->client->query('ChatSendServerMessageToLogin', "[".$percentDoneDuplicate."%] Deleting ".$amountOfDuplicateIdsPerTrackTotal." entries of duplicate secrecs on UID ".$challenge, $login);
				$aseco->console("[".$percentDoneDuplicate."%] Deleting ".$amountOfDuplicateIdsPerTrackTotal." entries of duplicate secrecs on UID ".$challenge);
				$amountOfDuplicateIdsTotal += $amountOfDuplicateIdsPerTrackTotal;
			}
		}
		$aseco->client->query('ChatSendServerMessageToLogin', "Done deleting duplicate secrecs", $login);
		
		$amountOfIdsTotal = $amountOfFaultyIdsTotal + $amountOfOldIdsTotal + $amountOfDuplicateIdsTotal;
		$aseco->client->query('ChatSendServerMessageToLogin', $amountOfIdsTotal . " entries were removed from database in total.", $login);
		$aseco->console($amountOfIdsTotal . " entries of secrecs were removed from database.");
	}
	else
	{
		$aseco->client->query('ChatSendServerMessageToLogin', "> You must be a MasterAdmin to use this command" , $login);
	}
}

// fill $tab_sec_recs and $tab_own_recs with already existing database information
function loadDbInfo($aseco) {
	global $tab_sec_recs,$tab_own_recs,$challengeNow;
	// first empty them
	$tab_sec_recs = array();
	$tab_own_recs = array();
	// load them up again with MySQL fetches
	load_mysql_all($challengeNow);
	foreach($aseco->server->players->player_list as $player)
	{
		load_mysql_own($challengeNow,$player->login);
	}
}

function setChatEnabled($aseco)
{
	global $chatz,$secrecsconfig,$chatz_own;
	if($secrecsconfig->display_recs[0]->sec_recs == 1 || strtoupper($secrecsconfig->display_recs[0]->sec_recs) == "TRUE")
	{
		$chatz = true;
	}
	else {
		$chatz = false;
	}
	if($secrecsconfig->display_recs[0]->own_recs == 1 || strtoupper($secrecsconfig->display_recs[0]->own_recs) == "TRUE")
	{
		$chatz_own = true;
	}
	else {
		$chatz_own = false;
	}
}

function nouseButtonOn($aseco) {
	global $showsecrecs, $secrecsconfig;
	
	$xPos = $secrecsconfig->position[0]->xPos;
	$yPos = $secrecsconfig->position[0]->yPos;

	if ($showsecrecs) {
		$xml = '<manialink id="0815470000122">
		  <format style="TextCardInfoSmall" textsize="1" />
		  <frame posn="'.$xPos.' '.$yPos.' 1">
			<quad posn="4.5 0 0" sizen="18 2.5"  halign="center" valign="center" style="Bgs1InRace" substyle="BgWindow1"  />
			<label posn="0 0.2 1" sizen="8 2" halign="center" valign="center" text="$i$s$fffSecrecs" action="27008505"/>
			<label posn="8 0.2 1" sizen="8 2" halign="center" valign="center" text="$i$s$fffMy Secrecs" action="27008504"/>
		  </frame>
	   </manialink>';
	   $aseco->client->addCall('SendDisplayManialinkPage', array($xml, 0, false));
	}
}

function nouseButtonHandleClick($aseco, $command) {
   $playerid = $command[0];
   $login = $command[1];
   $action = $command[2].'';
   
   if ($action == '27008505'){
      $chat = array();
      $chat[0] = $playerid;
      $chat[1] = $login;
      $chat[2] = "/secrecs";
      $chat[3] = true;
      $aseco->playerChat($chat);
   }
      if ($action == '27008504'){
      $chat = array();
      $chat[0] = $playerid;
      $chat[1] = $login;
      $chat[2] = "/mysecrecs";
      $chat[3] = true;
      $aseco->playerChat($chat);
   }
}

// deletes database secrecs of track if track is removed
function removeDbSecsOnTrackDeletion($aseco, $command) {
	global $secrecsconfig;
	
	if($secrecsconfig->remove_sec_from_db==true || $secrecsconfig->remove_sec_from_db==1)
	{
		if ($command[0] == 'remove')
		{
			$aseco->client->query('GetChallengeInfo', $command[1]);
			$challengeInfo = $aseco->client->getResponse();
			$uid = $challengeInfo["UId"];
			if ($uid != null) 
			{
				mysql_query("DELETE FROM secrecs_own WHERE ChallengeID = '". $uid ."';");
				mysql_query("DELETE FROM secrecs_all WHERE ChallengeID = '". $uid ."';");
			}
		}
	}
}
?>