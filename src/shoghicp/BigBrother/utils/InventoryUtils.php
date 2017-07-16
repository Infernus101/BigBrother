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

namespace shoghicp\BigBrother\utils;

use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\InventoryContentPacket;
use pocketmine\network\mcpe\protocol\ContainerSetDataPacket;
use pocketmine\network\mcpe\protocol\TakeItemEntityPacket;
use pocketmine\network\mcpe\protocol\types\ContainerIds;
use pocketmine\network\mcpe\protocol\types\WindowTypes;
use pocketmine\inventory\SlotType;
use pocketmine\inventory\FurnaceInventory;
use pocketmine\inventory\CraftingInventory;

use pocketmine\entity\Item as ItemEntity;
use pocketmine\math\Vector3;
use pocketmine\tile\Tile;
use pocketmine\tile\EnderChest as TileEnderChest;
use pocketmine\item\Item;
use pocketmine\item\Armor;
use pocketmine\inventory\InventoryHolder;

use shoghicp\BigBrother\BigBrother;
use shoghicp\BigBrother\DesktopPlayer;
use shoghicp\BigBrother\network\OutboundPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\ConfirmTransactionPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\OpenWindowPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\SetSlotPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\WindowItemsPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\WindowPropertyPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\CollectItemPacket;
use shoghicp\BigBrother\network\protocol\Play\Server\CloseWindowPacket as ServerCloseWindowPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\ClickWindowPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\CloseWindowPacket as ClientCloseWindowPacket;
use shoghicp\BigBrother\network\protocol\Play\Client\CreativeInventoryActionPacket;

class InventoryUtils{

	/** @var DesktopPlayer */
	private $player;
	/** @var array */
	private $windowInfo = [];
	/** @var array */
	private $craftInfoData = [];
	/** @var Item */
	private $playerHeldItem = null;
	/** @var int */
	private $playerHeldItemSlot = -1;
	/** @var Item[] */
	private $playerCraftSlot = [];
	/** @var Item[] */
	private $playerArmorSlot = [];
	/** @var Item[] */
	private $playerInventorySlot = [];
	/** @var Item[] */
	private $playerHotbarSlot = [];

	/**
	 * @param DesktopPlayer $player
	 */
	public function __construct(DesktopPlayer $player){
		$this->player = $player;

		$this->playerCraftSlot = array_fill(0, 5, Item::get(Item::AIR));
		$this->playerArmorSlot = array_fill(0, 5, Item::get(Item::AIR));
		$this->playerInventorySlot = array_fill(0, 36, Item::get(Item::AIR));
		$this->playerHotbarSlot = array_fill(0, 9, Item::get(Item::AIR));
		$this->playerHeldItem = Item::get(Item::AIR);
	}

	/**
	 * @param Item[] $items
	 * @return Item[]
	 */
	public function getInventory(array $items) : array{
		foreach($this->playerInventorySlot as $item){
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * @param Item[] $items
	 * @return Item[]
	 */
	public function getHotbar(array $items) : array{
		foreach($this->playerHotbarSlot as $item){
			$items[] = $item;
		}

		return $items;
	}

	protected function translateSlotToReal(int $windowid, int $slot, &$inventory=null) : int{
		$realSlot = -1;

		switch($windowid){
			case ContainerIds::INVENTORY:
				$realSlot = $slot;
				$inventory = null;
			break;

			default:
				if(isset($this->windowInfo[$windowid])){
					$nslots = $this->windowInfo[$windowid]['slots'];
					$inventory = $this->windowInfo[$windowid]['inventory'];

					if($slot >= $nslots){
						$realSlot = $slot - $nslots;
					}else{
						$realSlot = $slot;
					}
				}else{
					echo "unknown windowid: $windowid\n";
				}
			break;
		}

		return $realSlot;
	}

	protected function getSlotType(int $windowid, int $slot) : int{
		$inventory = null;
		$nslots = 0;
		$type = -1;

		switch($windowid){
			case ContainerIds::INVENTORY:
				$realSlot = $slot;
			break;

			default:
				if(isset($this->windowInfo[$windowid])){
					$nslots = $this->windowInfo[$windowid]['slots'];
					$inventory = $this->windowInfo[$windowid]['inventory'];

					if($slot >= $nslots){
						$realSlot = $slot - $nslots;
					}
				}else{
					echo "unknown windowid: $windowid\n";
					$realSlot = -1;
				}
			break;
		}

		if($inventory === null){
			if($realSlot === 0){
				$type = SlotType::RESULT;
			}elseif($realSlot > 0 and $realSlot < 5){
				$type = SlotType::CRAFTING;
			}elseif($realSlot >= 5 and $realSlot < 9){
				$type = SlotType::ARMOR;
			}elseif($realSlot >= 9 and $realSlot < 36){
				$type = SlotType::HOTBAR;
			}elseif($realSlot >= 36 and $realSlot < 45){
				$type = SlotType::CONTAINER;
			}
		}elseif($slot >= $nslots){
			if($realSlot >= 0 and $realSlot < 27){
				$type = SlotType::HOTBAR;
			}elseif($realSlot >= 27 and $realSlot < 36){
				$type = SlotType::CONTAINER;
			}
		}else{
			if($inventory instanceof FurnaceInventory){
				switch($slot){
					case 1:
						$type = SlotType::FUEL;
					break;

					case 2:
						$type = SlotType::RESULT;
					break;
				}
			}elseif($inventory instanceof CraftingInventory){
				switch($slot){
					case 0:
						$type = SlotType::RESULT;
					break;

					default:
						$type = SlotType::CRAFTING;
					break;
				}
			}
		}

		return $type;
	}

	protected function canSetItem(int $windowid, int $slot, Item $item) : bool{
		$canput = false;

		switch($slotType = $this->getSlotType($windowid, $slot)){
			case SlotType::RESULT:
			break;

			case SlotType::CRAFTING:
			case SlotType::HOTBAR:
			case SlotType::CONTAINER:
				$canput = true;
			break;

			case SlotType::ARMOR:
				if($item instanceof Armor){
					switch($item->getId()){
						case Item::CHAIN_HELMET:
						case Item::IRON_HELMET:
						case Item::DIAMOND_HELMET:
						case Item::GOLDEN_HELMET:
							$canput = $slot === 5;
						break;

						case Item::CHAIN_CHESTPLATE:
						case Item::IRON_CHESTPLATE:
						case Item::DIAMOND_CHESTPLATE:
						case Item::GOLDEN_CHESTPLATE:
							$canput = $slot === 6;
						break;

						case Item::CHAIN_LEGGINGS:
						case Item::IRON_LEGGINGS:
						case Item::DIAMOND_LEGGINGS:
						case Item::GOLDEN_LEGGINGS:
							$canput = $slot === 7;
						break;

						case Item::CHAIN_BOOTS:
						case Item::IRON_BOOTS:
						case Item::DIAMOND_BOOTS:
						case Item::GOLDEN_BOOTS:
							$canput = $slot === 8;
						break;

						default:
							echo "unknown armor $item\n";
						break;
					}
				}
			break;

			case SlotType::FUEL:
				switch($item->getId()){
					case Item::COAL:
					case Item::COAL_BLOCK:
					case Item::TRUNK:
					case Item::WOODEN_PLANKS:
					case Item::SAPLING:
					case Item::WOODEN_AXE:
					case Item::WOODEN_PICKAXE:
					case Item::WOODEN_SWORD:
					case Item::WOODEN_SHOVEL:
					case Item::WOODEN_HOE:
					case Item::STICK:
					case Item::FENCE:
					case Item::FENCE_GATE:
					case Item::FENCE_GATE_SPRUCE:
					case Item::FENCE_GATE_BIRCH:
					case Item::FENCE_GATE_JUNGLE:
					case Item::FENCE_GATE_ACACIA:
					case Item::FENCE_GATE_DARK_OAK:
					case Item::WOODEN_STAIRS:
					case Item::SPRUCE_WOOD_STAIRS:
					case Item::BIRCH_WOOD_STAIRS:
					case Item::JUNGLE_WOOD_STAIRS:
					case Item::TRAPDOOR:
					case Item::WORKBENCH:
					case Item::BOOKSHELF:
					case Item::CHEST:
					case Item::BUCKET:
						$canput = true;
					break;
				}
			break;

			default:
				echo "unknown slot type $slotType\n";
			break;
		}

		return $canput;
	}

	protected function getItemBySlot(int $windowid, int $slot){
		$inventory = null;

		switch($windowid){
			case ContainerIds::INVENTORY:
				$realSlot = $slot;
			break;

			default:
				if(isset($this->windowInfo[$windowid])){
					$type = $this->windowInfo[$windowid]['type'];
					$nslots = $this->windowInfo[$windowid]['slots'];
					$inventory = $this->windowInfo[$windowid]['inventory'];

					if($slot < $nslots){
						return $inventory->getItem($slot);
					}else{
						//Bottom Inventory (Player Inventory)
						$realSlot = $slot - $nslots;
					}
				}else{
					echo "unknown windowid: $windowid\n";
					return null;
				}
			break;
		}

		if($inventory === null){
			if($realSlot >= 0 and $realSlot < 5){
				//TODO fix me
				return $this->playerCraftSlot[$realSlot];
			}elseif($realSlot >= 5 and $realSlot < 9){
				return $this->player->getInventory()->getArmorItem($realSlot - 5);
			}elseif($realSlot >= 9 and $realSlot < 36){
				return $this->player->getInventory()->getItem($realSlot);
			}elseif($realSlot >= 36 and $realSlot < 45){
				return $this->player->getInventory()->getHotbarSlotItem($realSlot - 36);
			}else{
				echo "getItemBySlot() : invalid realSlot index $realSlot\n";
			}
		}else{
			$inventorySize = $this->player->getInventory()->getSize();
			$hotbarSize = $this->player->getInventory()->getHotbarSize();

			if($realSlot >= 0 and $realSlot < $inventorySize - $hotbarSize){
				return $this->player->getInventory()->getItem($realSlot + $hotbarSize);
			}elseif($realSlot >= $inventorySize - $hotbarSize and $realSlot < $inventorySize){
				return $this->player->getInventory()->getHotbarSlotItem($realSlot - $inventorySize + $hotbarSize);
			}
		}

		return null;
	}

	protected function setItemBySlot(int $windowid, int $slot, Item $item){
		$inventory = null;

		switch($windowid){
			case ContainerIds::INVENTORY:
				$realSlot = $slot;
			break;

			default:
				if(isset($this->windowInfo[$windowid])){
					$type = $this->windowInfo[$windowid]['type'];
					$nslots = $this->windowInfo[$windowid]['slots'];
					$inventory = $this->windowInfo[$windowid]['inventory'];

					if($slot < $nslots){
						return $inventory->setItem($slot, $item);
					}else{
						//Bottom Inventory (Player Inventory)
						$realSlot = $slot - $nslots;
					}
				}else{
					echo "unknown windowid: $windowid\n";
					return false;
				}
			break;
		}

		if($inventory === null){
			if($realSlot >= 0 and $realSlot < 5){
				$this->playerCraftSlot[$realSlot] = $item;
			}elseif($realSlot >= 5 and $realSlot < 9){
				//TODO check if item is armor instance
				return $this->player->getInventory()->setArmorItem($realSlot - 5, $item);
			}elseif($realSlot >= 9 and $realSlot < 36){
				return $this->player->getInventory()->setItem($realSlot, $item);
			}elseif($realSlot >= 36 and $realSlot < 45){
				return $this->player->getInventory()->setHotbarSlotItem($realSlot - 36, $item);
			}else{
				echo "setItemBySlot() : invalid realSlot index $realSlot\n";
			}
		}else{
			$inventorySize = $this->player->getInventory()->getSize();
			$hotbarSize = $this->player->getInventory()->getHotbarSize();

			if($realSlot >= 0 and $realSlot < $inventorySize - $hotbarSize){
				return $this->player->getInventory()->setHotbarSlotItem($realSlot + $hotbarSize, $item);
			}elseif($realSlot >= $inventorySize - $hotbarSize and $realSlot < $inventorySize){
				return $this->player->getInventory()->setItem($realSlot - $inventorySize + $hotbarSize, $item);
			}
		}

		return false;
	}

	protected function pickItem(int $windowid, int $slot, bool $whole=true){
		$target = $this->getItemBySlot($windowid, $slot);
		$picked = Item::get(Item::AIR, 0, 0);

		if($target !== null and $target->getId() !== Item::AIR and $target->getCount() > 0){
			if($whole){
				$picked = $target;
				$target = Item::get(Item::AIR, 0, 0);
			}else{
				$picked = clone $target;
				$picked->setCount((int)ceil($target->getCount()/2));
				$target->setCount((int)floor($target->getCount()/2));
			}
		}

		//TODO check whether if this method success
		if($target !== null){
			$this->setItemBySlot($windowid, $slot, $target);
		}

		return $picked;
	}

	protected function putItem(int $windowid, int $slot, Item $item, bool $whole=true){
		$target = $this->getItemBySlot($windowid, $slot);
		$remain = Item::get(Item::AIR, 0, 0);
		$amount = $whole ? $item->getCount() : 1;

		if($target === null or $target->getId() === Item::AIR or $target->getCount() === 0){
			$target = $item;
		}elseif($target->equals($item)){
			if($target->getCount() + $amount > $item->getMaxStackSize()){
				$remain = clone $target;
				$remain->setCount($target->getCount() + $amount - $item->getMaxStackSize());
				$target->setCount($item->getMaxStackSize());
			}else{
				$target->setCount($target->getCount() + $amount);
				if($whole !== true){
					$remain = clone $target;
					$remain->setCount($remain->getCount() - $amount);
				}
			}
		}

		//TODO check whether if this method success
		if($target !== null){
			$this->setItemBySlot($windowid, $slot, $target);
		}

		return $remain;
	}

	/**
	 * @param ContainerOpenPacket $packet
	 * @return OutboundPacket|null
	 */
	public function onWindowOpen(ContainerOpenPacket $packet) : ?OutboundPacket{
		$type = "";
		switch($packet->type){
			case WindowTypes::CONTAINER:
				$type = "minecraft:chest";
				$title = "chest";
			break;
			case WindowTypes::WORKBENCH:
				$type = "minecraft:crafting_table";
				$title = "crafting";
			break;
			case WindowTypes::FURNACE:
				$type = "minecraft:furnace";
				$title = "furnace";
			break;
			case WindowTypes::ENCHANTMENT:
				$type = "minecraft:enchanting_table";
				$title = "enchant";
			break;
			case WindowTypes::ANVIL:
				$type = "minecraft:anvil";
				$title = "repair";
			break;
			default://TODO: http://wiki.vg/Inventory#Windows
				echo "[InventoryUtils] ContainerOpenPacket: ".$packet->type."\n";

				$pk = new ContainerClosePacket();
				$pk->windowId = $packet->windowId;
				$this->player->handleDataPacket($pk);

				return null;
			break;
		}

		$slots = 0;
		$inventory = null;
		if(($tile = $this->player->getLevel()->getTile(new Vector3((int)$packet->x, (int)$packet->y, (int)$packet->z))) instanceof Tile){
			if($tile instanceof TileEnderChest){
				$inventory = $this->player->getEnderChestInventory();
				$slots = $inventory->getSize();
				$title = "enderchest";
			}elseif($tile instanceof InventoryHolder){
				$inventory = $tile->getInventory();
				$slots = $inventory->getSize();
				if($title === "chest" and $slots === 54){
					$title = "chestDouble";
				}
			}
		}

		$pk = new OpenWindowPacket();
		$pk->windowID = $packet->windowId;
		$pk->inventoryType = $type;
		$pk->windowTitle = json_encode(["translate" => "container.".$title]);
		$pk->slots = $slots;

		$this->windowInfo[$packet->windowId] = ["type" => $packet->type, "slots" => $slots, "inventory" => $inventory];

		return $pk;
	}

	/**
	 * @param bool $isserver
	 * @param ContainerClosePacket $packet
	 * @return OutboundPacket|null
	 */
	public function onWindowCloseFromPCtoPE(ClientCloseWindowPacket $packet) : ?ContainerClosePacket{
		foreach($this->playerCraftSlot as $num => $item){
			$this->player->dropItemNaturally($item);
			$this->playerCraftSlot[$num] = Item::get(Item::AIR);
		}

		$this->player->dropItemNaturally($this->playerHeldItem);
		$this->playerHeldItem = Item::get(Item::AIR);

		if($packet->windowID !== ContainerIds::INVENTORY){//Player Inventory
			$pk = new ContainerClosePacket();
			$pk->windowId = $packet->windowID;

			return $pk;
		}

		return null;
	}

	/**
	 * @param bool $isserver
	 * @param ContainerClosePacket $packet
	 * @return OutboundPacket|null
	 */
	public function onWindowCloseFromPEtoPC(ContainerClosePacket $packet) : ServerCloseWindowPacket{
		foreach($this->playerCraftSlot as $num => $item){
			$this->player->dropItemNaturally($item);
			$this->playerCraftSlot[$num] = Item::get(Item::AIR);
		}

		$this->player->dropItemNaturally($this->playerHeldItem);
		$this->playerHeldItem = Item::get(Item::AIR);

		$pk = new ServerCloseWindowPacket();
		$pk->windowID = $packet->windowId;

		unset($this->windowInfo[$packet->windowId]);

		return $pk;
	}

	/**
	 * @param InventorySlotPacket $packet
	 * @return OutboundPacket|null
	 */
	public function onWindowSetSlot(InventorySlotPacket $packet) : ?OutboundPacket{
		$pk = new SetSlotPacket();
		$pk->windowID = $packet->windowId;

		switch($packet->windowId){
			case ContainerIds::INVENTORY:
				$pk->item = $packet->item;

				if($packet->inventorySlot >= 0 and $packet->inventorySlot < $this->player->getInventory()->getHotbarSize()){
					$pk->slot = $packet->inventorySlot + 36;
				}elseif($packet->inventorySlot >= $this->player->getInventory()->getHotbarSize() and $packet->inventorySlot < $this->player->getInventory()->getSize()){
					$pk->slot = $packet->inventorySlot;
				}elseif($packet->inventorySlot >= $this->player->getInventory()->getSize() and $packet->inventorySlot < $this->player->getInventory()->getSize() + 4){
					// ignore this packet (this packet is not needed because this is duplicated packet)
					$pk = null;
				}

				return $pk;
			break;
			case ContainerIds::ARMOR:
				$pk->windowID = ContainerIds::INVENTORY;
				$pk->item = $packet->item;
				$pk->slot = $packet->inventorySlot + 5;

				return $pk;
			break;
			case ContainerIds::CREATIVE:
			case ContainerIds::HOTBAR:
			break;
			default:
				if(isset($this->windowInfo[$packet->windowId])){//TODO
					$pk->item = $packet->item;
					$pk->slot = $packet->inventorySlot;

					var_dump($packet);

					return $pk;
				}
				echo "[InventoryUtils] InventorySlotPacket: 0x".bin2hex(chr($packet->windowId))."\n";
			break;
		}
		return null;
	}

	/**
	 * @param ContainerSetDataPacket $packet
	 * @return OutboundPacket[]
	 */
	public function onWindowSetData(ContainerSetDataPacket $packet) : array{
		if(!isset($this->windowInfo[$packet->windowId])){
			echo "[InventoryUtils] ContainerSetDataPacket: 0x".bin2hex(chr($packet->windowId))."\n";
		}

		$packets = [];
		switch($this->windowInfo[$packet->windowId]["type"]){
			case WindowTypes::FURNACE:
				switch($packet->property){
					case 0://Smelting
						$pk = new WindowPropertyPacket();
						$pk->windowID = $packet->windowId;
						$pk->property = 3;
						$pk->value = 200;//changed?
						$packets[] = $pk;

						$pk = new WindowPropertyPacket();
						$pk->windowID = $packet->windowId;
						$pk->property = 2;
						$pk->value = $packet->value;
						$packets[] = $pk;
					break;
					case 1://Fire icon
						$pk = new WindowPropertyPacket();
						$pk->windowID = $packet->windowId;
						$pk->property = 1;
						$pk->value = 200;//changed?
						$packets[] = $pk;

						$pk = new WindowPropertyPacket();
						$pk->windowID = $packet->windowId;
						$pk->property = 0;
						$pk->value = $packet->value;
						$packets[] = $pk;
					break;
					default:
						echo "[InventoryUtils] ContainerSetDataPacket: 0x".bin2hex(chr($packet->windowId))."\n";
					break;
				}
			break;
			default:
				echo "[InventoryUtils] ContainerSetDataPacket: 0x".bin2hex(chr($packet->windowId))."\n";
			break;
		}

		return $packets;
	}

	/**
	 * @param InventoryContentPacket $packet
	 * @return OutboundPacket[]
	 */
	public function onWindowSetContent(InventoryContentPacket $packet) : array{
		$packets = [];

		switch($packet->windowId){
			case ContainerIds::INVENTORY:
				$pk = new WindowItemsPacket();
				$pk->windowID = $packet->windowId;

				for($i = 0; $i < 5; ++$i){
					$pk->items[] = Item::get(Item::AIR, 0, 0);//Craft
				}

				for($i = 0; $i < 4; ++$i){
					$pk->items[] = Item::get(Item::AIR, 0, 0);//Armor
				}

				/*$hotbar = [];
				foreach($packet->hotbar as $num => $hotbarslot){
					if($hotbarslot === -1){
						$packet->hotbar[$num] = $hotbarslot = $num + $this->player->getInventory()->getHotbarSize();
					}

					$hotbarslot -= $this->player->getInventory()->getHotbarSize();
					$hotbar[] = $packet->slots[$hotbarslot];
				}
				//TODO consider $packet->slots. is it ok to ignore it??

				$inventory = [];
				for($i = 0; $i < $this->player->getInventory()->getSize() - $this->player->getInventory()->getHotbarSize(); ++$i){
					$item = $this->player->getInventory()->getItem($i + 9);
					$pk->items[] = $item;
					$inventory[] = $item;
				}

				foreach($hotbar as $item){
					$pk->items[] = $item;//hotbar
				}*/

				// Inventory
				$inventory = [];
				for($i = 0, $len = $this->player->getInventory()->getSize(); $i < $len; ++$i){
					$pk->items[] = $packet->items[$i];
				}

				// Hotbar
				$hotbar = [];
				for($i = 0, $len = $this->player->getInventory()->getHotbarSize(); $i < $len; ++$i){
					$item = $this->player->getInventory()->getHotbarSlotItem($i);
					$pk->items []= $item;
					$hotbar []= $item;
				}

				$pk->items[] = Item::get(Item::AIR, 0, 0);//offhand

				$this->playerInventorySlot = $inventory;
				$this->playerHotbarSlot = $hotbar;

				$packets[] = $pk;
			break;
			case ContainerIds::ARMOR:
				foreach($packet->items as $slot => $item){
					$pk = new SetSlotPacket();
					$pk->windowID = ContainerIds::INVENTORY;
					$pk->item = $item;
					$pk->slot = $slot + 5;

					$packets[] = $pk;
				}

				$this->playerArmorSlot = $packet->items;
			break;
			case ContainerIds::CREATIVE:
			case ContainerIds::HOTBAR:
			break;
			default:
				if(isset($this->windowInfo[$packet->windowId])){
					$pk = new WindowItemsPacket();
					$pk->windowID = $packet->windowId;

					$pk->items = $packet->items;

					//var_dump($packet->slots);

					// Inventory
					for($i = 0; $i < $this->player->getInventory()->getSize() - $this->player->getInventory()->getHotbarSize(); ++$i){
						$pk->items[] = $this->player->getInventory()->getItem($i + 9);
					}

					// Hotbar
					for($i = 0; $i < $this->player->getInventory()->getHotbarSize(); ++$i){
						$pk->items[] = $this->player->getInventory()->getHotbarSlotItem($i);
					}

					$packets[] = $pk;
				}

				echo "[InventoryUtils] InventoryContentPacket: 0x".bin2hex(chr($packet->windowId))."\n";
			break;
		}

		return $packets;
	}

	/**
	 * @param ClickWindowPacket $packet
	 * @return DataPacket[]
	 */
	public function onWindowClick(ClickWindowPacket $packet) : array{
		$item = $packet->clickedItem;

		$accepted = false;

		//$item = ;
		//$heldItem = ;
		echo "click: $packet->mode, $packet->button; slot: $packet->slot\n";

		switch($packet->mode){
			case 0:
				switch($packet->button){
					case 0://Left mouse click
						$accepted = true;

						list($this->playerHeldItem, $item) = [$item, $this->playerHeldItem];//reverse
					break;
					case 1://Right mouse click
						$accepted = true;

						if($this->playerHeldItem === null || $this->playerHeldItem->getId() === Item::AIR || $this->playerHeldItem->getCount() === 0){
							$this->playerHeldItem = $this->pickItem($packet->windowID, $packet->slot, $packet->button === 0);
							echo "";
							echo "pick $this->playerHeldItem from slot $packet->slot\n";
						}else{
							if($packet->slot === 64537){
								$this->player->dropItemNaturally($this->playerHeldItem);
								$this->playerHeldItem = Item::get(Item::AIR, 0, 0);
							}elseif($this->playerHeldItem->equals($this->getItemBySlot($packet->windowID, $packet->slot))){
								if($this->canSetItem($packet->windowID, $packet->slot, $this->playerHeldItem)){
									echo "";
									echo "put $this->playerHeldItem into slot $packet->slot\n";
									$this->playerHeldItem = $this->putItem($packet->windowID, $packet->slot, $this->playerHeldItem, $packet->button === 0);
									echo "now, slot $packet->slot is ".$this->getItemBySlot($packet->windowID, $packet->slot)."\n";
									echo "$this->playerHeldItem is remaining in hand\n";
								}
							}else{
								if($this->canSetItem($packet->windowID, $packet->slot, $this->playerHeldItem)){
									//TODO check packet->item
									$item = $this->getItemBySlot($packet->windowID, $packet->slot);

									echo "swap $this->playerHeldItem with $item\n";
									$this->setItemBySlot($packet->windowID, $packet->slot, $this->playerHeldItem);
									echo "now, slot $packet->slot is $this->playerHeldItem\n";
									$this->playerHeldItem = $item;
								}
							}
						}
					break;
					default:
						echo "[InventoryUtils] UnknownButtonType: ".$packet->mode." : ".$packet->button."\n";
					break;
				}
			break;
			case 1:
				switch($packet->button){
					case 0://Shift + left mouse click
					case 1://Shift + right mouse click

					break;
					default:
						echo "[InventoryUtils] UnknownButtonType: ".$packet->mode." : ".$packet->button."\n";
					break;
				}
			break;
			case 2:
				switch($packet->button){
					case 0://Number key 1

					break;
					case 1://Number key 2

					break;
					case 2://Number key 3

					break;
					case 3://Number key 4

					break;
					case 4://Number key 5

					break;
					case 5://Number key 6

					break;
					case 6://Number key 7

					break;
					case 7://Number key 8

					break;
					case 8://Number key 9

					break;
					default:
						echo "[InventoryUtils] UnknownButtonType: ".$packet->mode." : ".$packet->button."\n";
					break;
				}
			break;
			case 3:
				switch($packet->button){
					case 2://Middle click

					break;
					default:
						echo "[InventoryUtils] UnknownButtonType: ".$packet->mode." : ".$packet->button."\n";
					break;
				}
			break;
			case 4:
				switch($packet->button){
					case 0:
						if($packet->slot !== -999){//Drop key

						}else{//Left click outside inventory holding nothing

						}
					break;
					case 1:
						if($packet->slot !== -999){//Ctrl + Drop key

						}else{//Right click outside inventory holding nothing

						}
					break;
					default:
						echo "[InventoryUtils] UnknownButtonType: ".$packet->mode." : ".$packet->button."\n";
					break;
				}
			break;
			case 5:
				switch($packet->button){
					case 0://Starting left mouse drag

					break;
					case 1://Add slot for left-mouse drag

					break;
					case 2://Ending left mouse drag

					break;
					case 4://Starting right mouse drag

					break;
					case 5://Add slot for right-mouse drag

					break;
					case 6://Ending right mouse drag

					break;
					case 8://Starting middle mouse drag

					break;
					case 9://Add slot for middle-mouse drag

					break;
					case 10://Ending middle mouse drag

					break;
					default:
						echo "[InventoryUtils] UnknownButtonType: ".$packet->mode." : ".$packet->button."\n";
					break;
				}
			break;
			case 6:
				switch($packet->button){
					case 0://Double click

					break;
					default:
						echo "[InventoryUtils] UnknownButtonType: ".$packet->mode." : ".$packet->button."\n";
					break;
				}
			break;
			default:
				echo "[InventoryUtils] ClickWindowPacket: ".$packet->mode."\n";
			break;
		}

		if($packet->windowID === 0){
			if($packet->slot >= 1 and $packet->slot){

			}
			$this->onCraft();
		}

		var_dump($packet);

		$packets = [];
		if($accepted){
			$pk = new InventorySlotPacket();
			$pk->windowId = $packet->windowID;
			$pk->item = $item;
			$pk->slot = $packet->slot;

			if($packet->windowID !== ContainerIds::INVENTORY){
				if($pk->slot >= $this->windowInfo[$packet->windowID]["slots"]){
					$pk->windowId = ContainerIds::INVENTORY;

					if($pk->slot >= 36 and $pk->slot < 45){
						$slots = 0;
					}else{
						$slots = 9;
					}

					$pk->slot = ($pk->slot - $this->windowInfo[$packet->windowID]["slots"]) + $slots;
				}
			}

			$packets[] = $pk;
		}

		$pk = new ConfirmTransactionPacket();
		$pk->windowID = $packet->windowID;
		$pk->actionNumber = $packet->actionNumber;
		$pk->accepted = $accepted;
		$this->player->putRawPacket($pk);

		return $packets;
	}

	/**
	 * @param CreativeInventoryActionPacket $packet
	 * @return DataPacket|null
	 */
	public function onCreativeInventoryAction(CreativeInventoryActionPacket $packet) : ?DataPacket{
		if($packet->slot === 65535){
			foreach($this->player->getInventory()->getContents() as $slot => $item){
				if($item->equals($packet->item, true, true)){
					$this->player->getInventory()->setItem($slot, Item::get(Item::AIR));
					break;
				}
			}

			// TODO check if item in the packet is not illegal
			$this->player->dropItemNaturally($packet->item);

			return null;
		}else{
			$pk = new InventorySlotPacket();
			$pk->item = $packet->item;

			if($packet->slot > 4 and $packet->slot < 9){//Armor
				$pk->windowId = ContainerIds::ARMOR;
				$pk->slot = $packet->slot - 5;
			}else{//Inventory
				$pk->windowId = ContainerIds::INVENTORY;

				if($packet->slot > 35 and $packet->slot < 45){//hotbar
					$pk->slot = $packet->slot - 36;
				}else{
					$pk->slot = $packet->slot;
				}
			}
			return $pk;
		}
		return null;
	}

	/**
	 * @param TakeItemEntityPacket $packet
	 * @return OutboundPacket|null
	 */
	public function onTakeItemEntity(TakeItemEntityPacket $packet) : ?OutboundPacket{
		$itemCount = 1;
		$item = Item::get(0);

		$entity = $this->player->getLevel()->getEntity($packet->target);
		if($entity instanceof ItemEntity){
			$item = $entity->getItem();
			$itemCount = $item->getCount();
		}

		if($this->player->getInventory()->canAddItem($item)){
			$emptyslot = $this->player->getInventory()->firstEmpty();

			$slot = -1;
			for($index = 0; $index < $this->player->getInventory()->getSize(); ++$index){
				$i = $this->player->getInventory()->getItem($index);
				if($i->equals($item) and $item->getCount() < $item->getMaxStackSize()){
					$slot = $index;
					$i->setCount($i->getCount() + 1);
					break;
				}
			}

			if($slot === -1){
				$slot = $emptyslot;
				$i = clone $item;
			}

			$pk = new InventorySlotPacket();
			$pk->windowId = ContainerIds::INVENTORY;
			$pk->slot = $slot;
			$pk->item = $i;
			$this->player->handleDataPacket($pk);

			$pk = new CollectItemPacket();
			$pk->eid = $packet->eid;
			$pk->target = $packet->target;
			$pk->itemCount = $itemCount;

			return $pk;
		}

		return null;
	}

	public function onCraft() : void{
		//TODO implement me!!
	}

	/**
	 * @param array $craftInfoData
	 */
	public function setCraftInfoData(array $craftInfoData) : void{
		$this->craftInfoData = $craftInfoData;
	}
}
