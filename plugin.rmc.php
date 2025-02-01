<?php

/*
=======================================================================
Description: Random Map Challenge Speedrun / RMC Plugin. Players are given a timelimit of x time to complete as many randomly chosen as possible.
=======================================================================
*/

Aseco::addChatCommand('rmc', 'rmc');
Aseco::registerEvent('onSync', 'rmc_onSync');
Aseco::registerEvent('onBeginRound', 'rmc_onBeginRound');
Aseco::registerEvent('onEverySecond', 'rmc_onEverySecond');
Aseco::registerEvent('onPlayerFinish', 'rmc_onPlayerFinish');
Aseco::registerEvent('onPlayerManialinkPageAnswer', 'rmc_onPlayerManialinkPageAnswer');

class RMC {
    private $aseco;
    private $flexitime;

    // hardcoded config, TODO: improve this later
    private $settings = [
        "timeLimit" => 60 * 60, // 1 hour // TODO: change later
        "skipsLimit" => 3,
        "drawStatsManiaLinkId" => "18640", // these are randomly chosen
        "drawLeaderboardManiaLinkId" => "18641"
    ];

    // total time the speedrun will take in seconds
    private $timeLeft = 60 * 60; // 1 hour
    private $skipsLeft = 3;
    private $chat_prefix = '$w$i$0f0[RMC]$z$s$g ';

    private $adminLogins = [
        'buugraa',
        '_555af__fc7.link_9c7max',
        'buzzy146',
        'rowy_201',
        'youmol',
        '_zodwin_',
        'thicc_boi9120',
        'wikileaks',
        'theonlyeddy',
        'simo_900',
        'julle012',
        'amgreborn'
    ];

    private $enabled = false;
    private $finishedChallenges = [];
    private $unfinishedChallenges = []; // for maps that were skipped or not finished in time
    private $waitTime = 30; // waiting time in seconds before next map is loaded
    private $originalJukeboxSkipleft = null;
    private $originalFeatureVotes = null;
    private $originalFeatureJukebox = null;
    private $nextMapQueued = false;
    private $challengePlayTime = 0; // time in seconds the current challenge has been running

    // for buzz world. kacky challenges uids. needs some better solution later. TODO:
    private $kackyUids = [];


    public function __construct($aseco, $flexitime) {
        global $jukebox_skipleft, $feature_votes, $feature_jukebox; // from includes/rasp.settings.php
        
        $this->createDatabaseLayout();

        $this->aseco = $aseco;
        $this->flexitime = $flexitime;

        $this->timeLeft = $this->settings["timeLimit"];
        $this->skipsLeft = $this->settings["skipsLimit"];

        // save original jukebox skipleft to restore it later
        $this->originalJukeboxSkipleft = $jukebox_skipleft;
        $this->originalFeatureVotes = $feature_votes;
        $this->originalFeatureJukebox = $feature_jukebox;

        // check for variable validity
        if ($this->waitTime < 15) {
            $this->aseco->console('RMC: waitTime must be atleast 15 seconds! Setting to 15 seconds.');
            $this->waitTime = 15;
        }

        // retrieve kacky uids from web and create array for faster lookup using isset()
        // use curl to get the data
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://kk.kackymania.com/uids.php");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $kackyUids = curl_exec($ch);
        curl_close($ch);
        $this->kackyUids = array_flip(explode(",", $kackyUids));
    }

    public function command_rmc($command) {
        $params = null;
        $login = $command["author"]->login;
        $author = $command["author"];

        $usageString = '';
        if (in_array($login, $this->adminLogins)) {
            $usageString = 'Usage: /rmc <start|stop|skip|info|stats|leaderboard|lb|history|help>';
        } else {
            $usageString = 'Usage: /rmc <info|stats|leaderboard|lb|history|help>';
        }
        
        // no arguments, show help
        if ($command["params"] == '') {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . $usageString, $login);
            return;
        }

        $params = explode(' ', $command["params"]);

        // only 1 argument allowed
        if (count($params) > 1) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . $usageString, $login);
            return;
        }

        switch ($params[0]) {
            case 'start':
                $this->start($login);
                break;
            case 'stop':
                $this->stop($login);
                break;
            case 'skip':
                $this->skip($author);
                break;
            case 'info':
                $this->info($author);
                break;
            case 'stats':
                $this->drawStats($author);
                break;
            case 'leaderboard':
                $this->drawLeaderboard($author);
                break;
            case 'lb':
                $this->drawLeaderboard($author);
                break;
            case 'history':
                $this->drawHistory($author);
                break;
            case 'help':
                $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . $usageString, $login);
                break;
            default:
                $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . $usageString, $login);
                break;
        }
    }

    private function finish() {
        $this->showStats();
        $this->reset();
    }

    private function info($author) {
        $login = $author->login;
        if (!$this->enabled) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'RMC is not running!', $login);
            return;
        }

        $ownFinishAmount = [];
        forEach ($this->finishedChallenges as $info) {
            if ($info->login == $login) {
                $ownFinishAmount[] = $info;
            }
        }

        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'Total challenges finished: ' . count($this->finishedChallenges) . ', You finished: ' . count($ownFinishAmount) . ', Skips left: ' . $this->skipsLeft, $login);
    }

    private function reset() {
        global $jukebox_skipleft, $feature_votes, $feature_jukebox;

        $this->enabled = false;
        $this->nextMapQueued = false;
        $this->waitTime = 30;
        $this->timeLeft = $this->settings["timeLimit"];
        $this->skipsLeft = $this->settings["skipsLimit"];
        $this->challengePlayTime = 0;
        $this->finishedChallenges = [];
        $this->unfinishedChallenges = [];
        $jukebox_skipleft = $this->originalJukeboxSkipleft;
        $feature_votes = $this->originalFeatureVotes;
        $feature_jukebox = $this->originalFeatureJukebox;
    }

    public function start($login) {
        global $jukebox_skipleft, $feature_votes, $feature_jukebox;

        if ($this->enabled) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'RMC is already running!', $login);
            return;
        }

        if (!in_array($login, $this->adminLogins)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'You are not allowed to start RMC!', $login);
            return;
        }

        $this->reset();
        $this->updateDatabaseEvent();
        $this->enabled = true;
        $this->finishedChallenges = [];
        $jukebox_skipleft = false;
        $feature_votes = false;
        $feature_jukebox = false;

        // start queue for next map
        $this->queueNextChallenge();
        
        // increase wait time to 60 seconds for start
        $this->waitTime = 60; // TODO: change back to 60 later

        $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'RMC started! Beginning in 60 seconds!');
    }

    private function skip($author) {
        if (!$this->enabled) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'RMC is not running!', $author->login);
            return;
        }

        if (!in_array($author->login, $this->adminLogins)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'You are not allowed to skip!', $author->login);
            return;
        }

        if ($this->nextMapQueued) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'Next map is already queued!', $author->login);
            return;
        }

        if ($this->skipsLeft <= 0) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'No skips left!', $author->login);
            return;
        }

        if (!$this->queueNextChallenge()) {
            return;
        }

        $unfinishedStats = $this->processCompletedChallenge(null, true);
        $this->updateDatabaseStats($unfinishedStats);
        $this->skipsLeft--;
        $this->waitTime = 20;

        $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'Current map was skipped by admin! Skips left: ' . $this->skipsLeft);
    }

    // used in case when admin manually stops RMC
    public function stop($login) {
        if (!$this->enabled) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'RMC is not running!', $login);
            return;
        }

        if (!in_array($login, $this->adminLogins)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'You are not allowed to stop RMC!', $login);
            return;
        }

        $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'RMC stopped by admin!');
        $this->finish();
    }

    // used for drawHistory. lists a line per event + two buttons to show stats and leaderboard
    public function onPlayerManialinkPageAnswer($answer) {
        // $answer = [0]=PlayerUid, [1]=Login, [2]=Answer
		$login = $answer[1];
		$manialinkId = $answer[2];
        $eventDatabaseId = $manialinkId % 100000;
        $manialinkId = floor($manialinkId / 100000);

        if ($manialinkId == $this->settings["drawStatsManiaLinkId"]) {
            $this->drawStats($this->aseco->server->players->getPlayer($login), $eventDatabaseId);
        } else if ($manialinkId == $this->settings["drawLeaderboardManiaLinkId"]) {
            $this->drawLeaderboard($this->aseco->server->players->getPlayer($login), $eventDatabaseId);
        }
    }

    private function drawHistory($author) {
        $sql = "select e.id as eventId, count(s.finished) as eventFinishes, e.date as eventDate from rmc_events as e inner join rmc_stats as s on e.id = s.event_id where s.finished = 1 group by e.id, s.finished";
        $result = mysql_query($sql);
        if (!$result) {
            $this->aseco->console('RMC: Failed to load history!');
            trigger_error('RMC: Failed to load history!', E_USER_ERROR);
        }
        $events = [];
        while ($row = mysql_fetch_object($result)) {
            $events[] = $row;
        }

        if (count($events) == 0) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'No history found!', $author->login);
            return;
        }

        $header = "RMC - History MOTHERUFC KER";
        // eventId, stats button, leaderboard button, finishes, date
        $message = array( array( 1, $header, array( 0.9, 0.05, 0.13, 0.26, 0.15, 0.25 ), array( "Icons64x64_1", "TrackInfo" ) ) );

        $message[1][] = array("Id", "Stats", "Leaderboard", "Finishes", "Date");

        $lineNumber = 0;
        forEach($events as $event) {
            $page = floor($lineNumber/15)+1;
            $message[$page][] = array((string)$lineNumber+1, array("Stats", (int)($this->settings["drawStatsManiaLinkId"])*100000 + $event->eventId), array("Leaderboard", (int)($this->settings["drawLeaderboardManiaLinkId"])*100000 + $event->eventId), $event->eventFinishes, $event->eventDate);
            $lineNumber++;
        }        
        
        $author->msgs = $message;
        display_manialink_multi($author);
    }

    // creates widget which lists all finished maps + player who finished it + time it took
    private function drawStats($author, $eventDatabaseId = null) {
        if ($eventDatabaseId == null) {
            $eventDatabaseId = "(SELECT MAX(id) FROM rmc_events)";
        }

		$sql = "SELECT c.Name AS challengeName, p.NickName AS nickName, s.challenge_playtime as challengePlaytime, s.finished, s.skipped FROM rmc_stats AS s INNER JOIN challenges AS c ON s.challenge_id = c.Id LEFT JOIN players AS p ON s.player_id = p.Id WHERE s.event_id = " . $eventDatabaseId . " ORDER BY s.finished DESC, s.skipped DESC";
        $result = mysql_query($sql);
        if (!$result) {
            $this->aseco->console('RMC: Failed to load stats!');
            trigger_error('RMC: Failed to load stats!', E_USER_ERROR);
        }
        $mapsAmount = mysql_num_rows($result);
        $completedChallenges = [];
        while ($row = mysql_fetch_object($result)) {
            $completedChallenges[] = $row;
        }

        if ($mapsAmount == 0) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'No maps finished yet!', $author->login);
            return;
        }

        $header = "RMC - Finished Challenges";
		$message = array( array( 1, $header, array( 1.1 ), array( "Icons64x64_1", "TrackInfo" ) ) ); // element 0 are header information
		
		if ($mapsAmount > 0) {
			$lineNumber = 0;

            // show finished challenges
            $processUnfinished = false;
			forEach($completedChallenges as $info) {
                if ($info->finished == 0 && !$processUnfinished) {
                    $processUnfinished = true;
                    // add 1 new line + 1 line for header before showing unfinished challenges
                    $lineNumber++;
                    $page = floor($lineNumber/15)+1; // page[0] are header infos, page[1] will be 0 to 14 ...
                    $message[$page][] = array("");
                    $lineNumber++;  
                    $page = floor($lineNumber/15)+1;
                    $message[$page][] = array("Unfinished challenges:");
                    $lineNumber++;
                }

                if (!$processUnfinished) {
                    $page = floor($lineNumber/15)+1;
                    $message[$page][] = array((string)$lineNumber+1 . '.  ' . $info->challengeName . '$z$s$g - ' . $info->nickName . '$z$g$s  - ' . formatTime($info->challengePlaytime * 1000, false));
				    $lineNumber++;
                } else {
                    $page = floor($lineNumber/15)+1;
                    $str = $info->challengeName . '$z$s$g - ' . ($info->skipped == 1 ? 'Skipped' : 'Not finished in time');
                    $message[$page][] = array($str);
                    $lineNumber++;
                }
				
			}
		} else {
			$message[1][] = array("");
		}
		
		$author->msgs = $message;
		display_manialink_multi($author);
    }

    // creates widget which lists top players by finishes. it sorts by finishes and shows as 1. playername (finishes) 2. playername (finishes) etc.
    private function drawLeaderboard($author, $eventDatabaseId = null) {
        if ($eventDatabaseId == null) {
            $eventDatabaseId = "(SELECT MAX(id) FROM rmc_events)";
        }

        $sql = "SELECT p.NickName as nickName, l.finishes FROM rmc_leaderboard AS l INNER JOIN players AS p ON p.Id = l.player_id WHERE l.event_id = " . $eventDatabaseId . " ORDER BY l.finishes DESC";
        $result = mysql_query($sql);
        if (!$result) {
            $this->aseco->console('RMC: Failed to load leaderboard!');
            trigger_error('RMC: Failed to load leaderboard!', E_USER_ERROR);
        }
        $mapsAmount = mysql_num_rows($result);
        $leaderboard = [];
        while ($row = mysql_fetch_object($result)) {
            $leaderboard[] = $row;
        }

        if ($mapsAmount == 0) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->chat_prefix . 'No maps finished yet!', $author->login);
            return;
        }

        $header = "RMC - Leaderboard";
        $message = array( array( 1, $header, array( 0.76 ), array( "Icons64x64_1", "TrackInfo" ) ) ); // element 0 are header information

        if ($mapsAmount > 0) {
            $lineNumber = 0;

            forEach ($leaderboard as $info) {
                $page = floor($lineNumber/15)+1;
                $message[$page][] = array((string)$lineNumber+1 . '.  ' . $info->nickName . '$z$g$s  (' . $info->finishes . ')');
                $lineNumber++;
            }
        } else {
            $message[1][] = array("");
        }

        $author->msgs = $message;
        display_manialink_multi($author);
    }

    public function onBeginRound() {
        if (!$this->enabled) {
            return;
        }

        $this->nextMapQueued = false;
        $this->waitTime = 30;
        $this->flexitime->paused = false;
        $this->flexitime->time_left = $this->timeLeft;
        $this->challengePlayTime = 0;
    }

    public function onEverySecond() {
        if (!$this->enabled) {
            return;
        }

        // check if we are in podium mode or not and based on that unpause flexitime allways
        // TODO:
        
        if ($this->nextMapQueued) {
            $this->waitTime--;

            switch($this->waitTime) {
                case 10:
                    $randomChallenge = $this->jukeRandomChallenge();
                    $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'Next map will be: ' . $randomChallenge["Name"]);
                    break;
                case 2:
                    $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'Skipping to next map!');
                    break;
                case 0:
                    $this->aseco->client->query('NextChallenge');
                    break;
                default:
                    break;
            }

            return;
        }

        $this->timeLeft--;
        $this->challengePlayTime++;
        $this->flexitime->time_left = $this->timeLeft;

        // check if time is up
        if ($this->timeLeft <= 1) {
            $unfinishedStats = $this->processCompletedChallenge(null);
            $this->updateDatabaseStats($unfinishedStats);
            // set flexi time to 2 minutes to allow players to view the stats / just general waiting so things dont happen abruptly
            $this->flexitime->time_left = 120;
            $this->flexitime->paused = false;
            $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'Time is up! RMC stopped!');
            $this->finish();
        }
    }

    public function onPlayerFinish($finish_item) {
        if (!$this->enabled || $this->nextMapQueued) {
            return;
        }

        // check if its actually a finish and not a player giving up
        if ($finish_item->score === 0) {
            return;
        }

        $finishedStats = $this->processCompletedChallenge($finish_item);
        $this->updateDatabaseStats($finishedStats);
        $this->updateDatabaseLeaderboard($finishedStats);

        // print stats of player who finished
        $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . $finishedStats->nickname . '$z$g$s finished in ' . formatTime($this->challengePlayTime * 1000, false) . '!');

        $this->queueNextChallenge();
    }

    // prints some stats in chat
    private function showStats() {
        $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'RMC has finished!');
        // total challenges finished
        $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'Total challenges finished: ' . count($this->finishedChallenges));
        // skip stats
        $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'Skips used: ' . ($this->settings["skipsLimit"] - $this->skipsLeft));
        // show top 3 players
        $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . $this->createTop3PlayersString());
    }

    // creates useful info finished/unfinished challenges
    private function processCompletedChallenge($finish_item = null, $skipped = false) {
        $stats = new stdClass();
        $stats->challengeName = $this->aseco->server->challenge->name;
        $stats->challengeUid = $this->aseco->server->challenge->uid;
        $stats->login = $finish_item == null ? null : $finish_item->player->login;
        $stats->nickname = $finish_item == null ? null : $finish_item->player->nickname;
        $stats->challengePlayTime = $this->challengePlayTime;
        $stats->challengeDatabaseId = $this->aseco->server->challenge->id;
        $stats->playerDatabaseId = $finish_item == null ? null : $finish_item->player->id;
        $stats->skipped = $skipped;
        
        if ($finish_item === null) {
            $this->unfinishedChallenges[] = $stats;
        } else {
            $this->finishedChallenges[] = $stats;
        }
        
        return $stats;
    }

    private function checkAnyMapsLeft() {
        $this->aseco->client->query('GetChallengeList', 5000, 0);
        $response = $this->aseco->client->getResponse();
        if (count($response) == count($this->finishedChallenges) + count($this->unfinishedChallenges)) {
            $this->aseco->client->query('ChatSendServerMessage', $this->chat_prefix . 'No maps left! RMC stopped!');
            $this->finish();
            return false;
        }

        return true;
    }

    // create database layout to store history of finished challenges
    private function createDatabaseLayout() {
        // rmc_events: id, date, timelimit, skiplimit
        $sql = 'CREATE TABLE IF NOT EXISTS `rmc_events` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `timelimit` int(11) NOT NULL,
            `skipslimit` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
        $result = mysql_query($sql);
        if (!$result) {
            $this->aseco->console('RMC: Failed to create table rmc_events!');
            trigger_error('RMC: Failed to create table rmc_events!', E_USER_ERROR);
        }

        // rmc_stats: id, event_id, challenge_id, player_id, challenge_playtime, finished, skipped (event_id and challenge_id are combined unique)
        $sql = 'CREATE TABLE IF NOT EXISTS `rmc_stats` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `event_id` int(11) NOT NULL,
            `challenge_id` int(11) NOT NULL,
            `player_id` int(11) DEFAULT NULL,
            `challenge_playtime` int(11) NOT NULL,
            `finished` tinyint(1) NOT NULL,
            `skipped` tinyint(1) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `event_challenge` (`event_id`,`challenge_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
        $result = mysql_query($sql);
        if (!$result) {
            $this->aseco->console('RMC: Failed to create table rmc_stats!');
            trigger_error('RMC: Failed to create table rmc_stats!', E_USER_ERROR);
        }

        // rmc_leaderboard: id, event_id, player_id, finishes (how many times player finished) (event_id and player_id are combined unique)
        $sql = 'CREATE TABLE IF NOT EXISTS `rmc_leaderboard` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `event_id` int(11) NOT NULL,
            `player_id` int(11) NOT NULL,
            `finishes` int(11) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `event_player` (`event_id`,`player_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
        $result = mysql_query($sql);
        if (!$result) {
            $this->aseco->console('RMC: Failed to create table rmc_leaderboard!');
            trigger_error('RMC: Failed to create table rmc_leaderboard!', E_USER_ERROR);
        }
    }

    // creates short informational string
    private function createTop3PlayersString() {
        $out = '';

        $top3 = [];
        forEach ($this->finishedChallenges as $info) {
            if (!array_key_exists($info->login, $top3)) {
                $top3[$info->login] = [
                    "nickname" => $info->nickname,
                    "finishes" => 1
                ];
            } else {
                $top3[$info->login]["finishes"]++;
            }
        }

        $out .= 'Top 3 players (by finishes): ';

        uasort($top3, function($a, $b) {
            return $b["finishes"] <=> $a["finishes"];
        });

        $i = 0;
        forEach ($top3 as $login => $info) {
            $out .= ($i + 1) . '. ' . $info["nickname"] . '$z$g$s (' . $info["finishes"] . ') ';
            $i++;
            if ($i >= 3) {
                break;
            }
        }

        return $out;
    }
    
    private function jukeRandomChallenge($useKackyUids = true) {
        global $jukebox; // from rasp jukebox plugin
        
        // select random map from maplist
        $this->aseco->client->query('GetChallengeList', 5000, 0);
        $response = $this->aseco->client->getResponse();

        // kacky uid check, TODO: improve this later
        if ($useKackyUids) {
            $kackyChallenges = array_values(array_filter($response, function($challenge) {
                return isset($this->kackyUids[$challenge["UId"]]);
            }));
            
            if (count($kackyChallenges) > 0) {
                $response = $kackyChallenges;
            }
        }

        $randomChallenge = null;

        // filter maps that were already played
        while (true) {
            $randomIndex = rand(0, count($response) - 1);
            $randomChallenge = $response[$randomIndex];

            $found = false;
            forEach ($this->finishedChallenges as $info) {
                if ($info->challengeUid == $randomChallenge["UId"]) {
                    $found = true;
                    break;
                }
            }

            forEach ($this->unfinishedChallenges as $info) {
                if ($info->challengeUid == $randomChallenge["UId"]) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                break;
            }
        }

        // now hack the damn thing into the jukebox
        $uid = $randomChallenge["UId"];
        // im following the code form rasp jukebox plugin, its ugly and i hate it
        $jukebox = array_reverse($jukebox, true);
        $jukebox[$uid]["FileName"] = $randomChallenge["FileName"];
        $jukebox[$uid]["Name"] = $randomChallenge["Name"];
        $jukebox[$uid]["Env"] = $randomChallenge["Environnement"]; // Environnement is typo by nadeo
        $jukebox[$uid]["Login"] = $this->aseco->server->login;
        $jukebox[$uid]["Nick"] = $this->aseco->server->name;
        $jukebox[$uid]["Source"] = "RMC"; // TODO
        $jukebox[$uid]["tmx"] = false;
        $jukebox[$uid]["uid"] = $uid;
        $jukebox = array_reverse($jukebox, true);
        // TODO: what is 'play'?
        $this->aseco->releaseEvent('onJukeboxChanged', array('play', $jukebox[$uid]));

        return $randomChallenge;
    }

    // selects random map from maplist and queues it
    private function queueNextChallenge() {
        if (!$this->enabled || !$this->checkAnyMapsLeft()) {
            return false;
        }

        $this->nextMapQueued = true;
        $this->waitTime = 30;
        // pause flexitime to wait for queueNextChallenge() to finish
        $this->flexitime->paused = true;

        return true;
    }
    
    // update rmc_events. returns id of event (skips needs to be updated later aswell)
    private function updateDatabaseEvent() {
        $sql = "INSERT INTO rmc_events (timelimit, skipslimit) VALUES (" . $this->settings["timeLimit"] . ", " . $this->settings["skipsLimit"] . ")";
        $result = mysql_query($sql);
        if (!$result) {
            $this->aseco->console('RMC: Failed to save event!');
            trigger_error('RMC: Failed to save event!', E_USER_ERROR);
        }
    }

    // update rmc_stats
    private function updateDatabaseStats($info) {
        $sql = "INSERT INTO rmc_stats (event_id, challenge_id, player_id, challenge_playtime, finished, skipped) VALUES ((SELECT MAX(id) FROM rmc_events), " . $info->challengeDatabaseId . ", " . ($info->playerDatabaseId == null ? "NULL" : $info->playerDatabaseId) . ", " . $info->challengePlayTime . ", " . ($info->playerDatabaseId == null ? "0" : "1") . ", " . ($info->skipped ? "1" : "0") . ")";
        $result = mysql_query($sql);
        if (!$result) {
            $this->aseco->console('RMC: Failed to save stats!');
            trigger_error('RMC: Failed to save stats!', E_USER_ERROR);
        }
    }

    // update rmc_leaderboard
    private function updateDatabaseLeaderboard($info) {
        // check if player is already in leaderboard, use insert into or duplicate key update
        $sql = "INSERT INTO rmc_leaderboard (event_id, player_id) VALUES ((SELECT MAX(id) FROM rmc_events), " . $info->playerDatabaseId . ") ON DUPLICATE KEY UPDATE finishes = finishes + 1";
        $result = mysql_query($sql);
        if (!$result) {
            $this->aseco->console('RMC: Failed to save leaderboard!');
            trigger_error('RMC: Failed to save leaderboard!', E_USER_ERROR);
        }
    }
}



function rmc_onSync($aseco) {
    global $rmc, $realh_flexitime;
    $rmc = new RMC($aseco, $realh_flexitime);
}

function rmc_onBeginRound($aseco) {
    global $rmc;
    $rmc->onBeginRound();
}

function rmc_onEverySecond($aseco) {
    global $rmc;
    $rmc->onEverySecond();
}

function rmc_onPlayerFinish($aseco, $player) {
    global $rmc;
    $rmc->onPlayerFinish($player);
}

function rmc_onPlayerManialinkPageAnswer($aseco, $answer) {
    global $rmc;
    $rmc->onPlayerManialinkPageAnswer($answer);
}

function chat_rmc($aseco, $command) {
    global $rmc;
    $rmc->command_rmc($command);
}
