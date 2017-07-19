<?php
/**
 *  ______  __         ______               __    __
 * |   __ \|__|.-----.|   __ \.----..-----.|  |_ |  |--..-----..----.
 * |   __ <|  ||  _  ||   __ <|   _||  _  ||   _||     ||  -__||   _|
 * |______/|__||___  ||______/|__|  |_____||____||__|__||_____||__|
 *             |_____|
 *
 * BigBrother plugin for PocketMine-MP
 * Copyright (C) 2014-2015 shoghicp <https://github.com/shoghicp/BigBrother>
 * Copyright (C) 2016- BigBrotherTeam
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author BigBrotherTeam
 * @link   https://github.com/BigBrotherTeam/BigBrother
 *
 */

declare(strict_types=1);

namespace shoghicp\BigBrother;

use pocketmine\plugin\PluginBase;
use pocketmine\network\mcpe\protocol\ProtocolInfo as Info;
use pocketmine\block\Block;
use pocketmine\block\Chest;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\utils\TextFormat;

use phpseclib\Crypt\RSA;
use shoghicp\BigBrother\network\ServerManager;
use shoghicp\BigBrother\network\ProtocolInterface;
use shoghicp\BigBrother\network\Translator;
use shoghicp\BigBrother\network\protocol\Play\Server\RespawnPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\OpenSignEditorPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\PlayerPositionAndLookPacket;
use shoghicp\BigBrother\inventory\CraftingInventory;
use shoghicp\BigBrother\inventory\BrewingStandInventory;
use shoghicp\BigBrother\utils\ConvertUtils;
use shoghicp\BigBrother\utils\AES;

if(\Phar::running(true) !== ""){
	define("shoghicp\BigBrother\PATH", \Phar::running(true) . "/");
}else{
	define("shoghicp\BigBrother\PATH", dirname(__FILE__, 4) . DIRECTORY_SEPARATOR);
}

class BigBrother extends PluginBase implements Listener{

	/** @var \Composer\Autoload\ClassLoader */
	private $loader;

	/** @var ProtocolInterface */
	private $interface;

	/** @var RSA */
	protected $rsa;

	/** @var string */
	protected $privateKey;

	/** @var string */
	protected $publicKey;

	/** @var bool */
	protected $onlineMode;

	/** @var Translator */
	protected $translator;

	/** @var DesktopPlayer[] */
	protected static $playerList = [];

	/**
	 * @override
	 */
	public function onEnable(){
		ConvertUtils::init();

		$this->saveDefaultConfig();
		$this->saveResource("server-icon.png", false);
		$this->saveResource("steve.yml", false);
		$this->saveResource("alex.yml", false);
		$this->saveResource("openssl.cnf", false);
		$this->reloadConfig();

		$this->onlineMode = (bool) $this->getConfig()->get("online-mode");

		if(is_file(\shoghicp\BigBrother\PATH . "/vendor/autoload.php")){
			$this->getLogger()->info("registering Composer autoloader...");
			$this->loader = require(\shoghicp\BigBrother\PATH . "/vendor/autoload.php");
		}else{
			$this->getLogger()->critical("Composer autoloader not found");
			$this->getLogger()->critical("Please initialize composer dependencies before running");
			$this->getPluginLoader()->disablePlugin($this);
			return;
		}

		$aes = new AES();
		switch($aes->getEngine()){
			case AES::ENGINE_OPENSSL:
				$this->getLogger()->info("Use openssl as AES encryption engine.");
			break;
			case AES::ENGINE_MCRYPT:
				$this->getLogger()->warning("Use obsolete mcrypt for AES encryption. Try to install openssl extension instead!!");
			break;
			case AES::ENGINE_INTERNAL:
				$this->getLogger()->warning("Use phpseclib internal engine for AES encryption, this may impact on performance. To improve them, try to install openssl extension.");
			break;
		}


		$this->rsa = new RSA();
		switch(constant("CRYPT_RSA_MODE")){
			case RSA::MODE_OPENSSL:
				$this->rsa->configFile = $this->getDataFolder() . "openssl.cnf";
				$this->getLogger()->info("Use openssl as RSA encryption engine.");
			break;
			case RSA::MODE_INTERNAL:
				$this->getLogger()->info("Use phpseclib internal engine for RSA encryption.");
			break;
		}

		if(!$this->getConfig()->exists("motd")){
			$this->getLogger()->warning("No motd has been set. The server description will be empty.");
			return;
		}

		if(Info::CURRENT_PROTOCOL === 137){
			$this->translator = new Translator();

			$this->getServer()->getPluginManager()->registerEvents($this, $this);

			if($this->onlineMode){
				$this->getLogger()->info("Server is being started in the background");
				$this->getLogger()->info("Generating keypair");
				$this->rsa->setPrivateKeyFormat(RSA::PRIVATE_FORMAT_PKCS1);
				$this->rsa->setPublicKeyFormat(RSA::PUBLIC_FORMAT_PKCS8);
				$this->rsa->setEncryptionMode(RSA::ENCRYPTION_PKCS1);
				$keys = $this->rsa->createKey(1024);
				$this->privateKey = $keys["privatekey"];
				$this->publicKey = $keys["publickey"];
				$this->rsa->loadKey($this->privateKey);
			}

			$this->getLogger()->info("Starting Minecraft: PC server on ".($this->getIp() === "0.0.0.0" ? "*" : $this->getIp()).":".$this->getPort()." version ".ServerManager::VERSION);

			$disable = true;
			foreach($this->getServer()->getNetwork()->getInterfaces() as $interface){
				if($interface instanceof ProtocolInterface){
					$disable = false;
				}
			}
			if($disable){
				$this->interface = new ProtocolInterface($this, $this->getServer(), $this->translator, $this->getConfig()->get("network-compression-threshold"));
				$this->getServer()->getNetwork()->registerInterface($this->interface);
			}
		}else{
			$this->getLogger()->critical("Couldn't find a protocol translator for #".Info::CURRENT_PROTOCOL .", disabling plugin");
			$this->getPluginLoader()->disablePlugin($this);
		}
	}

	/**
	 * @override
	 */
	public function onDisable(){
		if($this->loader !== null){
			$this->getLogger()->info("registering Composer autoloader...");
			$this->loader->unregister();
		}
	}

	/**
	 * @return string ip address
	 */
	public function getIp() : string{
		return (string)$this->getConfig()->get("interface");
	}

	/**
	 * @return int port
	 */
	public function getPort() : int{
		return (int)$this->getConfig()->get("port");
	}

	/**
	 * @return string motd
	 */
	public function getMotd() : string{
		return (string)$this->getConfig()->get("motd");
	}

	/**
	 * @return bool
	 */
	public function isOnlineMode(){
		return $this->onlineMode;
	}

	/**
	 * @return string ASN1 Public Key
	 */
	public function getASN1PublicKey() : string{
		$key = explode("\n", $this->publicKey);
		array_pop($key);
		array_shift($key);
		return base64_decode(implode(array_map("trim", $key)));
	}

	/**
	 * @param string $cipher cipher text
	 * @return string plain text
	 */
	public function decryptBinary(string $cipher) : string{
		return $this->rsa->decrypt($cipher);
	}

	/**
	 * @param PlayerRespawnEvent $event
	 *
	 * @priority NORMAL
	 */
	public function onRespawn(PlayerRespawnEvent $event) : void{
		$player = $event->getPlayer();
		if($player instanceof DesktopPlayer and $player->getHealth() === 0){
			$pk = new RespawnPacket();
			$pk->dimension = $player->bigBrother_getDimension();
			$pk->difficulty = $player->getServer()->getDifficulty();
			$pk->gamemode = $player->getGamemode();
			$pk->levelType = "default";
			$player->putRawPacket($pk);

			$player->bigBrother_respawn();
		}
	}

	/**
	 * @param BlockPlaceEvent $event
	 *
	 * @priority NORMAL
	 */
	public function onPlace(BlockPlaceEvent $event) : void{
		$player = $event->getPlayer();
		$block = $event->getBlock();
		if($player instanceof DesktopPlayer){
			if($block->getId() === Block::SIGN_POST or $block->getId() === Block::WALL_SIGN){
				$pk = new OpenSignEditorPacket();
				$pk->x = $block->x;
				$pk->y = $block->y;
				$pk->z = $block->z;
				$player->putRawPacket($pk);
			}
		}

		if($block instanceof Chest){
			$num_side_chest = 0;
			for($i = 2; $i <= 5; ++$i){
				if(($side_chest = $block->getSide($i))->getId() === $block->getId()){
					++$num_side_chest;
					for($j = 2; $j <= 5; ++$j){
						if($side_chest->getSide($j)->getId() === $side_chest->getId()){//Cancel block placement event if side chest is already large-chest
							$event->setCancelled();
						}
					}
				}
			}
			if($num_side_chest > 1){//Cancel if there are more than one chest that can be large-chest
				$event->setCancelled();
			}
		}
	}

	/**
	 * @param PlayerInteractEvent $event
	 *
	 * @priority NORMAL
	 */
	public function onInteract(PlayerInteractEvent $event){
		$player = $event->getPlayer();
		$block = $event->getBlock();

		if($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK and $player instanceof DesktopPlayer){
			switch($block->getId()){
				case Block::WORKBENCH:
					$player->addWindow(new CraftingInventory($player));
				break;

				case Block::BREWING_STAND_BLOCK:
					$player->addWindow(new BrewingStandInventory($player));
				break;
			}
		}
	}

	/**
	 * @param string|null $message
	 * @param string|null $source
	 * @param int         $type
	 * @param array|null  $parameters
	 * @return string
	 */
	public static function toJSON(?string $message, ?string $source = "", int $type = 1, ?array $parameters = []) : string{
		$message = $source.$message;
		$result = json_decode(TextFormat::toJSON($message), true);

		switch($type){
			case 2:
				unset($result["text"]);
				$message = TextFormat::clean($message);

				if(substr($message, 0, 1) === "["){//chat.type.admin
					$result["translate"] = "chat.type.admin";

					$result["with"][] = ["text" => substr($message, 1, strpos($message, ":") - 1)];
					$result["with"][] = ["translate" => preg_replace('/[^0-9a-zA-Z.]/', '', substr($message, strpos($message, "%")))];

					$with = &$result["with"][1];
				}else{
					$result["translate"] = str_replace("%", "", $message);

					$with = &$result;
				}

				foreach($parameters as $parameter){
					if(strpos($parameter, "%") !== false){
						$with["with"][] = ["translate" => str_replace("%", "", $parameter)];
					}else{
						$with["with"][] = ["text" => $parameter];
					}
				}
			break;
			case 3:
			case 4://Just to be sure
				if(isset($result["text"])){
					$result["text"] = $message;
				}
			break;
		}

		if(isset($result["extra"])){
			if(count($result["extra"]) === 0){
				unset($result["extra"]);
			}
		}

		$result = json_encode($result, JSON_UNESCAPED_SLASHES);
		return $result;
	}

	/**
	 * @param DesktopPlayer $player
	 */
	public static function addPlayerList(DesktopPlayer $player) : void{
		self::$playerList[$player->getName()] = $player;
	}

	/**
	 * @param DesktopPlayer $player
	 */
	public static function removePlayerList(DesktopPlayer $player) : void{
		if(isset(self::$playerList[$player->getName()])){
			unset(self::$playerList[$player->getName()]);
		}
	}

	/**
	 * @param string $username
	 * @return DesktopPlayer|null corresponding player when username equals $username else null
	 */
	public static function getPlayerList(string $username) : ?DesktopPlayer{
		return self::$playerList[$username] ?? null;
	}
}
