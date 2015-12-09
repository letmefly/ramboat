<?php
include_once 'helper.php';
include_once 'db.php';

ini_set('date.timezone','Asia/Shanghai');

function handler_updateLoginInfo($redis, $channel, $msg) {
	helper_log("redis submsg: " . $msg);
	$pubMsg = json_decode($msg, true);
	$db = new DB();
	$db->updateUser($pubMsg);
}

class GameData {
	private $USER_HASH = 'RAMBOAT_USER_HASH';
	private $PREFIX = '';
	private $SCORE_RANK = 'SCORE';
	private $YESTERDAY_SCORE_RANK = 'YESTERDAYSCORE';
	private $LAST_SCORE_RANK = 'LASTSCORE';
	private $MAX_SCORE_RANK = 'MAXSCORE';
	private $LOGIN_RANK = 'LOGIN';
	private $NAMELIST = 'NAMELIST';
	private $db;
	private $redis;
	private $FRESH_RECORD = 'FRESH_RECORD';

	function __construct() {
		$this->db = new DB();
		$this->redis = new Redis();
		$this->redis->connect('127.0.0.1', 6380);
	}
	function __destruct() {
	}

	public function addNewUser($userInfo) {
		$day = intval(date("w"));
		if ($day == 0) {$day = 7;}
		$dataArray = array(
			'USERID' => $userInfo['userId'],
			'NAME' => $userInfo['name'],
			'PW' => $userInfo['pw'],
			'ICON' => $userInfo['icon'],
			'LEVEL' => 0,
			'SCORE' => 0,
			'MAXSCORE' => 0,
			// 'LOGINTIMES' => 0,
			// 'LOGINSTAMP' => 0,
			'LASTLOGINDAY' => $day,
			'LASTWEEKRANK' => '',
			'RANK1' => '',
			'RANK2' => '',
			'RANK3' => '',
			'RANK4' => '',
			'RANK5' => '',
			'RANK6' => '',
			'RANK7' => '',
			'MILITARY' => 1
		);
		if ($dataArray['ICON'] == "") {
			$dataArray['ICON'] = '0';
		}
		$ret = $this->db->insertUser($dataArray);
	}


	public function userNameCheck($userName) {
		$userName =preg_replace("/\s|¡¡/","",$userName);
		if($userName == '') {
			return array ('code' => 1002);
		}
		if ($this->redis->sIsMember($NAMELIST, $userName)) {
			return array ('code' => 1001);
		}
		$row = $this->db->selectUserByName(array('USERNAME' => $userName));
		if ($row) {
			$this->redis->sAdd($this->NAMELIST, $row['NAME']);
			return array ('code' => 1001);
		}
		require_once('badword.src.php');
		$badword1 = array_combine($badword,array_fill(0,count($badword),'*'));
		$str = $userName;
		$str = strtr($str, $badword1);
		if ($str != $userName) {
			$pos = 0;
			for($i=1; $i<strlen($str); $i++) {
				if ($str[$i] == '*') {
					$pos = $i;
					break;
				}
			}
			$strSub = strlen(substr($str, $pos+1));
			$strBefore = strlen(substr($userName, 0, $pos));
			$strAfter = 0;
			if ($strSub != 0) {
				$strAfter = strlen(substr($userName, $strSub*-1));
			}
			$change = array (
				$strBefore => '',
				$strAfter => '');
			$strResult = substr($userName, $pos, strlen($userName)-$pos-$strAfter);
			return array ('code' => 1003, 'msg' => $strResult);
		}
		return false;
	}


	public function updateLoginInfo($userId, $timeStamp, $loginTimes) {
		// $this->redis->zAdd($this->LOGIN_RANK, $loginTimes, $userId);
		// $pubMsg = array('USERID' => $userId, 'LOGINTIMES' => $loginTimes, 'LOGINSTAMP' => $timeStamp);
		// $this->redis->publish('logininfo_chan', json_encode($pubMsg));
		$this->updateUserInfo(array('USERID' => $userId, 'LOGINTIMES' => $loginTimes, 'LOGINSTAMP' => $timeStamp));
	}

	public function modifyUserInfo($userId, $newname, $newicon) {
		$modifyInfo = array("USERID" => $userId);
		if ($newname) {
			$modifyInfo['NAME'] = $newname;
		}
		if ($newicon) {
			$modifyInfo['ICON'] = $newicon;
		}
		$this->updateUserInfo($modifyInfo);
	}

	public function updateUserScore($userId, $newscore, $newIcon, $military, $shipType) {
		$updateInfo = array("USERID" => $userId);
		if ($newscore) {
			$updateInfo['SCORE'] = $newscore;
		}
		//if ($newIcon) {
			$updateInfo['ICON'] = $newIcon;
		//}
		if ($military) {
			$updateInfo['MILITARY'] = $military;
		}
		//if ($shipType) {
			$updateInfo['SHIPTYPE_WILL_BE_UNSET'] = $shipType;
		//}
		//$userInfo = $this->getUserInfo($userId);
		$userScore = $this->redis->zScore($this->SCORE_RANK, $userId);
		if ($updateInfo['SCORE'] <= $userScore) {
			return 2001;
		}
		$this->updateUserInfo($updateInfo);
		return 1;
	}

	private function updateUserInfo($updateInfo) {
		if (!$updateInfo || !isset($updateInfo['USERID'])) {
			helper_log("[updateUserInfo] param invalid");
			return;
		}
		$userId = $updateInfo['USERID'];
		// 1. update userinfo memcache
		$userInfo = $this->getUserInfo($userId);
		foreach ($updateInfo as $key => $value) {
			$userInfo[$key] = $value;
		}
		// $this->redis->set($this->PREFIX . $userId, json_encode($userInfo));
		$this->redis->hSet($this->USER_HASH, $this->PREFIX . $userId, json_encode($userInfo));

		// 2. update score rank memchache
		if ($updateInfo['SCORE']) {
			
			$this->redis->zAdd($this->SCORE_RANK, intval($updateInfo['SCORE']), $userId);
			if ($updateInfo['SCORE'] > $userInfo['MAXSCORE']) {
				$this->redis->zAdd($this->MAX_SCORE_RANK, intval($updateInfo['SCORE']), $userId);
				$userInfo['MAXSCORE'] = $updateInfo['SCORE'];
				// update maxscore
				$updateInfo['MAXSCORE'] = $updateInfo['SCORE'];
			}
			// $this->redis->set($this->PREFIX . $userId, json_encode($userInfo));
			$this->redis->hSet($this->USER_HASH, $this->PREFIX . $userId, json_encode($userInfo));
		}

		// 3. update login rank memcache
		if ($updateInfo['LOGINTIMES']) {
			$this->redis->zAdd($this->LOGIN_RANK, intval($updateInfo['LOGINTIMES']), $userId);
		}

		// 2. update mysql async
		unset($updateInfo['SHIPTYPE_WILL_BE_UNSET']);
		$this->redis->publish('userinfo_chan', json_encode($updateInfo));
	}

	// public function updateYesterdayRank() {
	// 	$dateInfo = intval(date('w'))-1;
	// 	$this->redis->delete($this->YESTERDAY_SCORE_RANK);
	// 	$rows = $this->db->selectUserRankByDate($dateInfo);
	// 	if ($rows) {
	// 		foreach ($rows as $row) {
	// 			$this->redis->zAdd($this->YESTERDAY_SCORE_RANK, intval($row['RANK'.$dateInfo]), $row['USERID']);
	// 		}
	// 	}
	// }

	public function getUserInfo($userId) {
		// $userInfoStr = $this->redis->get($this->PREFIX . $userId);
		$userInfoStr = $this->redis->hGet($this->USER_HASH, $this->PREFIX . $userId);
		if ($userInfoStr) {
			return json_decode($userInfoStr, true);
		} else {
			$row = $this->db->selectUser(array('USERID' => $userId));
			if ($row) {
				// $this->redis->set($this->PREFIX . $userId, json_encode($row));
				$this->redis->hSet($this->USER_HASH, $this->PREFIX . $userId, json_encode($row));
				$this->redis->sAdd($this->NAMELIST, $row['NAME']);
				//$this->redis->zAdd($this->SCORE_RANK, intval($row['SCORE']), $userId);
				$this->redis->zAdd($this->MAX_SCORE_RANK, intval($row['MAXSCORE']), $userId);
			}
			return $row;
		}
	}
/*
	public function getScoreRank($userId) {
		$rankList = array();
		$userList = $this->redis->zRevRange($this->SCORE_RANK, 0, 98, true);
		foreach ($userList as $key => $value) {
			$userInfo = $this->getUserInfo($key);
			$rankNum = $this->redis->zRevRank($this->SCORE_RANK, $key) + 1;
			$rankInfo = array(
				"uid" => $userInfo['USERID'],
				"name" => $userInfo['NAME'],
				"icon" => strval($userInfo['ICON']),
				"score" => intval($userInfo['SCORE']),
				"rank" => $rankNum
			);
			if ($rankInfo["uid"] && $rankInfo["name"]) {
				array_push($rankList, $rankInfo);
			}
		}
		if (!isset($userList[$userId])) {
			$userInfo = $this->getUserInfo($userId);
			$rankNum = $this->redis->zRevRank($this->SCORE_RANK, $userId) + 1;
			$rankInfo = array(
				"uid" => $userInfo['USERID'],
				"name" => $userInfo['NAME'],
				"icon" => strval($userInfo['ICON']),
				"score" => intval($userInfo['SCORE']),
				"rank" => $rankNum
			);
			if ($rankInfo["uid"] && $rankInfo["name"]) {
				array_push($rankList, $rankInfo);
			}
		}

		return $rankList;
	}
*/

	 public function getScoreRank($userId) {
                $rankList = array();
                $userList = $this->redis->zRevRange($this->SCORE_RANK, 0, 98, true);
			$rankNum = 0;
                foreach ($userList as $key => $value) {
			if (!$key) {continue;}
			$rankNum = $rankNum + 1;
                        $userInfo = $this->getUserInfo($key);
                        //$rankNum = $this->redis->zRevRank($this->SCORE_RANK, $key) + 1;
                        $userScore = $this->redis->zScore($this->SCORE_RANK, $key);
                        $rankInfo = array(
                                "uid" => $userInfo['USERID'],
                                "name" => $userInfo['NAME'],
                                "icon" => strval($userInfo['ICON']),
                                "score" => $userScore,
						"military" => intval($userInfo['MILITARY']),
						"ship" => intval($userInfo['SHIPTYPE_WILL_BE_UNSET']),
                                "rank" => $rankNum
                        );
                        if ($rankInfo["uid"] && $rankInfo["name"]) {
                                array_push($rankList, $rankInfo);
                        }
                }
                if (!isset($userList[$userId])) {
                        $userInfo = $this->getUserInfo($userId);
                        $userScore = $this->redis->zScore($this->SCORE_RANK, $userId);
                        $rankNum = $this->redis->zRevRank($this->SCORE_RANK, $userId) + 1;
                        if (!$userScore) {
                            $userScore = 0;
                            $rankNum = $this->redis->zSize($this->SCORE_RANK) + 1;
                        }
                        $rankInfo = array(
                                "uid" => $userInfo['USERID'],
                                "name" => $userInfo['NAME'],
                                "icon" => strval($userInfo['ICON']),
                                "score" => $userScore,
						"military" => intval($userInfo['MILITARY']),
						"ship" =>intval($userInfo['SHIPTYPE_WILL_BE_UNSET']),
                                "rank" => $rankNum
                        );
                        if ($rankInfo["uid"] && $rankInfo["name"]) {
                                array_push($rankList, $rankInfo);
                        }
                }

                return $rankList;
        }

	 public function getTotalScoreRank() {
                $rankList = array();
                $userList = $this->redis->zRevRange($this->SCORE_RANK, 0, -1, true);
			$rankNum = 0;
                foreach ($userList as $key => $value) {
			if (!$key) {continue;}
			$rankNum = $rankNum + 1;
                        $userInfo = $this->getUserInfo($key);
                        //$rankNum = $this->redis->zRevRank($this->SCORE_RANK, $key) + 1;
                        $userScore = $this->redis->zScore($this->SCORE_RANK, $key);
                        $rankInfo = array(
                                "uid" => $userInfo['USERID'],
                                "name" => $userInfo['NAME'],
                                "icon" => strval($userInfo['ICON']),
                                "score" => $userScore,
				"military" => intval($userInfo['MILITARY']),
				"ship" => intval($userInfo['SHIPTYPE_WILL_BE_UNSET']),
                                "rank" => $rankNum
                        );
                        if ($rankInfo["uid"] && $rankInfo["name"]) {
                                array_push($rankList, $rankInfo);
                        }
                }
                return $rankList;
        }

	public function getSelfScoreRank($userId) {

		$userInfo = $this->getUserInfo($userId);
		$userScore = $this->redis->zScore($this->SCORE_RANK, $userId);
		$rankNum = $this->redis->zRevRank($this->SCORE_RANK, $userId) + 1;
		if (!$userScore) {
                    $userScore = 0;
                    $rankNum = $this->redis->zSize($this->SCORE_RANK) + 1;
                }
		if(!$userInfo['SHIPTYPE_WILL_BE_UNSET']) {
			$userInfo['SHIPTYPE_WILL_BE_UNSET'] = 0;
		}
		$rankInfo = array(
			"uid" => $userInfo['USERID'],
			"name" => $userInfo['NAME'],
			"icon" => strval($userInfo['ICON']),
			"score" => $userScore,
			"military" => intval($userInfo['MILITARY']),
			"ship" => intval($userInfo['SHIPTYPE_WILL_BE_UNSET']),
			"rank" => $rankNum
		);
		return $rankInfo;
	}

	public function getLoginRank($userId) {
		$rankList = array();
		$userList = $this->redis->zRevRange($this->LOGIN_RANK, 0, 99, true);
		foreach ($userList as $key => $value) {
			$userInfo = $this->getUserInfo($key);
			$rankInfo = array("name" => $userInfo['NAME'], "time" => intval($value));
			array_push($rankList, $rankInfo);
		}
		if (!isset($userList[$userId])) {
			$userInfo = $this->getUserInfo($userId);
			$rankInfo = array("name" => $userInfo['NAME'], "time" => intval($value));
			array_push($rankList, $rankInfo);
		}
		return $rankList;
	}

	public function getLastRank($userId, $day) {
		$row = $this->db->selectUserRank($userId, $day);
		$rankJson = $row['RANK'.$day];
		return json_decode($rankJson, true);
	}

	public function getReward($userId) {
		date_default_timezone_set("PRC");
		if ($this->redis->get($FRESH_RECORD) != date("Ymd")) {
			return null;
		}

		$userInfo = $this->getUserInfo($userId);
		$day = intval(date("w"));
		if ($day == 0) {$day = 7;}
		$lastDay = $userInfo['LASTLOGINDAY'];
		if ($day != $lastDay) {
			$updateInfo = array("USERID" => $userId, 'LASTLOGINDAY' => $day);
			$this->updateUserInfo($updateInfo);
			$rankInfo = $this->getLastRank($userId, ($lastDay)%7+1);
			foreach ($rankInfo as $value) {
				if ($value['uid'] == $userId) {
					$rank = $value['rank'];
					$reward = $this->getRewardInfo($rank);
					return $reward;
				}
			}
		}
		return null;
	}

	public function getRewardInfo($rank) {

		require_once('rewardconfig.php');
		foreach ($ramboatDailyRewardConfig as $key => $value) {
			if ($rank >= $value['start'] && $rank <= $value['end']) {
				$reward = array ();
				$reward['item'] = $value['reward'];
				$reward['count'] = $value['count'];
				$reward['type'] = intval($value['type']);
				$reward['rank'] = $rank;
				return $reward;
			}
		}
		return null;
	}

	public function backupScoreRank($userId) {
		$scoreRank = $this->getScoreRank2($userId, 10);
		$thisDayRank = json_encode($scoreRank);
		$thisDayRank = mysql_real_escape_string($thisDayRank);
		$day = intval(date("w"));
		if ($day == 0) {$day = 7;}
		$rankName = "RANK".$day;
		$updateInfo = array("USERID" => $userId, $rankName => $thisDayRank);
		$this->db->updateUser($updateInfo);	
	}

	public function backupScoreRank_redis() {
		$this->redis->zAdd("SCORE_RANK_BACKUP", 0, "a1234567890ff");
		$scoreRankKeyValue_backup = $this->redis->zRevRange("SCORE_RANK_BACKUP", 0, -1, true);
		foreach ($scoreRankKeyValue_backup as $key => $value) {
			$this->redis->zDelete("SCORE_RANK_BACKUP", $key);
		}

		$scoreRankKeyValue = $this->redis->zRevRange($this->SCORE_RANK, 0, -1, true);
		foreach ($scoreRankKeyValue as $key => $value) {
			$userScore = $this->redis->zScore($this->SCORE_RANK, $key);
			$this->redis->zAdd("SCORE_RANK_BACKUP", $userScore, $key);
		}
	}

	public function getLastDayRank_redis($userId) {
		$userScore = $this->redis->zScore("SCORE_RANK_BACKUP", $userId);
		if (!$userScore) {return array();}		
		$rankList = array();
		$userList = $this->redis->zRevRange("SCORE_RANK_BACKUP", 0, 99, true);
		foreach ($userList as $key => $value) {
			$userInfo = $this->getUserInfo($key);
			$userScore = $this->redis->zScore("SCORE_RANK_BACKUP", $key);
			$rankNum = $this->redis->zRevRank("SCORE_RANK_BACKUP", $key) + 1;
			$rankInfo = array(
				"uid" => $userInfo['USERID'],
				"name" => $userInfo['NAME'],
				"icon" => strval($userInfo['ICON']),
				"score" => $userScore,
				"rank" => $rankNum,
				"military" => intval($userInfo['MILITARY']),
	                     	"ship" => intval($userInfo['SHIPTYPE_WILL_BE_UNSET']),
			);
			if ($rankInfo["uid"] && $rankInfo["name"]) {
				array_push($rankList, $rankInfo);
			}
		}
		if (!isset($userList[$userId])) {
			$userInfo = $this->getUserInfo($userId);
			$userScore = $this->redis->zScore("SCORE_RANK_BACKUP", $userId);
			$rankNum = $this->redis->zRevRank("SCORE_RANK_BACKUP", $userId) + 1;
			$rankInfo = array(
				"uid" => $userInfo['USERID'],
				"name" => $userInfo['NAME'],
				"icon" => strval($userInfo['ICON']),
				"score" => $userScore,
				"rank" => $rankNum,
                                "military" => intval($userInfo['MILITARY']),                             
                                "ship" => intval($userInfo['SHIPTYPE_WILL_BE_UNSET']),
			);
			if ($rankInfo["uid"] && $rankInfo["name"]) {
				array_push($rankList, $rankInfo);
			}
		}

		return $rankList;
	}

	public function clearTodayRank() {
		//$this->redis->flushdb();
		//$updateInfo = array('SCORE' => 0);
		//$this->db->updateAllUser($updateInfo);
		//date_default_timezone_set("PRC");
		//$this->redis->set($FRESH_RECORD, date("Ymd"));
		$scoreRankKeyValue = $this->redis->zRevRange($this->SCORE_RANK, 0, -1, true);
                foreach ($scoreRankKeyValue as $key => $value) {
                        $this->redis->zDelete($this->SCORE_RANK, $key);
                }
	}

	public function clearWeekRank() {

		$updateInfo = array();
		for ($i=2; $i<=7; $i++) {
			$updateInfo['RANK'.$i] = '';
		}
		return $this->db->updateAllUser($updateInfo);
	}

	public function getAllUserId () {
		// $rows = $this->db->selectAllUser();
		// $result = array ();
		// foreach ($rows as $value) {
		// 	array_push($result, $value['USERID']);
		// 	// $this->getUserInfo($value['USERID']);
		// }
		// return $result;
		return $this->redis->hKeys($this->USER_HASH);
	}
}

?>

