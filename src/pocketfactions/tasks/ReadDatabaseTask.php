<?php

namespace pocketfactions\tasks;

use pocketfactions\faction\State;
use pocketfactions\utils\FactionList;
use pocketfactions\faction\Chunk;
use pocketfactions\faction\Faction;
use pocketfactions\faction\Rank;
use pocketfactions\Main;
use pocketmine\level\Position;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class ReadDatabaseTask extends AsyncTask{
	const CORRUPTED	= 1;
	const COMPLETED	= null;
	const WIP		= null;
	public function __construct($res, callable $onFinished, callable $statesSetter){
		$this->res = $res;
		$this->onFinished = $onFinished;
		$this->statesSetter = $statesSetter;
	}
	public function onRun(){
		$res = $this->res;
		$this->setResult(self::WIP);
		$prefix = $this->read($res, strlen(FactionList::MAGIC_P));
		if($prefix !== FactionList::MAGIC_P){
			$this->setResult(self::CORRUPTED);
			return;
		}
		$version = $this->read($res, 1);
		if($version !== Main::V_CURRENT){
			switch($version){
				case Main::V_INIT:
					break;
			}
		}
		$total = Bin::readBin($this->read($res, 4));
		$factions = array();
		for($i = 0; $i < $total; $i++){
			$id = Bin::readBin($this->read($res, 4));
			$name = Bin::readBin($this->read($res, 1));
			$whitelist = false;
			if($name & 0b10000000){
				$whitelist = true;
			}
			$name &= 0b01111111;
			$name = $this->read($res, $name);
			$motto = Bin::readBin($this->read($res, 2));
			$motto = $this->read($res, $motto);
			$founder = Bin::readBin($this->read($res, 1));
			$founder = $this->read($res, $founder);
			$ranks = array();
			for($i = 0; $i < Bin::readBin($this->read($res, 1)); $i++){
				$id = Bin::readBin($this->read($res, 1));
				$rkName = Bin::readBin($this->read($res, 1));
				$rkName = $this->read($res, $rkName);
				$perms = Bin::readBin($this->read($res, 2));
				$ranks[$id] = new Rank($id, $rkName, $perms);
			}
			$defaultRank = Bin::readBin($this->read($res, 1));
			if(!isset($ranks[$defaultRank])){
				trigger_error("Cannot find default rank $defaultRank from resource {$this->res}", E_USER_WARNING);
				$this->setResult(self::CORRUPTED);
				return;
			}
			$defaultRank = $ranks[$defaultRank];
			/**
			 * @var Rank[] $members Ranks indexed by member names, object reference from $ranks (not cloned)
			 */
			$members = array();
			for($i = 0; $i < Bin::readBin($this->read($res, 4)); $i++){
				$mbName = Bin::readBin($this->read($res, 1));
				$mbName = $this->read($res, $mbName);
				$members[$mbName] = $ranks[Bin::readBin($this->read($res, 1))]; // not cloned
			}
			$lastActive = Bin::readBin($this->read($res, 8));
			$chunks = array();
			for($i = 0; $i < Bin::readBin($this->read($res, 2)); $i++){
				$X = Bin::readBin($this->read($res, 2));
				$Z = Bin::readBin($this->read($res, 2));
				$world = Bin::readBin($this->read($res, 1));
				$world = $this->read($res, $world);
				$chunks[] = new Chunk($X, $Z, $world);
			}
			if(count($chunks) == 0){
				$this->setResult(self::CORRUPTED);
				return;
			}
			$baseChunk = array_shift($chunks);
			$x = Bin::readBin($this->read($res, 4)) - 2147483648;
			$y = Bin::readBin($this->read($res, 2));
			$z = Bin::readBin($this->read($res, 4)) - 2147483648;
			$level = Bin::readBin($this->read($res, 1));
			$level = $this->read($res, $level);
			$server = Server::getInstance();
			if(!$server->isLevelLoaded($level)){
				if(!$server->isLevelGenerated($level)){
					$server->generateLevel($level, null);
				}
				$server->loadLevel($level);
			}
			$level = $server->getLevel($level);
			$home = new Position($x, $y, $z, $level);
			$factions[$id] = new Faction(array(
				"name" => $name,
				"motto" => $motto,
				"id" => $id,
				"founder" => $founder,
				"ranks" => $ranks,
				"default-rank" => $defaultRank,
				"members" => $members,
				"last-active" => $lastActive,
				"chunks" => $chunks,
				"base-chunk" => $baseChunk,
				"whitelist" => $whitelist,
				"home" => $home,
			));
		}
		$states = [];
		for($i = 0; $i < Bin::readBin($this->read($res, 8)); $i++){
			$f0 = Bin::readBin($this->read($res, 4));
			$f1 = Bin::readBin($this->read($res, 4));
			$state = Bin::readBin($this->read($res, 1));
			$states[] = new State($factions[$f0], $factions[$f1], $state);
		}
		if($this->read($res, strlen(FactionList::MAGIC_S)) !== FactionList::MAGIC_S){
			$this->setResult(self::CORRUPTED);
			return;
		}
		if($this->getResult() === self::WIP){
			$this->setResult(self::COMPLETED);
		}
		fclose($res);
		call_user_func($this->statesSetter, $states);
		call_user_func($this->onFinished, $factions, $this);
	}
	protected function read($res, $length){
		$string = fread($res, $length);
		if(!is_string($string) or strlen($string) !== $length){
			$this->setResult(self::CORRUPTED);
			trigger_error("Database corrupted!", E_USER_WARNING);
		}
		return $string;
	}
}
