<?php

namespace pocketfactions\utils;

use pocketfactions\faction\State;
use pocketfactions\Main;
use pocketfactions\faction\Chunk;
use pocketfactions\faction\Faction;
use pocketfactions\tasks\ReadDatabaseTask;
use pocketfactions\tasks\WriteDatabaseTask;

use pocketmine\IPlayer;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class FactionList{
	const MAGIC_P = "\x00\x00\xff\xffFACTION-LIST";
	const MAGIC_S = "END-OF-LIST-\xff\xff\x00\x00";
	/**
	 * @var bool|Faction[]
	 */
	private $factions = false;
	/**
	 * @var null|AsyncTask
	 */
	public $currentAsyncTask = null;
	/**
	 * @var State[]
	 */
	private $states = [];
	public function __construct(){
		$this->path = Main::get()->getFactionsFilePath();
		$this->server = Server::getInstance();
		$this->load();
	}
	protected function load(){
		$this->loadFrom(fopen($this->path, "rb"));
	}
	/**
	 * @param resource $res
	 */
	public function loadFrom($res){
		$this->scheduleAsyncTask(new ReadDatabaseTask($res, array($this, "setAll"), array($this, "setFactionsStates")));
	}
	public function save(){
		$this->saveTo(fopen($this->path, "wb"));
	}
	/**
	 * @param resource $res
	 */
	public function saveTo($res){
		$this->scheduleAsyncTask(new WriteDatabaseTask($res));
	}
	/**
	 * @param AsyncTask $asyncTask
	 */
	public function scheduleAsyncTask(AsyncTask $asyncTask){
		if(($this->currentAsyncTask instanceof AsyncTask) and !$this->currentAsyncTask->isFinished()){
			trigger_error("Attempt to schedule an I/O task at Factions database rejected due to another I/O operation at the same resource running");
		}
		$this->server->getScheduler()->scheduleAsyncTask($asyncTask);
	}
	/**
	 * @param Faction[] $factions
	 */
	public function setAll(array $factions){
		$this->factions = $factions;
	}
	public function __destruct(){
		$this->save();
	}
	/**
	 * @return bool|Faction[]
	 */
	public function getAll(){
		return $this->factions;
	}
	/**
	 * @param string|int|IPlayer|Chunk $identifier
	 * @return bool|null|Faction
	 */
	public function getFaction($identifier){
		if($this->factions === false){
			return null;
		}
		switch(true){
			case is_string($identifier): // faction name
				foreach($this->factions as $faction){
					if($faction->getName() === $identifier){
						return $faction;
					}
				}
				return false;
			case is_int($identifier):
				return isset($this->factions[$identifier]) ? $this->factions[$identifier]:false;
			case $identifier instanceof IPlayer:
				foreach($this->factions as $faction){
					if(in_array(strtolower($identifier->getName()), $faction->getMembers())){
						return $faction;
					}
				}
				return false;
			case $identifier instanceof Chunk:
				foreach($this->factions as $faction){
					if($faction->hasChunk($identifier)){
						return $faction;
					}
				}
				return false;
			default:
				return false;
		}
	}
	public function addFaction(array $args, $id){
		$this->factions[$id] = new Faction($args);
	}
	public function getFactionsState(Faction $f0, Faction $f1){
		return $this->states[$f0->getID()."-".$f1->getID()];
	}
	public function setFactionsState(State $state){
		$this->states[$state->getF0()->getID()."-".$state->getF1()->getID()] = $state;
	}
	/**
	 * @param State[] $states
	 */
	public function setFactionsStates(array $states){
		foreach($states as $state){
			$this->setFactionsState($state);
		}
	}
	/**
	 * @return State[]
	 */
	public function getFactionsStates(){
		return $this->states;
	}
}
