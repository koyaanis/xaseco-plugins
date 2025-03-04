<?php
/*
=======================================================================
Description: Displays best Sectors
Author: DarkKnight, amgreborn
Version: v1.6
Dependencies: plugin.localdatabase.php

Changelog 1.6:
- Upgrade secrecs_own/secrecs_all datanase table for faster performance
- /secrecs_cleanupdb now only removes secrecs from challenges that are not on the server anymore
- Improving a sector now shows time difference to previous sector time

Changelog 1.5:
- Added pages to secrecs, fixes issue of displaying a too large window on challenges with many checkpoints
- Removed small/normal window size configuration, secrecs window is now set to a reasonable size
- Removed unecessary code

Changelog 1.4:
- Added /secrecs_cleanupdb chat command to remove duplicate secrecs, old secrecs and faulty secrecs that were caused by previous versions of this plugin

Changelog 1.3:
- Fixed a bug where it would assume that the gameservers database name is called "aseco"
- Fixed a bug where deleting secrecs wouldn't delete the entries from MySQL
- Fixed a bug where improving your own secrecs would overwrite everyone elses own secrecs for that sector with your name and time (many duplicates in database)
- Fixed a bug where incorrect times would register (known as the sector 0 bug)
- MySQL entries of a track will now be deleted if the track was removed from the server, can be turned off in bessecs.xml
- added /delsec chat command to delete single or multiple secrecs at once

=======================================================================
*/

Aseco::registerEvent("onCheckpoint", "secrecs_onCheckpoint");
Aseco::registerEvent("onNewChallenge", "secrecs_ownNewChallenge");
Aseco::registerEvent("onPlayerFinish", "secrecs_onPlayerFinish");
Aseco::registerEvent('onSync', 'secrecs_onSync');
Aseco::registerEvent('onPlayerConnect', 'secrecs_onPlayerConnect');
Aseco::registerEvent('onPlayerConnect', 'secrecs_nouseButtonOn');
Aseco::registerEvent('onPlayerManialinkPageAnswer', 'secrecs_nouseButtonHandleClick');
Aseco::registerEvent('onTracklistChanged', 'secrecs_removeDbSecsOnTrackDeletion');
Aseco::addChatCommand('secrecs', 'Shows Sector Records');
Aseco::addChatCommand('mysecrecs', 'Shows own Sector Records');
Aseco::addChatCommand('delsecs','Deletes all records on this challenge (both own and all secrecs)');
Aseco::addChatCommand('delsec','Deletes 1 record or a range of records on this challenge (both own and all secrecs)');

// fastest player of each sector is stored in here as object. example $tabs_sec_recs[2]->login yields login of player who has fastest sector 2 time
$secrecs_challengeSectorsAll;
// all driven sector times of all players. this is bigger than $secrecs_challengeSectorsAll. example $secrecs_challengeSectorsOwn[$login][2]->time yields time of player $login at sec 2
$secrecs_challengeSectorsOwn;

// used to save info about previous CP. differences between checkpoints are calculated this way
$secrecs_lastCP = array();
// challenge uid
$secrecs_challengeNow = null;
// contains XML infos
$secrecs_config;

// amount of checkpoints on Challenge. used to properly show secrecs ingame and to prevent false parameters to be given when deleting secrecs
$secrecs_checkpointAmount;
// enable or disable button widgets "Secrecs" and "My Secrecs". if disabled, players must use /secrecs or /mysecrecs to view the secrecs
$secrecs_showsecrecs;

class secrecs {
	var $time;
	var $login;
	var $cp;
	function secrecs($time, $login, $nickname, $cp)
	{
		$this->time = $time;
		$this->login =  $login;
		$this->nickname = $nickname;
		$this->cp = $cp;
	}
}

function secrecs_onPlayerConnect($aseco,$player) {
	global $secrecs_challengeNow, $aseco;

	if (is_null($secrecs_challengeNow)) {
		// onplayerconnect triggers before onnewchallenge, so we wait for the onnewchallenge event instead
		return;
	}

	secrecs_loadMysqlOwn($secrecs_challengeNow,$player);
}

function secrecs_SecToTime($oldTime,$prefix) {
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

function secrecs_createDatabaseTables() {
	mysql_query("CREATE TABLE IF NOT EXISTS `secrecs_all` (
		`Id` int(10) NOT NULL AUTO_INCREMENT,
		`ChallengeId` int(10) NOT NULL,
		`Sector` int(10) NOT NULL,
		`PlayerId` int(10) NOT NULL,
		`Time` int(10) NOT NULL,
		PRIMARY KEY (`Id`),
		UNIQUE KEY `unique_challenge_sector` (`ChallengeId`, `Sector`),
		FOREIGN KEY (`ChallengeId`) REFERENCES `challenges`(`Id`) ON DELETE CASCADE,
		FOREIGN KEY (`PlayerId`) REFERENCES `players`(`Id`) ON DELETE CASCADE
	) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=latin1;");

	mysql_query("CREATE TABLE IF NOT EXISTS `secrecs_own` (
		`Id` int(10) NOT NULL AUTO_INCREMENT,
		`ChallengeId` int(10) NOT NULL,
		`Sector` int(10) NOT NULL,
		`PlayerId` int(10) NOT NULL,
		`Time` int(10) NOT NULL,PRIMARY KEY (`Id`),
		UNIQUE KEY `unique_challenge_sector_player` (`ChallengeId`, `Sector`, `PlayerId`),
		FOREIGN KEY (`ChallengeId`) REFERENCES `challenges`(`Id`) ON DELETE CASCADE,
		FOREIGN KEY (`PlayerId`) REFERENCES `players`(`Id`) ON DELETE CASCADE
	) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=latin1;");
}

function secrecs_upgradeDatabaseStructure($aseco) {
	/* upgrading from old version. old version uses VARCHAR for challengeid/playernick, we need to upgrade to integer.
	also PlayerNick column is actually the login of the player */
	
	// old version uses ChallengeId (varchar), check if ChallengeId is varchar to determien if upgrade is needed
	$oldColumnScheme = mysql_fetch_object(mysql_query("SHOW COLUMNS FROM secrecs_all LIKE 'ChallengeId';"));

	// if "varchar" in type, it's the old version
	if (strpos(strtolower($oldColumnScheme->Type), 'varchar') === false) {
		// new version, upgrade not needed
		return;
	}
	
	$masterAdminsOnline = [];
	// of course the internal player_list isnt available at this point so we do custom querying again
	$aseco->client->query('GetPlayerList', 255, 0, 0);
	$players = $aseco->client->getResponse();
	foreach ($players as $player) {
		if (($i = array_search($player["Login"], $aseco->masteradmin_list['TMLOGIN'])) !== false) {
			$masterAdminsOnline[] = $player["Login"];
		}
	}
	$masterAdminsOnline = implode(',', $masterAdminsOnline);

	// temporarily increase memory limit to 2 GB
	$memoryLimitOriginal = ini_get('memory_limit');
	if ($memoryLimitOriginal !== '' && $memoryLimitOriginal !== false) {
		ini_set('memory_limit', '2048M');
	}
	
	$aseco->client->query('ChatSendServerMessageToLogin', '[plugin.bestsecs.php] Upgrading database structure for secrecs plugin... This may take a while.', $masterAdminsOnline);
	$aseco->console('[plugin.bestsecs.php] Upgrading database structure for secrecs plugin... This may take a while.');

	// first do cleanup, remove entries from secrecs_own/secrecs_all with empty challengeid and playernick
	mysql_query("DELETE FROM secrecs_own WHERE ChallengeID = '';");
	mysql_query("DELETE FROM secrecs_all WHERE ChallengeID = '';");
	mysql_query("DELETE FROM secrecs_own WHERE PlayerNick = '';");
	mysql_query("DELETE FROM secrecs_all WHERE PlayerNick = '';");

	// more cleanup, delete challengeid in secrecs_own/secrecs_all that do not exist in challenges
	mysql_query("DELETE FROM secrecs_own WHERE ChallengeID NOT IN (SELECT UId FROM challenges);");
	mysql_query("DELETE FROM secrecs_all WHERE ChallengeID NOT IN (SELECT UId FROM challenges);");

	// more cleanup, delete playernick in secrecs_own/secrecs_all that do not exist in players
	mysql_query("DELETE FROM secrecs_own WHERE PlayerNick NOT IN (SELECT Login FROM players);");
	mysql_query("DELETE FROM secrecs_all WHERE PlayerNick NOT IN (SELECT Login FROM players);");

	// retrieve info from challenges/players into variables
	$challenges = [];
	$players = [];
	$query = mysql_query("SELECT * FROM challenges order by id;");
	while ($row = mysql_fetch_object($query)) {
		$challenges[$row->Uid] = $row;
	}
	$query = mysql_query("SELECT * FROM players order by id;");
	while ($row = mysql_fetch_object($query)) {
		$players[$row->Login] = $row;
	}

	// retrieve current secrecs_own/secrecs_all data and update challengeid/playernick columns to integer
	$secrecs_all = [];
	$secrecs_own = [];
	$query = mysql_query("SELECT * FROM secrecs_all;");
	while ($row = mysql_fetch_object($query)) {
		$secrecs_all[] = [
			'ChallengeId' => (int)$challenges[$row->ChallengeID]->Id, // auto convert to the actual id of challenges table
			'Sector' => (int)$row->Sector,
			'PlayerNick' => (int)$players[$row->PlayerNick]->Id, // auto convert to the actual id of players table
			'Time' => (int)$row->Time
		];
	}

	$query = mysql_query("SELECT * FROM secrecs_own;");
	while ($row = mysql_fetch_object($query)) {
		$secrecs_own[] = [
			'ChallengeId' => (int)$challenges[$row->ChallengeID]->Id, // auto convert to the actual id of challenges table
			'Sector' => (int)$row->Sector,
			'PlayerNick' => (int)$players[$row->PlayerNick]->Id, // auto convert to the actual id of players table
			'Time' => (int)$row->Time
		];
	}

	// prior to 1.3, there sometimes were duplicates where in secrecs_own, a player improving a specific sector would overwrite all other players' times for that sector on that map
	// so we need to filter out these duplicates from secrecs_own
	usort($secrecs_own, function($a, $b) {
		if ($a['ChallengeId'] == $b['ChallengeId']) {
			if ($a['Sector'] == $b['Sector']) {
				return $a['PlayerNick'] - $b['PlayerNick'];
			}
			return $a['Sector'] - $b['Sector'];
		}
		return $a['ChallengeId'] - $b['ChallengeId'];
	});

	// now we can filter out the duplicates
	$secrecsOwnCountBeforeFilter = count($secrecs_own);
	$secrecs_ownfiltered = [];
	$previoussecrecs_own = null;
	foreach ($secrecs_own as $row) {
		if ($previoussecrecs_own == null || $previoussecrecs_own['ChallengeId'] != $row['ChallengeId'] || $previoussecrecs_own['Sector'] != $row['Sector'] || $previoussecrecs_own['PlayerNick'] != $row['PlayerNick']) {
			$secrecs_ownfiltered[] = $row;
		}
		$previoussecrecs_own = $row;
	}
	$secrecs_own = $secrecs_ownfiltered;
	$secrecsOwnCountAfterFilter = count($secrecs_own);

	// print out how many duplicates were found and removed
	$aseco->client->query('ChatSendServerMessageToLogin', '[plugin.bestsecs.php] Removed '.($secrecsOwnCountBeforeFilter - $secrecsOwnCountAfterFilter).' duplicates from secrecs_own table.', $masterAdminsOnline);
	$aseco->console('[plugin.bestsecs.php] Removed '.($secrecsOwnCountBeforeFilter - $secrecsOwnCountAfterFilter).' duplicates from secrecs_own table.');
	
	// drop the old tables
	mysql_query("DROP TABLE secrecs_all;");
	mysql_query("DROP TABLE secrecs_own;");

	// create new tables
	secrecs_createDatabaseTables();

	// insert the new data
	$batchSize = 100;
	$iteration = 0;
	$isIterationPrint = count($secrecs_all) / 10.0;
	$batchQuery = "INSERT INTO `secrecs_all` (ChallengeId,Sector,PlayerId,Time) VALUES ";
	foreach ($secrecs_all as $row) {
		$iteration++;
		$batchQuery .= "(".$row['ChallengeId'].",".$row['Sector'].",".$row['PlayerNick'].",".$row['Time']."),";
		if ($iteration % $batchSize == 0) {
			$batchQuery = rtrim($batchQuery, ',') . ";";
			mysql_query($batchQuery);
			$batchQuery = "INSERT INTO `secrecs_all` (ChallengeId,Sector,PlayerId,Time) VALUES ";
		}
		if ($iteration % $isIterationPrint == 0) {
			$aseco->client->query('ChatSendServerMessageToLogin', '[plugin.bestsecs.php] Upgrading secrecs_all table: '.($iteration / count($secrecs_all) * 100).'%', $masterAdminsOnline);
		}
	}
	if ($iteration % $batchSize != 0) {
		$batchQuery = rtrim($batchQuery, ',') . ";";
		mysql_query($batchQuery);
	}
	$aseco->console('[plugin.bestsecs.php] secrecs_all table upgraded.');

	$iteration = 0;
	$isIterationPrint = count($secrecs_own) / 10.0;
	$batchQuery = "INSERT INTO `secrecs_own` (ChallengeId,Sector,PlayerId,Time) VALUES ";
	foreach ($secrecs_own as $row) {
		$iteration++;
		$batchQuery .= "(".$row['ChallengeId'].",".$row['Sector'].",".$row['PlayerNick'].",".$row['Time']."),";
		if ($iteration % $batchSize == 0) {
			$batchQuery = rtrim($batchQuery, ',') . ";";
			mysql_query($batchQuery);
			$batchQuery = "INSERT INTO `secrecs_own` (ChallengeId,Sector,PlayerId,Time) VALUES ";
		}
		if ($iteration % $isIterationPrint == 0) {
			$aseco->client->query('ChatSendServerMessageToLogin', '[plugin.bestsecs.php] Upgrading secrecs_own table: '.($iteration / count($secrecs_own) * 100).'%', $masterAdminsOnline);
		}
	}
	if ($iteration % $batchSize != 0) {
		$batchQuery = rtrim($batchQuery, ',') . ";";
		mysql_query($batchQuery);
	}
	$aseco->console('[plugin.bestsecs.php] secrecs_own table upgraded.');

	// reset memory limit
	ini_set('memory_limit', $memoryLimitOriginal);
}

function secrecs_onSync($aseco) {
	secrecs_upgradeDatabaseStructure($aseco);
	secrecs_loadConfig();
	secrecs_createDatabaseTables();
}

function secrecs_loadConfig() {
	global $secrecs_config;
	$data = simplexml_load_file('bestsecs.xml');
	
	$secrecs_config = [
		"position" => [
			"xPos" => (string)$data->position[0]->xPos,
			"yPos" => (string)$data->position[0]->yPos
		],
		"display_recs" => [
			"sec_recs" => (bool)$data->display_recs[0]->sec_recs,
			"own_recs" => (bool)$data->display_recs[0]->own_recs
		],
		"window_enabled" => [
			"TA" => (bool)$data->window_enabled[0]->TA,
			"Rounds" => (bool)$data->window_enabled[0]->Rounds,
			"Team" => (bool)$data->window_enabled[0]->Team,
			"Cup" => (bool)$data->window_enabled[0]->Cup,
			"Lap" => (bool)$data->window_enabled[0]->Lap,
			"Stunts" => (bool)$data->window_enabled[0]->Stunts
		],
		"remove_sec_from_db" => (bool)$data->remove_sec_from_db[0]
	];
}

function secrecs_onCheckpoint($aseco, $param) {
	global $secrecs_challengeSectorsAll,$secrecs_lastCP,$secrecs_challengeNow,$secrecs_challengeSectorsOwn,$secrecs_config;
	
	$login = $param[1];

	$player = $aseco->server->players->player_list[$login];
	$nickname = $player->nickname;
	$timeOrScore = $param[2];
	$checkpointIndex = $param[4];
	$time2 = 0;
	
	// on xaseco restart, if player is in middle of race and crosses a checkpoint, ignore that sector
	$doUpdate = true;
		
	// successfully registered restart/finish, $secrecs_lastCP[$login] will be empty
	if (!isset($secrecs_lastCP[$login]) && $checkpointIndex == 0)
	{
		$time2 = $timeOrScore;
	}
	// happens when xaseco restarts and players are in the middle of the race (beyond checkpoint 0). we cannot calculate that secrec
	// unless there are no checkpoints on the map
	else if (!isset($secrecs_lastCP[$login]) && $checkpointIndex != 0)
	{
		$doUpdate = false;
		
		$aseco->client->query('GetCurrentChallengeInfo');
		$secrecs_checkpointAmount = $aseco->client->getResponse()["NbCheckpoints"];
		// no checkpoints on map
		if ($secrecs_checkpointAmount == 1)
		{
			$time2 = $timeOrScore;
			$doUpdate = true;
		}
	}
	// special case scenario, if player has passed a checkpoint, respawns to checkpoint and then restarts track within 5 seconds, the game will not have
	// triggered the onPlayerFinish event, thus $secrecs_lastCP couldn't properly be cleared
	else if (isset($secrecs_lastCP[$login]) && $checkpointIndex == 0)
	{
		$time2 = $timeOrScore;
	}
	else if (isset($secrecs_lastCP[$login]) && $checkpointIndex != 0)
	{
		$time2 = $timeOrScore - $secrecs_lastCP[$login]["cpTime"];
	}
	
	if ($doUpdate)
	{
		// player has claimed a sector
		if((!$secrecs_challengeSectorsAll[$checkpointIndex]) || (($time2 < $secrecs_challengeSectorsAll[$checkpointIndex]->time) && ($time2 > 0)))
		{
			$oldtime_old = $time2 - $secrecs_challengeSectorsAll[$checkpointIndex]->time;
			$secrecs_challengeSectorsAll[$checkpointIndex] = new secrecs($time2,$login,$nickname,$checkpointIndex);
			if ($secrecs_config["display_recs"]["sec_recs"])
			{
				if ($oldtime_old != $time2) {
					$aseco->client->query('ChatSendServerMessage', $nickname." \$z\$29fgained the record in sector ".$checkpointIndex.". Time: ".secrecs_SecToTime($time2,false)." (".($oldtime_old/1000).")");
				}
				else {
					$aseco->client->query('ChatSendServerMessage', $nickname." \$z\$29fclaimed the record in sector ".$checkpointIndex.". Time: ".secrecs_SecToTime($time2,false));
				}
			}
			ksort($secrecs_challengeSectorsAll);
			update_mysql_all($time2,$checkpointIndex,$secrecs_challengeNow,$player,$aseco);
		}
	
		// player has improved a sector
		if(($time2 < $secrecs_challengeSectorsOwn[$login][$checkpointIndex]->time  && ($time2 > 0)) || !$secrecs_challengeSectorsOwn[$login][$checkpointIndex])
		{
			$oldtime_old = $time2 - $secrecs_challengeSectorsOwn[$login][$checkpointIndex]->time;
			$secrecs_challengeSectorsOwn[$login][$checkpointIndex] = new secrecs($time2,$login,$nickname,$checkpointIndex);
			if($secrecs_config["display_recs"]["own_recs"])
			{
				if ($oldtime_old != $time2) {
					$aseco->client->query('ChatSendServerMessageToLogin', "> You improved your record in sector ".$checkpointIndex.". Time: ".secrecs_SecToTime($time2,false)." (".($oldtime_old/1000).")", $login);
				}
				else {
					$aseco->client->query('ChatSendServerMessageToLogin', "> You set a record in sector ".$checkpointIndex.". Time: ".secrecs_SecToTime($time2,false), $login);
				}
			}
			ksort($secrecs_challengeSectorsOwn[$login]);
			update_mysql_own($time2,$checkpointIndex,$secrecs_challengeNow,$player,$aseco);
		}
	}
	
	$secrecs_lastCP[$login] = array("cpIndex" => $checkpointIndex, "cpTime" => $timeOrScore);
}

function secrecs_ownNewChallenge($aseco, $challenge) {
	global $secrecs_challengeSectorsAll,$secrecs_challengeNow,$secrecs_challengeSectorsOwn,$secrecs_checkpointAmount;
	$secrecs_challengeNow = $challenge;
	
	// update $tabs_sec_recs and $secrecs_challengeSectorsOwn
	secrecs_loadDbInfo($aseco);
	
	$aseco->client->query('GetCurrentChallengeInfo');
	$secrecs_checkpointAmount = $aseco->client->getResponse()["NbCheckpoints"];
	
	secrecs_setShowSecRecs($aseco);
}

function secrecs_setShowSecRecs($aseco) {
	global $secrecs_showsecrecs,$secrecs_config;
	
	$gmode = $aseco->server->gameinfo->mode;
	$ta = $secrecs_config["window_enabled"]["TA"];
	$rn = $secrecs_config["window_enabled"]["Rounds"];
	$tm = $secrecs_config["window_enabled"]["Team"];
	$cu = $secrecs_config["window_enabled"]["Cup"];
	$la = $secrecs_config["window_enabled"]["Lap"];
	$st = $secrecs_config["window_enabled"]["Stunts"];
	
	if($ta==1 && $gmode==1)
	{
		$secrecs_showsecrecs = true;
	}
	else if($tm==1 && $gmode==2)
	{
		$secrecs_showsecrecs = true;
	}
	else if($rn==1 && $gmode==0)
	{
		$secrecs_showsecrecs = true;
	}
	else if($la==1 && $gmode==3)
	{
		$secrecs_showsecrecs = true;
	}
	else if($st==1 && $gmode==4)
	{
		$secrecs_showsecrecs = true;
	}
	else if($cu==1 && $gmode==5)
	{
		$secrecs_showsecrecs = true;
	}
	else
	{
		$secrecs_showsecrecs = false;
	}
	
	secrecs_nouseButtonOn($aseco);
}

// CAREFUL, THE PLAYERFINISH EVENT CAN ONLY UPDATE ONCE EVERY ~5 SECONDS, THIS CAN EASILY LEAD TO MISCALCULATIONS
// FURTHER LOGIC WAS USED ABOVE TO HOTFIX THIS
function secrecs_onPlayerFinish($aseco, $finish) {
	global $secrecs_lastCP;
	$secrecs_lastCP[$finish->player->login] = null;
}

function update_mysql_all($recordTime, $sector, $challenge, $player, $aseco) {
	$query = "INSERT INTO secrecs_all (ChallengeId, Sector, PlayerId, Time) VALUES (".$challenge->id.", ".$sector.", ".$player->id.", ".$recordTime.") ON DUPLICATE KEY UPDATE PlayerId=".$player->id.", Time=".$recordTime.";";
	mysql_query($query);
}

function update_mysql_own($recordTime, $sector, $challenge, $player, $aseco) {
	$query = "INSERT INTO secrecs_own (ChallengeId, Sector, PlayerId, Time) VALUES (".$challenge->id.", ".$sector.", ".$player->id.", ".$recordTime.") ON DUPLICATE KEY UPDATE Time=".$recordTime.";";
	mysql_query($query);
}

function secrecs_loadMysqlAll($challenge) {
	global $secrecs_challengeSectorsAll;
	
	$secrecs_challengeSectorsAll = array();
	$query = "SELECT sec.Time, p.Login, p.NickName, sec.Sector FROM `secrecs_all` as sec inner join players as p on sec.PlayerId = p.Id WHERE ChallengeId=".$challenge->id." ORDER BY Sector;";
	$result = mysql_query($query);
	while($row = mysql_fetch_object($result))
	{
		$secrecs_challengeSectorsAll[$row->Sector] = new secrecs($row->Time,$row->Login,$row->NickName,$row->Sector);
	}
}

function secrecs_loadMysqlOwn($challenge,$player) {
	global $secrecs_challengeSectorsOwn;
	$secrecs_challengeSectorsOwn[$player] = array();
	$query = "SELECT sec.Time, p.Login, p.NickName, sec.Sector FROM `secrecs_own` as sec inner join players as p on sec.PlayerId = p.Id WHERE ChallengeId=".$challenge->id." AND PlayerId=".$player->id." ORDER BY Sector;";
	$result = mysql_query($query);
	while($row = mysql_fetch_object($result))
	{
		$secrecs_challengeSectorsOwn[$row->Login][$row->Sector] = new secrecs($row->Time,$row->Login,$row->NickName,$row->Sector);
	}
}

function chat_secrecs($aseco, $command) {
	global $secrecs_challengeSectorsAll, $secrecs_challengeNow;
	$player = $command[ "author" ];

	if ( $aseco->server->getGame() == 'TMF' ) {
		$Header = "Sector Records on this Map: ";
		$Message = array( array( 1, $Header, array( 1.25 ), array( "Icons64x64_1", "TrackInfo" ) ) ); // element 0 are header information

		$RecsSize = count( $secrecs_challengeSectorsAll );
		
		if ($RecsSize > 0)
		{
			$Pages = ceil( $RecsSize / 15 ); // page 1 shows sector 0 to 14
			$OverallTime = 0;
			
			$sectorsRead = 0; // because secrecs can be deleted, we must determine on which page we are moving ourself by using this counter
			foreach( $secrecs_challengeSectorsAll as $value )
			{
				$Sector = $value->cp; // "cp" is not the checkpoint, but the sector, and the first sector is 0
				$Page = floor( $sectorsRead / 15 ) + 1; // Page[0] are header infos, Page[1] will be sec 0 to 14, Page[2] 15 to 29..
				$OverallTime += $value->time;

				if ( strlen( $Sector ) == 1 ) {
					$Sector = "0" . $Sector;	
				}
				
				// $Page minimum value is 1.
				$Message[ $Page ][] = array( $aseco->formatColors( "{#highlite}Sec" . $Sector . ": " . secrecs_SecToTime( $value->time, false ) . " by " . $value->nickname . "\n" ) );
				
				$sectorsRead++;
			}

			for ( $i = 0; $i < $Pages; $i++ ) {
				$Message[ 1 + $i ][] = array( "" );
				$Message[ 1 + $i ][] = array( $aseco->formatColors( "{#highlite}Total Time: " . secrecs_SecToTime( $OverallTime, false ) ) );
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
	global $secrecs_challengeSectorsOwn, $secrecs_challengeSectorsAll, $secrecs_challengeNow;
	$player = $command["author"];
	
	if ($aseco->server->getGame() == 'TMF') {
		$Header = "Your own Sector Records on this Map: ";
		$Message = array( array( 1, $Header, array( 1.25 ), array( "Icons64x64_1", "TrackInfo" ) ) );

		$RecsSize = count( $secrecs_challengeSectorsOwn[$player->login] );		
		
		if ($RecsSize > 0)
		{
			$Pages = ceil( $RecsSize / 15 );
			$OverallTime = 0;
			
			$sectorsRead = 0; // because secrecs can be deleted, we must determine on which page we are moving ourself by using this counter
			foreach( $secrecs_challengeSectorsOwn[$player->login] as $value )
			{
				$Sector = $value->cp;
				$Page = floor( $sectorsRead / 15 ) + 1;
				$OverallTime += $value->time;
				
				$RecTime = $secrecs_challengeSectorsAll[$Sector]->time;
				$RecLogin = $secrecs_challengeSectorsAll[$Sector]->login;
				$RecNick = $secrecs_challengeSectorsAll[$Sector]->nickname;	

				$Diff = ( $value->time - $RecTime );
				if( strlen( $Sector ) == 1 ) { 
					$Sector = "0" . $Sector;					
				}

				$Message[ $Page ][] = array( $aseco->formatColors( "{#highlite}Sec" . $Sector . ": " . secrecs_SecToTime( $value->time, false ) . " ( " . secrecs_SecToTime( $Diff, true ) . " to TOP1 " . $RecNick->NickName . " {#highlite}) \n" ) );
				
				$sectorsRead++;
			}
			
			for ( $i = 0; $i < $Pages; $i++ ) {
				$Message[ 1 + $i ][] = array( "" );
				$Message[ 1 + $i ][] = array( $aseco->formatColors( "{#highlite}Total Time: " . secrecs_SecToTime( $OverallTime, false ) ) );
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

function chat_delsecs($aseco, $command) {
	global $secrecs_challengeNow,$secrecs_challengeSectorsAll,$secrecs_challengeSectorsOwn;
	$admin = $command['author'];
	$login = $admin->login;
	if($aseco->isMasterAdmin($admin) || $aseco->isAdmin($admin) || $aseco->isOperator($admin))
	{
		mysql_query("DELETE FROM secrecs_all WHERE ChallengeId = '".$secrecs_challengeNow->id."';");
		mysql_query("DELETE FROM secrecs_own WHERE ChallengeId = '".$secrecs_challengeNow->id."';");
		$secrecs_challengeSectorsAll = array();
		$secrecs_challengeSectorsOwn = array();
		$aseco->client->query('ChatSendServerMessageToLogin', "> All SecRecs deleted !" , $login);
		
		// update $tabs_sec_recs and $secrecs_challengeSectorsOwn
		secrecs_loadDbInfo($aseco);
	}
	else
	{
		$aseco->client->query('ChatSendServerMessageToLogin', "> You must be an Admin to use this command" , $login);
	}
	
}

function chat_delsec($aseco, $command) {
	global $secrecs_challengeNow,$secrecs_challengeSectorsAll,$secrecs_challengeSectorsOwn,$secrecs_checkpointAmount;
	$admin = $command['author'];
	$login = $admin->login;

	if($aseco->isMasterAdmin($admin) || $aseco->isAdmin($admin) || $aseco->isOperator($admin))
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
		$secrecs_checkpointAmount = $aseco->client->getResponse()["NbCheckpoints"];
		
		if ($matchesAmount == 0)
		{
			$aseco->client->query('ChatSendServerMessageToLogin', "> Usage: /delsec 2; /delsec 3-5" , $login);
		}
		else if ($matchesAmount == 1)
		{
			if ($command["params"] === $regexMatches[0][0])
			{
				$sectorToDelete = $regexMatches[0][0];
				if ($sectorToDelete >= 0 && $sectorToDelete < $secrecs_checkpointAmount)
				{
					// delete secrec from database
					mysql_query("DELETE FROM secrecs_all WHERE ChallengeId = '".$secrecs_challengeNow->id."' AND Sector = ". $sectorToDelete .";");
					mysql_query("DELETE FROM secrecs_own WHERE ChallengeId = '".$secrecs_challengeNow->id."' AND Sector = ". $sectorToDelete .";");
					
					// update $tabs_sec_recs and $secrecs_challengeSectorsOwn
					secrecs_loadDbInfo($aseco);
					
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
					if ($deleteFromThisSector >= 0 && $deleteToThisSector < $secrecs_checkpointAmount)
					{
						// delete secrecs from database
						for ($i = $deleteFromThisSector; $i <= $deleteToThisSector; $i++) {
							mysql_query("DELETE FROM secrecs_all WHERE ChallengeId = '".$secrecs_challengeNow->id."' AND Sector = ". $i .";");
							mysql_query("DELETE FROM secrecs_own WHERE ChallengeId = '".$secrecs_challengeNow->id."' AND Sector = ". $i .";");
						}
						
						// update $tabs_sec_recs and $secrecs_challengeSectorsOwn
						secrecs_loadDbInfo($aseco);
						
						$aseco->client->query('ChatSendServerMessage', '> Sectors '. $deleteFromThisSector . '-'. $deleteToThisSector . ' deleted.');
					}
					else
					{
						$aseco->client->query('ChatSendServerMessageToLogin', "> Please choose a valid sector range.", $login);
					}
				}
				else if ($deleteFromThisSector == $deleteToThisSector)
				{
					if ($deleteFromThisSector >= 0 && $deleteToThisSector < $secrecs_checkpointAmount)
					{
						// delete secrec from database
						mysql_query("DELETE FROM secrecs_all WHERE ChallengeId = '".$secrecs_challengeNow->id."' AND Sector = '". $deleteFromThisSector ."';");
						mysql_query("DELETE FROM secrecs_own WHERE ChallengeId = '".$secrecs_challengeNow->id."' AND Sector = '". $deleteFromThisSector ."';");
						
						
						// update $tabs_sec_recs and $secrecs_challengeSectorsOwn
						secrecs_loadDbInfo($aseco);
						
						$aseco->client->query('ChatSendServerMessage', '> Sector '. $deleteFromThisSector .' deleted.');
					}
					else
					{
						$aseco->client->query('ChatSendServerMessageToLogin', "> Please choose a valid sector range.", $login);
					}
				}
				else
				{
					if ($deleteFromThisSector >= 0 && $deleteToThisSector < $secrecs_checkpointAmount)
					{
						// delete secrecs from database
						for ($i = $deleteFromThisSector; $i <= $deleteToThisSector; $i++)
						{
							mysql_query("DELETE FROM secrecs_all WHERE ChallengeId = '".$secrecs_challengeNow->id."' AND Sector = ". $i .";");
							mysql_query("DELETE FROM secrecs_own WHERE ChallengeId = '".$secrecs_challengeNow->id."' AND Sector = ". $i .";");
						}
						
						// update $tabs_sec_recs and $secrecs_challengeSectorsOwn
						secrecs_loadDbInfo($aseco);
						
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

// fill $secrecs_challengeSectorsAll and $secrecs_challengeSectorsOwn with already existing database information
function secrecs_loadDbInfo($aseco) {
	global $secrecs_challengeSectorsAll, $secrecs_challengeSectorsOwn, $secrecs_challengeNow;

	// first empty them
	$secrecs_challengeSectorsAll = array();
	$secrecs_challengeSectorsOwn = array();

	// load them up again with MySQL fetches
	secrecs_loadMysqlAll($secrecs_challengeNow);

	foreach($aseco->server->players->player_list as $player) {
		secrecs_loadMysqlOwn($secrecs_challengeNow,$player);
	}
}

function secrecs_nouseButtonOn($aseco) {
	global $secrecs_showsecrecs, $secrecs_config;
	
	if (!$secrecs_showsecrecs) {
		return;
	}

	$xPos = $secrecs_config["position"]["xPos"];
	$yPos = $secrecs_config["position"]["yPos"];

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

function secrecs_nouseButtonHandleClick($aseco, $command) {
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
function secrecs_removeDbSecsOnTrackDeletion($aseco, $command) {
	global $secrecs_config;
	// TODO: test this
	if ($secrecs_config["remove_sec_from_db"])
	{
		if ($command[0] == 'remove')
		{
			$aseco->client->query('GetChallengeInfo', $command[1]);
			$challengeInfo = $aseco->client->getResponse();
			$uid = $challengeInfo["UId"];
			
			// get database id of track
			$query = mysql_query("select Id from challenges where Uid = '". $uid ."';");

			if ($obj = mysql_fetch_object($query))
			{
				mysql_query("DELETE FROM secrecs_own WHERE ChallengeId = '". $obj->Id ."';");
				mysql_query("DELETE FROM secrecs_all WHERE ChallengeId = '". $obj->Id ."';");
			}
		}
	}
}

// Deletes all database secrecs whos tracks are deleted from server, deletes empty ChallengeIds and removes secrecs_own duplicates. Could take some minutes to execute
function chat_secrecs_cleanupdb($aseco, $command) {
	$author = $command['author'];
	$login = $author->login;

	if(!$aseco->isMasterAdmin($author))
	{
		$aseco->client->query('ChatSendServerMessageToLogin', "> You must be a MasterAdmin to use this command" , $login);
		return;
	}

	// counting variables of entries that are deleted
	$amountOfFaultyIdsTotal = 0;
	$amountOfOldIdsTotal = 0;

	$amountOfFaultyIdsTotal += mysql_result(mysql_query("select count(Id) from secrecs_all where ChallengeId NOT IN (SELECT Id FROM challenges);"), 0);
	$amountOfFaultyIdsTotal += mysql_result(mysql_query("select count(Id) from secrecs_own where ChallengeId NOT IN (SELECT Id FROM challenges);"), 0);
	$amountOfFaultyIdsTotal += mysql_result(mysql_query("select count(Id) from secrecs_all where PlayerId NOT IN (SELECT Id FROM players);"), 0);
	$amountOfFaultyIdsTotal += mysql_result(mysql_query("select count(Id) from secrecs_own where PlayerId NOT IN (SELECT Id FROM players);"), 0);

	// remove challengeid's that are not in challenges table
	mysql_query("DELETE FROM secrecs_own WHERE ChallengeId NOT IN (SELECT Id FROM challenges);");
	mysql_query("DELETE FROM secrecs_all WHERE ChallengeId NOT IN (SELECT Id FROM challenges);");

	// remove playerid's that are not in players table
	mysql_query("DELETE FROM secrecs_own WHERE PlayerId NOT IN (SELECT Id FROM players);");
	mysql_query("DELETE FROM secrecs_all WHERE PlayerId NOT IN (SELECT Id FROM players);");
	
	/* remove old secs of removed tracks */
	$challengesUids = array_flip(array_keys(getChallengesCache($aseco))); // use array_flip for faster lookup
	// grab ids from challenges table
	$challengesList = array();
	$query = mysql_query("select Id, Uid from challenges;");
	while ($row = mysql_fetch_object($query))
	{
		if (isset($challengesUids[$row->Uid]))
		{
			$challengesList[] = $row->Id;
		}
	}
	
	// get secrecs of all challenges, compare with above and delete the ones that aren't in $challengesList
	// we query for secrecs_all, but we might aswell query secrecs_own, both yield the same result
	$dbSecsResult = mysql_query("select DISTINCT ChallengeId from secrecs_all;");
	// array of secrecs tracks id, from which some tracks have already been deleted
	$secrecList = array();
	while ($row = mysql_fetch_object($dbSecsResult))
	{
		$secrecList[] = $row->ChallengeId;
	}
	
	// find old secrecs that can be removed by comparing $challengesList and $secrecList
	if (count($secrecList) > 0 && count($challengesList) > 0)
	{
		// to display remaining IDs that are to be deleted ingame when executing the command
		$iterator = 0;
		// TODO: this below is non sense
		$totalCount = count($secrecList);
		foreach($secrecList as $secid)
		{
			$iterator++;
			if (!in_array($secid, $challengesList))
			{
				$amountOfOldIdsPerTrackAll = mysql_result(mysql_query("SELECT COUNT(*) FROM secrecs_all WHERE ChallengeId = '".$secid."';"), 0);
				$amountOfOldIdsPerTrackOwn = mysql_result(mysql_query("SELECT COUNT(*) FROM secrecs_own WHERE ChallengeId = '".$secid."';"), 0);
				$amountOfOldIdsPerTrackTotal = $amountOfOldIdsPerTrackAll + $amountOfOldIdsPerTrackOwn;
				mysql_query("DELETE FROM secrecs_all WHERE ChallengeId = ". $secid .";");
				mysql_query("DELETE FROM secrecs_own WHERE ChallengeId = ". $secid .";");
				$percentDoneOld = round(($iterator*100)/$totalCount);
				$aseco->client->query('ChatSendServerMessageToLogin', "[plugin.bestsecs.php] [". $percentDoneOld ."%] Deleting ".$amountOfOldIdsPerTrackTotal." entries of old secrecs on ID ". $secid, $login);
				$aseco->console('[plugin.bestsecs.php] Deleting '.$amountOfOldIdsPerTrackTotal.' entries of old secrecs on ID '. $secid);
				$amountOfOldIdsTotal += $amountOfOldIdsPerTrackTotal;
			}
		}
	}

	$aseco->client->query('ChatSendServerMessageToLogin', "[plugin.bestsecs.php] Done deleting old secrecs", $login);
	$aseco->console('[plugin.bestsecs.php] Done deleting old secrecs');
	
	$amountOfIdsTotal = $amountOfFaultyIdsTotal + $amountOfOldIdsTotal;
	$aseco->client->query('ChatSendServerMessageToLogin', "[plugin.bestsecs.php] " . $amountOfIdsTotal . " entries were removed from database in total.", $login);
	$aseco->console($amountOfIdsTotal . " entries of secrecs were removed from database.");
}

?>
