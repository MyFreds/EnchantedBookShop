<?php

declare(strict_types=1);

namespace Fred\ebs;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\block\{VanillaBlocks, utils\DyeColor};
use pocketmine\item\VanillaItems;
use pocketmine\item\EnchantedBook
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\command\{Command, CommandSender};
use onebone\economyapi\EconomyAPI;
use muqsit\invmenu\{InvMenu, InvMenuHandler};
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use jojoe77777\FormAPI\CustomForm;

class EBS extends PluginBase implements Listener
{
    private static $instance;
    public $prefix;
    public $menu;
    public $cfg;
    public $messages;

    public function onEnable(): void
    {
        if (!InvMenuHandler::isRegistered()) {
            InvMenuHandler::register($this);
        }
        $this->menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        self::$instance = $this;
        $this->saveDefaultConfig();
        $this->cfg = $this->getConfig();

        $this->saveResource("message.yml");
        $this->messages = new Config(
            $this->getDataFolder() . "message.yml",
            Config::YAML
        );
        $this->prefix = $this->getMessage("ebs-prefix");

        $this->getServer()
            ->getPluginManager()
            ->registerEvents($this, $this);
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args
    ): bool {
        if ($command->getName() === "ebs" && $sender instanceof Player) {
            $page = isset($args[0]) ? (int) $args[0] : 1;
            $this->ebsMenu($sender, $page);
            self::playSound($sender, "random.chestopen");
            return true;
        }
        return false;
    }

    public function ebsMenu(Player $player, int $page = 1)
    {
        $inv = $this->menu->getInventory();
        $inv->clearAll();
        $this->menu->setListener(InvMenu::readonly());
        $this->menu->setListener(
            \Closure::fromCallable([$this, "ebsListener"])
        );
        $totalItemsPerPage = 53;
        $itemsPerPage = 28;
        $vines = [0, 9, 18, 27, 36, 8, 17, 26, 35, 44, 45, 53];
        $white = [3, 4, 5, 48, 49, 50];
        $black = [1, 2, 6, 7, 46, 47, 51, 52];

        $this->fillSlots($inv, $vines, VanillaBlocks::VINES()->asItem(), "§8-");
        $this->fillSlots(
            $inv,
            $white,
            VanillaBlocks::STAINED_GLASS_PANE()
                ->setColor(DyeColor::WHITE())
                ->asItem(),
            "§8-"
        );
        $this->fillSlots(
            $inv,
            $black,
            VanillaBlocks::STAINED_GLASS_PANE()
                ->setColor(DyeColor::BLACK())
                ->asItem(),
            "§8-"
        );

        $config = new Config(
            $this->getDataFolder() . "config.yml",
            Config::YAML
        );
        $items = $config->get("Shop-enchant", []);
        $enchantedSlots = [
            10,
            11,
            12,
            13,
            14,
            15,
            16,
            19,
            20,
            21,
            22,
            23,
            24,
            25,
            28,
            29,
            30,
            31,
            32,
            33,
            34,
            37,
            38,
            39,
            40,
            41,
            42,
            43,
        ];

        $totalPages = (int) ceil(count($items) / $itemsPerPage);
        $start = ($page - 1) * $itemsPerPage;
        $end = min($start + $itemsPerPage, count($items));

        foreach (
            array_slice($items, $start, $end - $start)
            as $enchantKey => $enchantData
        ) {
            $itemId = $enchantData["id"];
            $itemName = $enchantData["name"];
            $itemPrice = $enchantData["price"];
            $itemMaxLevel = $enchantData["max-level"];
            $name = $this->getMessage("enchant-item-name", [
                "{name}" => ucfirst($itemName),
                "{id}" => $itemId,
                "{price}" => $itemPrice,
                "{max-level}" => $itemMaxLevel,
            ]);
            $item = VanillaItems::ENCHANTED_BOOK();
            $item->setCustomName($name);
            $this->enchantInvItem($player, $item, (int) $itemMaxLevel, $itemId);
            foreach ($enchantedSlots as $slot) {
                if ($inv->getItem($slot)->isNull()) {
                    $inv->setItem($slot, $item);
                    break;
                }
            }
        }

        if ($page > 1) {
            $backItem = VanillaItems::PAPER()->setCustomName(
                $this->getMessage("back-page-name")
            );
            $inv->setItem(45, $backItem);
        }
        if ($page < $totalPages) {
            $nextItem = VanillaItems::PAPER()->setCustomName(
                $this->getMessage("next-page-name")
            );
            $inv->setItem(53, $nextItem);
        }

        $this->menu->setName(
            $this->getMessage("menu-title", [
                "{page}" => $page,
                "{totalPages}" => $totalPages,
            ])
        );
        $this->menu->send($player);
    }

    private function fillSlots($inventory, $slots, $item, $name)
    {
        foreach ($slots as $slot) {
            $inventory->setItem($slot, $item->setCustomName($name));
        }
    }

    public function ebsListener(
        InvMenuTransaction $transaction
    ): InvMenuTransactionResult {
        $player = $transaction->getPlayer();
        $itemClicked = $transaction->getItemClicked();

        if (
            $itemClicked->getCustomName() ===
            $this->getMessage("back-page-name")
        ) {
            $page = max(1, $this->currentPage($player) - 1);
            $this->ebsMenu($player, $page);
            return $transaction->discard();
        }

        if (
            $itemClicked->getCustomName() ===
            $this->getMessage("next-page-name")
        ) {
            $page = $this->currentPage($player) + 1;
            $this->ebsMenu($player, $page);
            return $transaction->discard();
        }

        $itemData = $this->parseItemName($itemClicked->getCustomName());
        if ($itemData !== false) {
            $itemId = $itemData["id"];
            $itemPrice = $itemData["price"];
            $itemMaxLevel = $itemData["max-level"];
            $player->removeCurrentWindow();
            self::playSound($player, "random.pop");
            $this->resultEnchant($player, $itemId, $itemMaxLevel, $itemPrice);
            return $transaction->discard();
        }

        return $transaction->discard();
    }

    private function currentPage(Player $player): int
    {
        // No logic.
        return 1;
    }

    private function parseItemName(string $itemName)
    {
        $matches = [];
        $pattern =
            "/^§e(.+) Enchantment\n\n§rId: §a(\d+)\n§rPrice: §a(\d+)\n§rMax-Level: §a(\d+)\n$/";
        if (preg_match($pattern, $itemName, $matches)) {
            return [
                "name" => $matches[1],
                "id" => (int) $matches[2],
                "price" => (float) $matches[3],
                "max-level" => (int) $matches[4],
            ];
        }
        return false;
    }

    public function resultEnchant(Player $player, $id, $maxlevel, $price)
    {
        $form = new CustomForm(function (Player $player, $data = null) use (
            $id,
            $maxlevel,
            $price
        ) {
            if ($data == null) {
                return true;
            }

            $enc = EnchantmentIdMap::getInstance()->fromId($id);
            $money = EconomyAPI::getInstance()->myMoney($player);
            $level = (int) $data[1];

            if ($enc !== null && $level <= $maxlevel) {
                $totalPrice = $price * $level;
                if ($money >= $totalPrice) {
                    if (
                        $player
                            ->getInventory()
                            ->canAddItem(VanillaItems::ENCHANTED_BOOK())
                    ) {
                        $book = VanillaItems::ENCHANTED_BOOK();
                        $book->addEnchantment(
                            new EnchantmentInstance($enc, $level)
                        );
                        EconomyAPI::getInstance()->reduceMoney(
                            $player,
                            $totalPrice
                        );
                        $player->getInventory()->addItem($book);
                        $player->sendMessage(
                            $this->prefix . $this->getMessage("enchant-success")
                        );
                        self::playSound($player, "random.orb");
                    } else {
                        $player->sendMessage(
                            $this->prefix . $this->getMessage("inventory-full")
                        );
                        self::playSound($player, "mob.villager.no");
                    }
                } else {
                    self::playSound($player, "mob.villager.no");
                    $player->sendMessage(
                        $this->prefix . $this->getMessage("not-enough-money")
                    );
                }
            } else {
                self::playSound($player, "mob.villager.no");
                $player->sendMessage(
                    $this->prefix . $this->getMessage("invalid-enchant-level")
                );
            }
        });

        $form->setTitle($this->getMessage("payment-title"));
        $money = EconomyAPI::getInstance()->myMoney($player);
        $form->addLabel(
            $this->getMessage("money-info", [
                "{money}" => (string) $money,
                "{price}" => (string) $price,
            ])
        );
        $form->addSlider("Enchantment Level", 1, $maxlevel, 1);
        $form->sendToPlayer($player);
    }

    public function enchantInvItem(
        Player $player,
        EnchantedBook $item,
        int $level,
        $enchant
    ) {
        if (is_int($enchant)) {
            $enc = EnchantmentIdMap::getInstance()->fromId($enchant);
            if ($enc !== null) {
                $item->addEnchantment(new EnchantmentInstance($enc, $level));
            } else {
                $this->getLogger()->warning(
                    $this->prefix .
                        $this->getMessage("invalid-enchant", [
                            "{id}" => $enchant,
                        ])
                );
                $player->sendMessage(
                    $this->prefix .
                        $this->getMessage("invalid-enchant", [
                            "{id}" => $enchant,
                        ])
                );
            }
        }
    }

    public static function playSound(
        Player $player,
        string $sound,
        float $minimumVolume = 1.0,
        float $volume = 1.0,
        float $pitch = 1.0
    ) {
        $pos = $player->getPosition();
        $pk = new PlaySoundPacket();
        $pk->soundName = $sound;
        $pk->volume = $volume > $minimumVolume ? $minimumVolume : $volume;
        $pk->pitch = $pitch;
        $pk->x = $pos->x;
        $pk->y = $pos->y;
        $pk->z = $pos->z;
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public static function getInstance()
    {
        return self::$instance;
    }

    public function getMessage(string $key, array $params = []): string
    {
        $message = $this->messages->get($key, "Message not found: " . $key);
        foreach ($params as $placeholder => $value) {
            if (is_string($value) || is_numeric($value)) {
                $message = str_replace($placeholder, (string) $value, $message);
            }
        }
        return $message;
    }
}
