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

use pocketmine\Achievement;
use pocketmine\Player;
use pocketmine\event\Timings;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\ProtocolInfo as Info;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\SourceInterface;
use pocketmine\level\Level;
use pocketmine\utils\Utils;
use pocketmine\utils\UUID;
use pocketmine\utils\TextFormat;
use shoghicp\BigBrother\network\Packet;
use shoghicp\BigBrother\network\protocol\Login\EncryptionRequestPacket;
use shoghicp\BigBrother\network\protocol\Login\EncryptionResponsePacket;
use shoghicp\BigBrother\network\protocol\Login\LoginSuccessPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\AdvancementsPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\KeepAlivePacket;
use shoghicp\BigBrother\network\protocol\Play\Server\PlayerPositionAndLookPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\PlayerListPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\TitlePacket;
use shoghicp\BigBrother\network\protocol\Play\Server\SelectAdvancementTabPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\UnloadChunkPacket;
use shoghicp\BigBrother\network\ProtocolInterface;
use shoghicp\BigBrother\utils\Binary;
use shoghicp\BigBrother\utils\InventoryUtils;

//for initEntity()
use pocketmine\entity\Living;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use shoghicp\BigBrother\inventory\DesktopPlayerInventory;

class DesktopPlayer extends Player{

	/** @var int */
	private $bigBrother_status = 0; //0 = log in, 1 = playing
	/** @var string */
	protected $bigBrother_uuid;
	/** @var string */
	protected $bigBrother_formatedUUID;
	/** @var array */
	protected $bigBrother_properties = [];
	/** @var string */
	private $bigBrother_checkToken;
	/** @var string */
	private $bigBrother_secret;
	/** @var string */
	private $bigBrother_username;
	/** @var string */
	private $bigbrother_clientId;
	/** @var int */
	private $bigBrother_dimension = 0;
	/** @var string[] */
	private $bigBrother_entitylist = [];
	/** @var InventoryUtils */
	private $inventoryutils;
	/** @var array */
	protected $Settings = [];
	/** @var ProtocolInterface */
	protected $interface;
	/** @var BigBrother */
	protected $plugin;

	/**
	 * @param SourceInterface $interface
	 * @param string          $clientID
	 * @param string          $address
	 * @param int             $port
	 * @param BigBrother      $plugin
	 */
	public function __construct(SourceInterface $interface, string $clientID, string $address, int $port, BigBrother $plugin){
		$this->plugin = $plugin;
		$this->bigbrother_clientId = $clientID;
		parent::__construct($interface, $clientID, $address, $port);
		$this->inventoryutils = new InventoryUtils($this);
	}

	/**
	 * @return InventoryUtils
	 */
	public function getInventoryUtils() : InventoryUtils{
		return $this->inventoryutils;
	}

	/**
	 * This method is just copied `Human#initEntity()` method from PMMP project.
	 * And modified it to instantiate newly created `DesktopPlayerInventory` class.
	 * Original code is commented out, and changes are follow by the comment.
	 *
	 * TODO I think we need to suggest API changes to PMMP project because `Human#initEntity()` is updated frequently and this method is too big.
	 * TODO This code call `Living::initEntity()` directly, but this code is dangerous if the class hierarchy is changed.
	 * TODO Consider well about the hotbar link function.
	 *
	 * @override
	 */
	protected function initEntity(){

		$this->setDataFlag(self::DATA_PLAYER_FLAGS, self::DATA_PLAYER_FLAG_SLEEP, false, self::DATA_TYPE_BYTE);
		$this->setDataProperty(self::DATA_PLAYER_BED_POSITION, self::DATA_TYPE_POS, [0, 0, 0], false);

		//$this->inventory = new PlayerInventory($this);
		$this->inventory = new DesktopPlayerInventory($this);
		if($this instanceof Player){
			$this->addWindow($this->inventory, 0);
		}else{
			if(isset($this->namedtag->NameTag)){
				$this->setNameTag($this->namedtag["NameTag"]);
			}

			if(isset($this->namedtag->Skin) and $this->namedtag->Skin instanceof CompoundTag){
				$this->setSkin($this->namedtag->Skin["Data"], $this->namedtag->Skin["Name"]);
			}

			$this->uuid = UUID::fromData((string) $this->getId(), $this->getSkinData(), $this->getNameTag());
		}

		if(isset($this->namedtag->Inventory) and $this->namedtag->Inventory instanceof ListTag){
			foreach($this->namedtag->Inventory as $item){
				if($item["Slot"] >= 0 and $item["Slot"] < 9){ //Hotbar
					//$this->inventory->setHotbarSlotIndex($item["Slot"], isset($item["TrueSlot"]) ? $item["TrueSlot"] : -1);
				}elseif($item["Slot"] >= 100 and $item["Slot"] < 104){ //Armor
					//$this->inventory->setItem($this->inventory->getSize() + $item["Slot"] - 100, ItemItem::nbtDeserialize($item));
					$this->inventory->setItem($this->inventory->getSize() + $item["Slot"] - 100, Item::nbtDeserialize($item));
				}else{
					//$this->inventory->setItem($item["Slot"] - 9, ItemItem::nbtDeserialize($item));
					$this->inventory->setItem($item["Slot"] - 9, Item::nbtDeserialize($item));
				}
			}
		}

		if(isset($this->namedtag->SelectedInventorySlot) and $this->namedtag->SelectedInventorySlot instanceof IntTag){
			$this->inventory->setHeldItemIndex($this->namedtag->SelectedInventorySlot->getValue(), false);
		}else{
			$this->inventory->setHeldItemIndex(0, false);
		}

		//parent::initEntity();
		Living::initEntity();


		if(!isset($this->namedtag->foodLevel) or !($this->namedtag->foodLevel instanceof IntTag)){
			$this->namedtag->foodLevel = new IntTag("foodLevel", (int) $this->getFood());
		}else{
			$this->setFood((float) $this->namedtag["foodLevel"]);
		}

		if(!isset($this->namedtag->foodExhaustionLevel) or !($this->namedtag->foodExhaustionLevel instanceof FloatTag)){
			$this->namedtag->foodExhaustionLevel = new FloatTag("foodExhaustionLevel", $this->getExhaustion());
		}else{
			$this->setExhaustion((float) $this->namedtag["foodExhaustionLevel"]);
		}

		if(!isset($this->namedtag->foodSaturationLevel) or !($this->namedtag->foodSaturationLevel instanceof FloatTag)){
			$this->namedtag->foodSaturationLevel = new FloatTag("foodSaturationLevel", $this->getSaturation());
		}else{
			$this->setSaturation((float) $this->namedtag["foodSaturationLevel"]);
		}

		if(!isset($this->namedtag->foodTickTimer) or !($this->namedtag->foodTickTimer instanceof IntTag)){
			$this->namedtag->foodTickTimer = new IntTag("foodTickTimer", $this->foodTickTimer);
		}else{
			$this->foodTickTimer = $this->namedtag["foodTickTimer"];
		}

		if(!isset($this->namedtag->XpLevel) or !($this->namedtag->XpLevel instanceof IntTag)){
			$this->namedtag->XpLevel = new IntTag("XpLevel", $this->getXpLevel());
		}else{
			$this->setXpLevel((int) $this->namedtag["XpLevel"]);
		}

		if(!isset($this->namedtag->XpP) or !($this->namedtag->XpP instanceof FloatTag)){
			$this->namedtag->XpP = new FloatTag("XpP", $this->getXpProgress());
		}

		if(!isset($this->namedtag->XpTotal) or !($this->namedtag->XpTotal instanceof IntTag)){
			$this->namedtag->XpTotal = new IntTag("XpTotal", $this->totalXp);
		}else{
			$this->totalXp = $this->namedtag["XpTotal"];
		}

		if(!isset($this->namedtag->XpSeed) or !($this->namedtag->XpSeed instanceof IntTag)){
			$this->namedtag->XpSeed = new IntTag("XpSeed", $this->xpSeed ?? ($this->xpSeed = mt_rand(-0x80000000, 0x7fffffff)));
		}else{
			$this->xpSeed = $this->namedtag["XpSeed"];
		}
	}

	/**
	 * @param Item $item
	 */
	public function dropItemNaturally(Item $item) : void{
		$this->getLevel()->dropItem($this->add(0, 1.3, 0), $item, $this->getDirectionVector()->multiply(0.4), 40);
	}

	/**
	 * @return int dimension
	 */
	public function bigBrother_getDimension() : int{
		return $this->bigBrother_dimension;
	}

	/**
	 * @param int $level_dimension
	 * @return int dimension of pc version converted from $level_dimension
	 */
	public function bigBrother_getDimensionPEToPC(int $level_dimension) : int{
		switch($level_dimension){
			case 0://Overworld
				$dimension = 0;
			break;
			case 1://Nether
				$dimension = -1;
			break;
			case 2://The End
				$dimension = 1;
			break;
		}
		$this->bigBrother_dimension = $dimension;
		return $dimension;
	}

	/**
	 * @param int    $eid
	 * @param string $entitytype
	 */
	public function bigBrother_addEntityList(int $eid, string $entitytype) : void{
		if(!isset($this->bigBrother_entitylist[$eid])){
			$this->bigBrother_entitylist[$eid] = $entitytype;
		}
	}

	/**
	 * @param int $eid
	 * @return string
	 */
	public function bigBrother_getEntityList(int $eid) : string{
		if(isset($this->bigBrother_entitylist[$eid])){
			return $this->bigBrother_entitylist[$eid];
		}
		return "generic";
	}

	/**
	 * @param int $eid
	 */
	public function bigBrother_removeEntityList(int $eid) : void{
		if(isset($this->bigBrother_entitylist[$eid])){
			unset($this->bigBrother_entitylist[$eid]);
		}
	}

	/**
	 * @return int status
	 */
	public function bigBrother_getStatus() : int{
		return $this->bigBrother_status;
	}

	/**
	 * @return array properties
	 */
	public function bigBrother_getProperties() : array{
		return $this->bigBrother_properties;
	}

	/**
	 * @return string uuid
	 */
	public function bigBrother_getUniqueId() : string{
		return $this->bigBrother_uuid;
	}

	/**
	 * @return string formatted uuid
	 */
	public function bigBrother_getformatedUUID() : string{
		return $this->bigBrother_formatedUUID;
	}

	/**
	 * @return array settings
	 */
	public function getSettings() : array{
		return $this->Settings;
	}

	/**
	 * @param string $settingname
	 * @return
	 */
	public function getSetting(string $settingname){
		return $this->Settings[$settingname] ?? false;
	}

	/**
	 * @param array $settings
	 */
	public function setSetting(array $settings) : void{
		$this->Settings = array_merge($this->Settings, $settings);
	}

	/**
	 * @param string $settingname
	 */
	public function removeSetting(string $settingname) : void{
		if(isset($this->Settings[$settingname])){
			unset($this->Settings[$settingname]);
		}
	}

	/**
	 * @param string $settingname
	 */
	public function cleanSetting(string $settingname) : void{
		unset($this->Settings[$settingname]);
	}

	/**
	 * @param bool $first
	 */
	public function sendAdvancements(bool $first = false) : void{
		$pk = new AdvancementsPacket();
		$pk->advancements = [
			[
				"pocketmine:advancements/root",
				[
					false
				],
				[
					true,
					BigBrother::toJSON("Welcome to PocketMine-MP Server!"),
					BigBrother::toJSON("Join to PocketMine-MP Server with Minecraft"),
					Item::get(Item::GRASS),
					0,
					[
						1,
						"minecraft:textures/blocks/stone.png"
					],
					0,
					0
				],
				[],
				[]
			]
		];
		$pk->identifiers = [];
		$pk->progress = [];
		$this->putRawPacket($pk);

		if($first){
			$pk = new SelectAdvancementTabPacket();
			$pk->hasTab = true;
			$pk->tabId = "pocketmine:advancements/root";
			$this->putRawPacket($pk);
		}
	}

	/**
	 * TODO note that this method overriding parent private method!!
	 * @param int        $x
	 * @param int        $z
	 * @param Level|null $level
	 * @override
	 */
	private function unloadChunk(int $x, int $z, ?Level $level = null){
		parent::unloadChunk($x, $z, $level);

		$pk = new UnloadChunkPacket();
		$pk->chunkX = $x;
		$pk->chunkZ = $z;

		$this->putRawPacket($pk);
	}

	/**
	 * @override
	 */
	public function onVerifyCompleted(LoginPacket $packet, bool $isValid, bool $isAuthenticated) : void{
		parent::onVerifyCompleted($packet, true, true);

		BigBrother::addPlayerList($this);

		$pk = new ResourcePackClientResponsePacket();
		$pk->status = ResourcePackClientResponsePacket::STATUS_COMPLETED;
		$this->handleDataPacket($pk);

		$pk = new RequestChunkRadiusPacket();
		$pk->radius = 8;
		$this->handleDataPacket($pk);

		$pk = new KeepAlivePacket();
		$pk->id = mt_rand();
		$this->putRawPacket($pk);

		$pk = new PlayerListPacket();
		$pk->actionID = PlayerListPacket::TYPE_ADD;
		$pk->players[] = [
			UUID::fromString($this->bigBrother_formatedUUID)->toBinary(),
			$this->bigBrother_username,
			$this->bigBrother_properties,
			$this->getGamemode(),
			0,
			true,
			BigBrother::toJSON($this->bigBrother_username)
		];
		$this->putRawPacket($pk);

		$playerlist = [];
		$playerlist[UUID::fromString($this->bigBrother_formatedUUID)->toString()] = $this->bigBrother_username;
		$this->setSetting(["PlayerList" => $playerlist]);

		$pk = new TitlePacket(); //for Set SubTitle
		$pk->actionID = TitlePacket::TYPE_SET_TITLE;
		$pk->data = TextFormat::toJSON("");
		$this->putRawPacket($pk);

		$pk = new TitlePacket();
		$pk->actionID = TitlePacket::TYPE_SET_SUB_TITLE;
		$pk->data = TextFormat::toJSON(TextFormat::YELLOW . TextFormat::BOLD . "This is a beta version of BigBrother.");
		$this->putRawPacket($pk);

		$this->sendAdvancements(true);
	}

	public function bigBrother_respawn() : void{
		$pk = new PlayerPositionAndLookPacket();
		$pk->x = $this->getX();
		$pk->y = $this->getY();
		$pk->z = $this->getZ();
		$pk->yaw = 0;
		$pk->pitch = 0;
		$pk->flags = 0;
		$this->putRawPacket($pk);

		foreach($this->usedChunks as $index => $d){//reset chunks
			Level::getXZ($index, $x, $z);

			foreach($this->level->getChunkEntities($x, $z) as $entity){
				if($entity !== $this){
					$entity->despawnFrom($this);
				}
			}

			unset($this->usedChunks[$index]);
			$this->level->unregisterChunkLoader($this, $x, $z);
			unset($this->loadQueue[$index]);
		}

		$this->usedChunks = [];
	}

	/**
	 * @param string     $uuid
	 * @param array|null $onlineModeData
	 */
	public function bigBrother_authenticate(string $uuid, ?array $onlineModeData = null) : void{
		if($this->bigBrother_status === 0){
			$this->bigBrother_uuid = $uuid;
			$this->bigBrother_formatedUUID = Binary::UUIDtoString($this->bigBrother_uuid);

			$this->interface->setCompression($this);

			$pk = new LoginSuccessPacket();
			$pk->uuid = $this->bigBrother_formatedUUID;
			$pk->name = $this->bigBrother_username;
			$this->putRawPacket($pk);

			$this->bigBrother_status = 1;

			if($onlineModeData !== null){
				$this->bigBrother_properties = $onlineModeData;
			}

			$skin = false;
			$skindata = null;
			foreach($this->bigBrother_properties as $property){
				if($property["name"] === "textures"){
					$skindata = json_decode(base64_decode($property["value"]), true);
					if(isset($skindata["textures"]["SKIN"]["url"])){
						$skin = $this->getSkinImage($skindata["textures"]["SKIN"]["url"]);
					}
				}
			}

			$pk = new LoginPacket();
			$pk->username = $this->bigBrother_username;
			$pk->protocol = Info::CURRENT_PROTOCOL;
			$pk->clientUUID = $this->bigBrother_formatedUUID;
			$pk->clientId = crc32($this->bigbrother_clientId);
			$pk->serverAddress = "127.0.0.1:25565";
			if($skin === null or $skin === false){
				if($this->plugin->getConfig()->get("skin-slim")){
					$pk->skinId = "Standard_Custom";
				}else{
					$pk->skinId = "Standard_CustomSlim";
				}
				$pk->skin = file_get_contents($this->plugin->getDataFolder().$this->plugin->getConfig()->get("skin-yml"));
			}else{
				if($skindata !== null && !isset($skindata["textures"]["SKIN"]["metadata"]["model"])){
					$pk->skinId = "Standard_Custom";
				}else{
					$pk->skinId = "Standard_CustomSlim";
				}
				$pk->skin = $skin;
			}
			$pk->chainData = ["chain" => []];
			$pk->clientDataJwt = "eyJ4NXUiOiJNSFl3RUFZSEtvWkl6ajBDQVFZRks0RUVBQ0lEWWdBRThFTGtpeHlMY3dsWnJ5VVFjdTFUdlBPbUkyQjd2WDgzbmRuV1JVYVhtNzR3RmZhNWZcL2x3UU5UZnJMVkhhMlBtZW5wR0k2SmhJTVVKYVdacmptTWo5ME5vS05GU05CdUtkbThyWWlYc2ZhejNLMzZ4XC8xVTI2SHBHMFp4S1wvVjFWIn0.W10.QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFB";
			$this->handleDataPacket($pk);
		}
	}

	/**
	 * @param BigBrother $plugin
	 * @param EncryptionResponsePacket $packet
	 */
	public function bigBrother_processAuthentication(BigBrother $plugin, EncryptionResponsePacket $packet) : void{
		$this->bigBrother_secret = $plugin->decryptBinary($packet->sharedSecret);
		$token = $plugin->decryptBinary($packet->verifyToken);
		$this->interface->enableEncryption($this, $this->bigBrother_secret);
		if($token !== $this->bigBrother_checkToken){
			$this->close("", "Invalid check token");
		}else{
			$this->getAuthenticateOnline($this->bigBrother_username, Binary::sha1("".$this->bigBrother_secret.$plugin->getASN1PublicKey()));
		}
	}

	/**
	 * @param BigBrother $plugin
	 * @param string $username
	 * @param bool $onlineMode
	 */
	public function bigBrother_handleAuthentication(BigBrother $plugin, string $username, bool $onlineMode = false) : void{
		if($this->bigBrother_status === 0){
			$this->bigBrother_username = $username;
			if($onlineMode){
				$pk = new EncryptionRequestPacket();
				$pk->serverID = "";
				$pk->publicKey = $plugin->getASN1PublicKey();
				$pk->verifyToken = $this->bigBrother_checkToken = str_repeat("\x00", 4);
				$this->putRawPacket($pk);
			}else{
				$info = $this->getProfile($username);
				if(is_array($info)){
					$this->bigBrother_authenticate($info["id"], $info["properties"]);
				}
			}
		}
	}

	/**
	 * @param string $username
	 * @return array|bool|null
	 */
	public function getProfile(string $username){
		$profile = json_decode(Utils::getURL("https://api.mojang.com/users/profiles/minecraft/".$username), true);
		if(!is_array($profile)){
			return false;
		}

		$uuid = $profile["id"];
		$info = json_decode(Utils::getURL("https://sessionserver.mojang.com/session/minecraft/profile/".$uuid."", 3), true);
		if(!isset($info["id"])){
			return false;
		}
		return $info;
	}

	/**
	 * @param string $username
	 * @param string $hash
	 */
	public function getAuthenticateOnline(string $username, string $hash) : void{
		$result = json_decode(Utils::getURL("https://sessionserver.mojang.com/session/minecraft/hasJoined?username=".$username."&serverId=".$hash, 5), true);
		if(is_array($result) and isset($result["id"])){
			$this->bigBrother_authenticate($result["id"], $result["properties"]);
		}else{
			$this->close("", "User not premium");
		}
	}

	/**
	 * @param string $url
	 * @return string|bool|null sking image
	 */
	public function getSkinImage(string $url){
		if(extension_loaded("gd")){
			$image = imagecreatefrompng($url);

			if($image !== false){
				$width = imagesx($image);
				$height = imagesy($image);
				$colors = [];
				for($y = 0; $y < $height; $y++){
					$y_array = [];
					for($x = 0; $x < $width; $x++){
						$rgb = imagecolorat($image, $x, $y);
						$r = ($rgb >> 16) & 0xFF;
						$g = ($rgb >> 8) & 0xFF;
						$b = $rgb & 0xFF;
						$alpha = imagecolorsforindex($image, $rgb)["alpha"];
						$x_array = [$r, $g, $b, $alpha];
						$y_array[] = $x_array;
					}
					$colors[] = $y_array;
				}
				$skin = null;
				foreach($colors as $width){
					foreach($width as $height){
						$alpha = 0;
						if($height[0] === 255 and $height[1] === 255 and $height[2] === 255){
							$height[0] = 0;
							$height[1] = 0;
							$height[2] = 0;
							if($height[3] === 127){
								$alpha = 255;
							}else{
								$alpha = 0;
							}
						}else{
							if($height[3] === 127){
								$alpha = 0;
							}else{
								$alpha = 255;
							}
						}
						$skin = $skin.chr($height[0]).chr($height[1]).chr($height[2]).chr($alpha);
					}
				}
				imagedestroy($image);
				return $skin;
			}
		}
		return false;
	}

	/**
	 * @param DataPacket $packet
	 * @override
	 */
	public function handleDataPacket(DataPacket $packet){
		if($this->isConnected() === false){
			return;
		}

		$timings = Timings::getReceiveDataPacketTimings($packet);
		$timings->startTiming();

		$this->getServer()->getPluginManager()->callEvent($ev = new DataPacketReceiveEvent($this, $packet));
		if(!$ev->isCancelled() and !$packet->handle($this->sessionAdapter)){
			$this->getServer()->getLogger()->debug("Unhandled " . $packet->getName() . " received from " . $this->getName() . ": 0x" . bin2hex($packet->buffer));
		}

		$timings->stopTiming();
	}

	/**
	 * @param Packet $packet
	 */
	public function putRawPacket(Packet $packet) : void{
		$this->interface->putRawPacket($this, $packet);
	}
}
