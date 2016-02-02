<?php

namespace TeamChat;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;

use pocketmine\Player;
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\level\Position;
use pocketmine\plugin\PluginBase;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\scheduler\PluginTask;
use pocketmine\Server;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;

class Main extends PluginBase implements Listener{

	private $request;
	
	public function onEnable(){
		@mkdir($this->getDataFolder());
		$this->teams = new Config($this->getDataFolder()."Groups.yml", Config::YAML, array());
		$this->config = (new Config($this->getDataFolder()."config.yml", Config::YAML, array("Friendly-Fire" => true)))->getAll();
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
    }
	
	public function onCommand(CommandSender $sender, Command $cmd, $label, array $args) {
		$name = strtolower($sender->getName());
		if(count($args) === 0) $args[0] = "help";
			switch($args[0]){
				case "c":
					if(!isset($args[1])){
						$sender->sendMessage("/tc c <Message>");
						return true;
					}
					$team = $this->inTeam($name);
					if($team){
						array_shift($args);
						$this->sendToTeam($team,$sender->getName(),implode(' ',$args));
					}else{
						$sender->sendMessage("You are not in a group");
					}
				break;
				case "create":
					if(!isset($args[1])){
						$sender->sendMessage("/tc create <Group Name>");
						return true;
					}
					$team = $this->inTeam($name);
					if($team){
						$sender->sendMessage("You are already in a group");
					}elseif($this->teams->get($args[1],null)){
						$sender->sendMessage("That name has already been used, please use a different name");
					}else{
						$this->teams->set($args[1],[$name]);
						$this->teams->save();
						$sender->sendMessage("Group created successfully!");
					}
				break;
				case "inv":
					if(!isset($args[1])){
						$sender->sendMessage("/tc inv <player>");
						return true;
					}
					$team = $this->inTeam($name);
					if($team){
						$p = $this->getServer()->getPlayer($args[1]);
						if($p){
							if($this->inTeam($p->getName())){
								$p->sendMessage("That player is already in a group");
							}else{
								$this->request[strtolower($p->getName())] = $team;
								$sender->sendMessage("Invitation sent");
								$p->sendMessage($sender->getName()."invites you to their group \"$team\"。 To accept: /tc accept, To deny: /tc deny");
								$this->task[strtolower($p->getName())] = $this->getServer()->getScheduler()->scheduleDelayedTask(new PluginCallbackTask($this, [$this,"removeRequest"],[$name]),600);
							}
						}else{
							$sender->sendMessage("Player not found");
						}
					}else{
						$sender->sendMessage("You are not in a group");
					}
				break;
				case "kick":
					if(!isset($args[1])){
						$sender->sendMessage("/tc kick <player>");
						return true;
					}
					if(strtolower($args[1]) === $name){
						$sender->sendMessage("You cannot kick yourself,use /tc leave");
						return true;
					}
					$team = $this->inTeam($name);
					if($team){
						if($this->inTeam($args[1])){
							$p = $this->getServer()->getPlayer($args[1]);
							$this->sendToTeam($team,null,$sender->getName()." kicked $args[1] out of the group");
							$this->removeFromTeam($args[1],$team);
						}else{
							$sender->sendMessage("Player is not in your group");
						}
					}else{
						$sender->sendMessage("You are not in a group");
					}
				break;
				case "name":
					if(!isset($args[1])){
						$sender->sendMessage("/tc name <new name>");
						return true;
					}
					$team = $this->inTeam($name);
					if($team){
						$teams = $this->teams->getAll();
						if(isset($teams[$args[1]])){
							$sender->sendMessage("That name has already been used, please use a different name");
							return true;
						}
						$teams[$args[1]] = $teams[$team];
						unset($teams[$team]);
						$this->teams->setAll($teams);
						$this->teams->save();
						$this->sendToTeam($args[1],null,$sender->getName()." changed the conversation name to \"$args[1]\"");
					}else{
						$sender->sendMessage("You are not in a group");
					}
				break;
				case "leave":
					$team = $this->inTeam($name);
					if($team){
						if(count($this->teams->getAll()[$team]) - 1){
							$this->removeFromTeam($name,$team);
							$sender->sendMessage("Successfully left the group");
							$this->sendToTeam($team,null,$sender->getName()." left the group");
						}else{
							$this->teams->remove($team);
							$this->teams->save();
							$sender->sendMessage("Successfully left and deleted the group");
						}
					}else{
						$sender->sendMessage("You are not in a group");
					}
				break;
				case "list":
					$team = $this->inTeam($name);
					if($team){
						$sender->sendMessage(TextFormat::GREEN."-----Members of \"$team\"-----\n".implode(' ',$this->teams->get($team)));
					}else{
						$sender->sendMessage("You are not in a group");
					}
				break;
				case "accept":
					if(isset($this->request[$name])){
						$this->addToTeam($name,$this->request[$name]);
						$sender->sendMessage("Successfully joined \"".$this->request[$name]."\"");
						$this->sendToTeam($this->request[$name],null,$sender->getName()." joined the group");
						unset($this->request[$name]);
						if(isset($this->task[$name])) $this->task[$name]->cancel();
					}else{
						$sender->sendMessage("You do not have any invites");
					}
				break;
				case "deny":
					if(isset($this->request[$name])){
						$sender->sendMessage("Successfully denied request to join \"".$this->request[$name]."\"");
						unset($this->request[$name]);
						if(isset($this->task[$name])) $this->task[$name]->cancel();
					}else{
						$sender->sendMessage("You do not have any invites");
					}
				break;
				default:
					$sender->sendMessage("Create group: /tc create <group name>\nInvite players: /tc inv <player>\nKick member: /tc kick <player>\nName group: /tc name <new name>\nLeave group: /tc leave\nList members: /tc list");
				break;
			}
		return true;
	}
	
	public function onChat(PlayerCommandPreprocessEvent $event){
		$msg = $event->getMessage();
		$p = $event->getPlayer();
		if(in_array(substr($msg,0,1),['~'])){
			$team = $this->inTeam($p->getName());
			if($team){
				$this->sendToTeam($team,$p->getName(),substr($msg,1));
				$event->setCancelled();
			}
		}
	}
	
	public function removeRequest($name){
		$p = $this->getServer()->getPlayer($name);
		if($p) $p->sendMessage("Invitation timeout");
		unset($this->request[$name],$this->task[$name]);
	}
	
	public function inTeam($name){
		foreach($this->teams->getAll() as $team => $members){
			foreach($members as $member)
			if($member === $name) return $team;
		}
		return false;
	}
	
	public function sendToTeam($team,$sender,$msg){
		if($sender === null){
			$msg = TextFormat::GRAY."[TeamChat] $msg";
		}else{
			$msg = "[$team] $sender: $msg";
		}
		$this->getLogger()->info($msg);
		foreach($this->teams->get($team) as $p){
			$p = $this->getServer()->getPlayerExact($p);
			if($p) $p->sendMessage($msg);
		}
	}
	
	public function removeFromTeam($name,$team){
		$name = strtolower($name);
		$config = $this->teams->getAll();
		$key = array_search($name,$config[$team]);
		if($key !== false){
			unset($config[$team][$key]);
			$this->teams->setAll($config);
			$this->teams->save();
		}
	}
	
	public function addToTeam($name,$team){
		$name = strtolower($name);
		$config = $this->teams->getAll();
		$config[$team][] = $name;
		$this->teams->setAll($config);
		$this->teams->save();
	}
	
	public function onEntityDamage(EntityDamageEvent $event){
		$p = $event->getEntity();
		if($p instanceof Player && $event instanceof EntityDamageByEntityEvent && $this->config["Friendly-Fire"] === false){
		    $name = $p->getName();
			$dmg = $event->getDamager();
			if($dmg instanceof Player){
				$dmgn = $dmg->getName();
				if($this->inTeam($name) === $this->inTeam($dmgn) && $this->inTeam($name) !== false){
					$event->setCancelled();
				}
			}
		}
	}
}