<?php

Aseco::addChatCommand('addrandomtrack', 'Adds random track from tmx');

function chat_addrandomtrack($aseco, $command) {
	global $rasp, $tmxdir, $jukebox_adminadd, $jukebox;  // from plugin.rasp.php, rasp.settings.php
	
	$admin = $command['author'];
	$login = $admin->login;
	if (!$aseco->isMasterAdmin($admin) && !$aseco->isAdmin($admin)) {
		$aseco->client->query('ChatSendServerMessageToLogin', '>> You need to be Admin to use this command', $login);
	}
	
	// check if chat command was allowed for a masteradmin/admin/operator
	if ($aseco->isMasterAdmin($admin)) {
		$logtitle = 'MasterAdmin';
		$chattitle = $aseco->titles['MASTERADMIN'][0];
	} else if ($aseco->isAdmin($admin)) {
		$logtitle = 'Admin';
		$chattitle = $aseco->titles['ADMIN'][0];
	} else if ($aseco->isOperator($admin)) {
		$logtitle = 'Operator';
		$chattitle = $aseco->titles['OPERATOR'][0];
	}
	
	$tmxUrlFormats = [
		"tmnf" => ["https://tmnf.exchange/trackrandom", 'http://tmnf.exchange/trackgbx/'],
		"original" => ["https://original.tm-exchange.com/trackrandom", 'http://original.tm-exchange.com/trackgbx/'],
		"sunrise" => ["https://sunrise.tm-exchange.com/trackrandom", 'http://sunrise.tm-exchange.com/trackgbx/'],
		"nations" => ["https://nations.tm-exchange.com/trackrandom", 'http://nations.tm-exchange.com/trackgbx/'],
		"tmuf" => ["https://tmuf.exchange/trackrandom", 'http://tmuf.exchange/trackgbx/']
	];
	
	$randomSection = array_rand($tmxUrlFormats);
	
	$wasRandomTmxIdFound = false;
	try {
		$streamContext = stream_context_create([
			"ssl" => [
				"verify_peer" => false,
				"verify_peer_name" => false,
			],
		]);
		
		// Get random ID
		$RandomTrackHeaders = get_headers($tmxUrlFormats[$randomSection][0], false, $streamContext);
		$RandomTMXId = array();
		forEach ($RandomTrackHeaders as $RandomTrackHeader) {
			if (preg_match('/^Location\:\s[^\d]+(\d+)/', $RandomTrackHeader, $RandomTMXId) === 1) {
				$RandomTMXId = $RandomTMXId[1];
				$wasRandomTmxIdFound = true;
				break;
			}
		}
	} catch(Exception $e) {
		return;
	}
	
	if (!$wasRandomTmxIdFound) {
		trigger_error('[plugin.randomtmxtrack.php] Could not retrieve a random TMX id!', E_USER_WARNING);
		$aseco->client->query('ChatSendServerMessageToLogin', 'Could not retrieve a random TMX id!', $login);
		return;
	}
	
	// try to load the track(s) from TMX
	$source = 'TMX';
	
	$trackGbxUrl = $tmxUrlFormats[$randomSection][1];

	$file = http_get_file($trackGbxUrl . $RandomTMXId);
	if ($file === false || $file == -1) {
		$message = '{#server}> {#error}Error downloading, or wrong TMX section, or TMX is down!';
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		return;
	}
	
	// check for maximum online track size (256 KB)
	if (strlen($file) >= 256 * 1024) {
		$message = formatText($rasp->messages['TRACK_TOO_LARGE'][0],
							  round(strlen($file) / 1024));
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		return;
	}
	
	$sepchar = substr($aseco->server->trackdir, -1, 1);
	$partialdir = $tmxdir . $sepchar . $RandomTMXId . '.Challenge.gbx';
	$localfile = $aseco->server->trackdir . $partialdir;
	if ($nocasepath = file_exists_nocase($localfile)) {
		if (!unlink($nocasepath)) {
			$message = '{#server}> {#error}Error erasing old file - unable to erase {#highlite}$i ' . $localfile;
			$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			return;
		}
	}
	
	if (!$lfile = @fopen($localfile, 'wb')) {
		$message = '{#server}> {#error}Error creating file - unable to create {#highlite}$i ' . $localfile;
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		return;
	}
	
	if (!fwrite($lfile, $file)) {
		$message = '{#server}> {#error}Error saving file - unable to write data';
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		fclose($lfile);
		return;
	}
	fclose($lfile);
	
	$newtrk = getChallengeData($localfile, false);  // 2nd parm is whether or not to get players & votes required
	if ($newtrk['votes'] == 500 && $newtrk['name'] == 'Not a GBX file') {
		$message = '{#server}> {#error}No such track on ' . $source;
		if ($source == 'TMX' && $aseco->server->getGame() == 'TMF')
			$message .= ' section ' . $section;
		$message .= '!';
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		unlink($localfile);
		return;
	}
	
	// check for track presence on server
	$tracksCache = getChallengesCache($aseco);
	if (array_key_exists($newtrk['uid'], $tracksCache)) {
		$message = $rasp->messages['ADD_PRESENT'][0];
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		unlink($localfile);
		unset($list);
		return;
	}
	
	// rename ID filename to track's name
	$md5new = md5_file($localfile);
	$filename = trim(utf8_decode(stripColors($newtrk['name'])));
	$filename = preg_replace('/[^A-Za-z0-9 \'#=+~_,.-]/', '_', $filename);
	$filename = preg_replace('/ +/', ' ', preg_replace('/_+/', '_', $filename));
	$partialdir = $tmxdir . $sepchar . $filename . '_' . $RandomTMXId . '.Challenge.gbx';
	// ensure unique filename by incrementing sequence number,
	// if not a duplicate track
	$i = 1;
	$dupl = false;
	while ($nocasepath = file_exists_nocase($aseco->server->trackdir . $partialdir)) {
		$md5old = md5_file($nocasepath);
		if ($md5old == $md5new) {
			$dupl = true;
			$partialdir = str_replace($aseco->server->trackdir, '', $nocasepath);
			break;
		} else {
			$partialdir = $tmxdir . $sepchar . $filename . '_' . $RandomTMXId . '-' . $i++ . '.Challenge.gbx';
		}
	}
	if ($dupl) {
		unlink($localfile);
	} else {
		rename($localfile, $aseco->server->trackdir . $partialdir);
	}

	// check track vs. server settings
	if ($aseco->server->getGame() == 'TMF') {
		$rtn = $aseco->client->query('CheckChallengeForCurrentServerParams', $partialdir);
	} else {
		$rtn = true;
	}
	if (!$rtn) {
		trigger_error('[' . $aseco->client->getErrorCode() . '] CheckChallengeForCurrentServerParams - ' . $aseco->client->getErrorMessage(), E_USER_WARNING);
		$message = formatText($rasp->messages['JUKEBOX_IGNORED'][0],
							  stripColors($newtrk['name']), $aseco->client->getErrorMessage());
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		return;
	}
	
	// permanently add the track to the server list
	$rtn = $aseco->client->query('AddChallenge', $partialdir);
	if (!$rtn) {
		trigger_error('[' . $aseco->client->getErrorCode() . '] AddChallenge - ' . $aseco->client->getErrorMessage(), E_USER_WARNING);
		return;
	}
	
	$aseco->client->resetError();
	$aseco->client->query('GetChallengeInfo', $partialdir);
	$track = $aseco->client->getResponse();
	if ($aseco->client->isError()) {
		trigger_error('[' . $aseco->client->getErrorCode() . '] GetChallengeInfo - ' . $aseco->client->getErrorMessage(), E_USER_WARNING);
		$message = formatText('{#server}> {#error}Error getting info on track {#highlite}$i {1} {#error}!',
							  $partialdir);
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		return;
	}
	
	$track['Name'] = stripNewlines($track['Name']);
	// check whether to jukebox as well
	// overrules /add-ed but not yet played track
	if ($jukebox_adminadd) {
		$uid = $track['UId'];
		$jukebox[$uid]['FileName'] = $track['FileName'];
		$jukebox[$uid]['Name'] = $track['Name'];
		$jukebox[$uid]['Env'] = $track['Environnement'];
		$jukebox[$uid]['Login'] = $login;
		$jukebox[$uid]['Nick'] = $admin->nickname;
		$jukebox[$uid]['source'] = $source;
		$jukebox[$uid]['tmx'] = false;
		$jukebox[$uid]['uid'] = $uid;
	}

	// log console message
	$aseco->console('{1} [{2}] adds track "{3}" from {4}!', $logtitle, $login, stripColors($track['Name'], false), $source);

	// show chat message
	$message = formatText('{#server}>> {#admin}{1}$z$s {#highlite}{2}$z$s {#admin}adds {3}track: {#highlite}{4} {#admin}from {5}',
						  $chattitle, $admin->nickname,
						  ($jukebox_adminadd ? '& jukeboxes ' : ''),
						  stripColors($track['Name']), $source);
	$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));

	// throw 'tracklist changed' event
	$aseco->releaseEvent('onTracklistChanged', array('add', $partialdir));

	// throw 'jukebox changed' event
	if ($jukebox_adminadd) {
		$aseco->releaseEvent('onJukeboxChanged', array('add', $jukebox[$uid]));
	}
}

?>
