<?php
declare(strict_types=1);
namespace MyPlot;

use EssentialsPE\Loader;
use ImagicalGamer\EconomyPlus\Main;
use MyPlot\provider\DataProvider;
use MyPlot\provider\EconomyPlusProvider;
use MyPlot\provider\EconomyProvider;
use MyPlot\provider\EconomySProvider;
use MyPlot\provider\EssentialsPEProvider;
use MyPlot\provider\JSONDataProvider;
use MyPlot\provider\MySQLProvider;
use MyPlot\provider\PocketMoneyProvider;
use MyPlot\provider\SQLiteDataProvider;
use MyPlot\provider\YAMLDataProvider;
use MyPlot\task\ClearPlotTask;
use onebone\economyapi\EconomyAPI;
use pocketmine\event\level\LevelLoadEvent;
use pocketmine\lang\BaseLang;
use pocketmine\level\format\Chunk;
use pocketmine\level\generator\biome\Biome;
use pocketmine\level\generator\Generator;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\permission\Permission;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat as TF;
use PocketMoney\PocketMoney;
use spoondetector\SpoonDetector;

class MyPlot extends PluginBase
{
	/** @var PlotLevelSettings[] $levels */
	private $levels = [];

	/** @var DataProvider $dataProvider */
	private $dataProvider = null;

	/** @var EconomyProvider $economyProvider */
	private $economyProvider = null;

	/** @var BaseLang $baseLang */
	private $baseLang = null;

	/**
	 * Returns the Multi-lang management class
	 *
	 * @api
	 *
	 * @return BaseLang
	 */
	public function getLanguage() : BaseLang {
		return $this->baseLang;
	}

	/**
	 * Returns the DataProvider that is being used
	 *
	 * @api
	 *
	 * @return DataProvider
	 */
	public function getProvider() : DataProvider {
		return $this->dataProvider;
	}

	/**
	 * Returns the EconomyProvider that is being used
	 *
	 * @api
	 *
	 * @return EconomyProvider|null
	 */
	public function getEconomyProvider() : ?EconomyProvider {
		return $this->economyProvider;
	}

	/**
	 * Returns a PlotLevelSettings object which contains all the settings of a level
	 *
	 * @api
	 * @param string $levelName
	 *
	 * @return PlotLevelSettings|null
	 */
	public function getLevelSettings(string $levelName) : ?PlotLevelSettings {
		if (isset($this->levels[$levelName])) {
			return $this->levels[$levelName];
		}
		return null;
	}

	/**
	 * Checks if a plot level is loaded
	 *
	 * @api
	 * @param string $levelName
	 *
	 * @return bool
	 */
	public function isLevelLoaded(string $levelName) : bool {
		return isset($this->levels[$levelName]);
	}

	/**
	 * Generate a new plot level with optional settings
	 *
	 * @api
	 * @param string $levelName
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function generateLevel(string $levelName, array $settings = []) : bool {
		if ($this->getServer()->isLevelGenerated($levelName) === true) {
			return false;
		}
		if (empty($settings)) {
			$settings = $this->getConfig()->get("DefaultWorld");
		}
		$settings = [
			"preset" => json_encode($settings)
		];
		return $this->getServer()->generateLevel($levelName, null, MyPlotGenerator::class, $settings);
	}

	/**
	 * Saves provided plot if changed
	 *
	 * @api
	 * @param Plot $plot
	 *
	 * @return bool
	 */
	public function savePlot(Plot $plot) : bool {
		return $this->dataProvider->savePlot($plot);
	}

	/**
	 * Get all the plots a player owns (in a certain level if $levelName is provided)
	 *
	 * @api
	 * @param string $username
	 * @param string $levelName
	 *
	 * @return Plot[]
	 */
	public function getPlotsOfPlayer(string $username, string $levelName) : array {
		return $this->dataProvider->getPlotsByOwner($username, $levelName);
	}

	/**
	 * Get the next free plot in a level
	 *
	 * @api
	 * @param string $levelName
	 * @param int $limitXZ
	 *
	 * @return Plot|null
	 */
	public function getNextFreePlot(string $levelName, int $limitXZ = 0) : ?Plot {
		return $this->dataProvider->getNextFreePlot($levelName, $limitXZ);
	}

	/**
	 * Finds the plot at a certain position or null if there is no plot at that position
	 *
	 * @api
	 * @param Position $position
	 *
	 * @return Plot|null
	 */
	public function getPlotByPosition(Position $position) : ?Plot {
		$x = $position->x;
		$z = $position->z;
		$levelName = $position->level->getFolderName();

		$plotLevel = $this->getLevelSettings($levelName);
		if ($plotLevel === null) {
			return null;
		}

		$plotSize = $plotLevel->plotSize;
		$roadWidth = $plotLevel->roadWidth;
		$totalSize = $plotSize + $roadWidth;

		if ($x >= 0) {
			$X = (int) floor($x / $totalSize);
			$difX = $x % $totalSize;
		}else{
			$X = (int) ceil(($x - $plotSize + 1) / $totalSize);
			$difX = abs(($x - $plotSize + 1) % $totalSize);
		}

		if ($z >= 0) {
			$Z = (int) floor($z / $totalSize);
			$difZ = $z % $totalSize;
		}else{
			$Z = (int) ceil(($z - $plotSize + 1) / $totalSize);
			$difZ = abs(($z - $plotSize + 1) % $totalSize);
		}

		if (($difX > $plotSize - 1) or ($difZ > $plotSize - 1)) {
			return null;
		}

		return $this->dataProvider->getPlot($levelName, $X, $Z);
	}

	/**
	 *  Get the begin position of a plot
	 *
	 * @api
	 * @param Plot $plot
	 *
	 * @return Position|null
	 */
	public function getPlotPosition(Plot $plot) : ?Position {
		$plotLevel = $this->getLevelSettings($plot->levelName);
		if ($plotLevel === null) {
			return null;
		}

		$plotSize = $plotLevel->plotSize;
		$roadWidth = $plotLevel->roadWidth;
		$totalSize = $plotSize + $roadWidth;
		$x = $totalSize * $plot->X;
		$z = $totalSize * $plot->Z;
		$level = $this->getServer()->getLevelByName($plot->levelName);
		return new Position($x, $plotLevel->groundHeight, $z, $level);
	}

	/**
	 * Teleport a player to a plot
	 *
	 * @api
	 * @param Player $player
	 * @param Plot $plot
	 * @param bool $center
	 *
	 * @return bool
	 */
	public function teleportPlayerToPlot(Player $player, Plot $plot, bool $center = false) : bool {
		if ($center) {
			return $this->teleportMiddle($player, $plot);
		}
		$plotLevel = $this->getLevelSettings($plot->levelName);
		if ($plotLevel === null) {
			return false;
		}
		$pos = $this->getPlotPosition($plot);
		$plotSize = $plotLevel->plotSize;
		$pos->add(floor($plotSize / 2), 1, -1);
		return $player->teleport($pos);
	}

	/**
	 * Reset all the blocks inside a plot
	 *
	 * @api
	 * @param Plot $plot
	 * @param int $maxBlocksPerTick
	 *
	 * @return bool
	 */
	public function clearPlot(Plot $plot, int $maxBlocksPerTick = 256) : bool {
		if (!$this->isLevelLoaded($plot->levelName)) {
			return false;
		}
		foreach($this->getServer()->getLevelByName($plot->levelName)->getEntities() as $entity) {
			$plotB = $this->getPlotByPosition($entity);
			if ($plotB != null) {
				if ($plotB === $plot) {
					if (!$entity instanceof Player) {
						$entity->close();
					}
				}
			}
		}
		$this->getServer()->getScheduler()->scheduleTask(new ClearPlotTask($this, $plot, $maxBlocksPerTick));
		return true;
	}

	/**
	 * Delete the plot data
	 *
	 * @api
	 * @param Plot $plot
	 *
	 * @return bool
	 */
	public function disposePlot(Plot $plot) : bool {
		return $this->dataProvider->deletePlot($plot);
	}

	/**
	 * Clear and dispose a plot
	 *
	 * @api
	 * @param Plot $plot
	 * @param int $maxBlocksPerTick
	 *
	 * @return bool
	 */
	public function resetPlot(Plot $plot, int $maxBlocksPerTick = 256) : bool {
		if ($this->disposePlot($plot)) {
			return $this->clearPlot($plot, $maxBlocksPerTick);
		}
		return false;
	}

	/**
	 * Changes the biome of a plot
	 *
	 * @api
	 * @param Plot $plot
	 * @param Biome $biome
	 *
	 * @return bool
	 */
	public function setPlotBiome(Plot $plot, Biome $biome) : bool {
		foreach($this->getPlotChunks($plot) as $chunk) {
			if ($chunk instanceof Chunk) {
				for($x = 0; $x <= 16; $x++) {
					for($z = 0; $z <= 16; $z++) {
						$chunk->setBiomeId($x, $z, $biome->getId());
						$chunk->setChanged(true);
						foreach ($chunk->getEntities() as $entity) {
							if ($entity instanceof Player) {
								$entity->onChunkChanged($chunk);
								$entity->sendChunk($x, $z, $chunk);
							}
						}
					}
				}
			}
		}

		$plot->biome = $biome->getName();
		$this->savePlot($plot);
		return true;
	}

	/**
	 * Changes the completed state of the plot
	 *
	 * @api
	 * @param Plot $plot
	 * @param bool $done
	 *
	 * @return bool
	 */
	public function setPlotDone(Plot $plot, bool $done = true) : bool {
		$plot->done = $done;
		$pos1 = $this->getPlotPosition($plot);
		if ($pos1 !== null) {
			$plotLevel = $this->getLevelSettings($plot->levelName);
			if ($plotLevel === null) {
				return false;
			}

			$plotSize = $plotLevel->plotSize;
			if ($plot->X >= 0 and $plot->Z >= 0) {
				$pos1 = $pos1->asVector3()->add(0, 3, 0);
				$pos2 = $pos1->asVector3()->add($plotSize, 3);
				$pos3 = $pos1->asVector3()->add(0, 3, $plotSize);
				$pos4 = $pos1->asVector3()->add($plotSize, 3,$plotSize);
			} elseif ($plot->X < 0 and $plot->Z > 0){
				$pos1 = $pos1->asVector3()->add(0, 3, 0);
				$pos2 = $pos1->asVector3()->add(-$plotSize, 3);
				$pos3 = $pos1->asVector3()->add(0, 3, $plotSize);
				$pos4 = $pos1->asVector3()->add(-$plotSize,3, $plotSize);
			} elseif ($plot->X > 0 and $plot->Z < 0){
				$pos1 = $pos1->asVector3()->add(0, 3, 0);
				$pos2 = $pos1->asVector3()->add($plotSize, 3);
				$pos3 = $pos1->asVector3()->add(0, 3, -$plotSize);
				$pos4 = $pos1->asVector3()->add($plotSize,3, -$plotSize);
			} elseif ($plot->X < 0 and $plot->Z < 0) {
				$pos1 = $pos1->asVector3()->add(0, 3, 0);
				$pos2 = $pos1->asVector3()->add(-$plotSize, 3);
				$pos3 = $pos1->asVector3()->add(0, 3, -$plotSize);
				$pos4 = $pos1->asVector3()->add(-$plotSize,3, -$plotSize);
			} else {
				return false;
			}
			if ($done) {
				// TODO: spawn floating nametags at each corner reading the translated version of completed
			} else {
				// TODO: remove floating nametags at each corner reading the translated version of completed
			}
		} else {
			return false;
		}
		return true;
	}

	/**
	 * Returns the PlotLevelSettings of all the loaded levels
	 *
	 * @api
	 *
	 * @return PlotLevelSettings[]
	 */
	public function getPlotLevels() : array {
		return $this->levels;
	}

	/**
	 * Returns the Chunks contained in a plot
	 *
	 * @api
	 * @param Plot $plot
	 *
	 * @return Chunk[]
	 */
	public function getPlotChunks(Plot $plot) : array {
		$plotLevel = $this->getLevelSettings($plot->levelName);
		$level = $this->getServer()->getLevelByName($plot->levelName);
		$pos = $this->getPlotPosition($plot);
		$plotSize = $plotLevel->plotSize;
		$xMax = $pos->x + $plotSize;
		$zMax = $pos->z + $plotSize;

		$chunkIndexes = [];
		for ($x = $pos->x; $x < $xMax; $x++) {
			for ($z = $pos->z; $z < $zMax; $z++) {
				$index = Level::chunkHash($x >> 4, $z >> 4);
				if (!in_array($index, $chunkIndexes)) {
					$chunkIndexes[] = $index;
				}
				Level::getXZ($index, $pos->x, $pos->z);
			}
		}
		$chunks = [];
		foreach ($chunkIndexes as $index) {
			Level::getXZ($index, $plot->X, $plot->Z);
			$chunk = $level->getChunk($plot->X,$plot->Z);
			$chunks[] = $chunk;
		}

		return $chunks;
	}

	/**
	 * Get the maximum number of plots a player can claim
	 *
	 * @api
	 * @param Player $player
	 *
	 * @return int
	 */
	public function getMaxPlotsOfPlayer(Player $player) : int {
		if ($player->hasPermission("myplot.claimplots.unlimited"))
			return PHP_INT_MAX;
		/** @var Permission[] $perms */
		$perms = array_merge($this->getServer()->getPluginManager()->getDefaultPermissions($player->isOp()),
			$player->getEffectivePermissions());
		$perms = array_filter($perms, function ($name) {
			return (substr($name, 0, 18) === "myplot.claimplots.");
		}, ARRAY_FILTER_USE_KEY);
		if (count($perms) == 0)
			return 0;
		krsort($perms);
		foreach ($perms as $name => $perm) {
			$maxPlots = substr($name, 18);
			if (is_numeric($maxPlots)) {
				return (int) $maxPlots;
			}
		}
		return 0;
	}

	/**
	 * Finds the exact center of the plot at ground level
	 *
	 * @api
	 * @param Plot $plot
	 *
	 * @return Position|null
	 */
	public function getPlotMid(Plot $plot) : ?Position {
		$plotLevel = $this->getLevelSettings($plot->levelName);
		if ($plotLevel === null) {
			return null;
		}

		$plotSize = $plotLevel->plotSize;
		$pos = $this->getPlotPosition($plot);
		$pos = new Position($pos->getFloorX() + ($plotSize / 2) + 0.5, $pos->getFloorY() + 1, $pos->getFloorZ() + ($plotSize / 2) + 0.5);

		return $pos;
	}

	/**
	 * Teleports the player to the exact center of the plot at nearest open space to the ground level
	 *
	 * @api
	 * @param Plot $plot
	 * @param Player $player
	 *
	 * @return bool
	 */
	public function teleportMiddle(Player $player, Plot $plot) : bool {
		$mid = $this->getPlotMid($plot);
		if ($mid == null) {
			return false;
		}
		return $player->teleport($mid);
	}

	/* -------------------------- Non-API part -------------------------- */

	public function onLoad() : void {
		$this->getLogger()->notice(TF::BOLD."Loading...");
		$this->getLogger()->debug(TF::BOLD."Loading Config");
		$this->saveDefaultConfig();
		$this->reloadConfig();
		@mkdir($this->getDataFolder() . "worlds");

		$this->getLogger()->debug(TF::BOLD."Loading MyPlot Generator");
		// Register world generator
		Generator::addGenerator(MyPlotGenerator::class, "myplot");

		$this->getLogger()->debug(TF::BOLD."Loading Languages");
		// Loading Languages
		/** @var string $lang */
		$lang = $this->getConfig()->get("language", BaseLang::FALLBACK_LANGUAGE);
		$this->baseLang = new BaseLang($lang, $this->getFile() . "resources/");

		$this->getLogger()->debug(TF::BOLD."Loading Data Provider settings");
		// Initialize DataProvider
		/** @var int $cacheSize */
		$cacheSize = $this->getConfig()->get("PlotCacheSize", 256);
		switch (strtolower($this->getConfig()->get("DataProvider"))) {
			case "mysql":
				$settings = $this->getConfig()->get("MySQLSettings");
				$this->dataProvider = new MySQLProvider($this, $cacheSize, $settings);
				break;
			case "yaml":
				$this->dataProvider = new YAMLDataProvider($this, $cacheSize);
				break;
			case "json":
				$this->dataProvider = new JSONDataProvider($this, $cacheSize);
				break;
			case "sqlite3":
			case "sqlite":
			default:
				$this->dataProvider = new SQLiteDataProvider($this, $cacheSize);
				break;
		}
		$this->getLogger()->debug(TF::BOLD."Loading MyPlot Commands");
		// Register command
		$this->getServer()->getCommandMap()->register(Commands::class, new Commands($this));
	}

	public function onEnable() : void {
		SpoonDetector::printSpoon($this, "spoon.txt");
		if ($this->isDisabled()) {
			return;
		}
		$this->getLogger()->debug(TF::BOLD."Loading economy settings");
		// Initialize EconomyProvider
		if ($this->getConfig()->get("UseEconomy", false) === true) {
			if (($plugin = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI")) !== null) {
				if ($plugin instanceof EconomyAPI) {
					$this->economyProvider = new EconomySProvider($plugin);
					$this->getLogger()->debug("Eco set to EconomySProvider");
				}
				$this->getLogger()->debug("Eco not instance of EconomyAPI");
			} elseif (($plugin = $this->getServer()->getPluginManager()->getPlugin("EssentialsPE")) !== null) {
				if ($plugin instanceof Loader) {
					$this->economyProvider = new EssentialsPEProvider($plugin);
					$this->getLogger()->debug("Eco set to EssentialsPE");
				}
				$this->getLogger()->debug("Eco not instance of EssentialsPE");
			} elseif (($plugin = $this->getServer()->getPluginManager()->getPlugin("PocketMoney")) !== null) {
				if ($plugin instanceof PocketMoney) {
					$this->economyProvider = new PocketMoneyProvider($plugin);
					$this->getLogger()->debug("Eco set to PocketMoney");
				}
				$this->getLogger()->debug("Eco not instance of PocketMoney");
			} elseif (($plugin = $this->getServer()->getPluginManager()->getPlugin("EconomyPlus")) !== null) {
				if ($plugin instanceof Main) {
					$this->economyProvider = new EconomyPlusProvider($plugin);
					$this->getLogger()->debug("Eco set to EconomyPlus");
				}
				$this->getLogger()->debug("Eco not instance of EconomyPlus");
			}
			if (!isset($this->economyProvider)) {
				$this->getLogger()->info("No supported economy plugin found!");
				$this->getConfig()->set("UseEconomy", false);
			}
		}

		$this->getLogger()->debug(TF::BOLD."Loading Events");
		$eventListener = new EventListener($this);
		$this->getServer()->getPluginManager()->registerEvents($eventListener, $this);
		$this->getLogger()->debug(TF::BOLD."Registering Loaded Levels");
		foreach($this->getServer()->getLevels() as $level) {
			$eventListener->onLevelLoad(new LevelLoadEvent($level));
		}
		$this->getLogger()->notice(TF::GREEN."Enabled!");
	}

	/**
	 * @param string $levelName
	 * @param PlotLevelSettings $settings
	 *
	 * @return bool
	 */
	public function addLevelSettings(string $levelName, PlotLevelSettings $settings) : bool {
		$this->levels[$levelName] = $settings;
		return true;
	}

	/**
	 * @param string $levelName
	 *
	 * @return bool
	 */
	public function unloadLevelSettings(string $levelName) : bool {
		if (isset($this->levels[$levelName])) {
			unset($this->levels[$levelName]);
			$this->getLogger()->debug("Level ".$levelName." settings unloaded!");
			return true;
		}
		return false;
	}

	public function onDisable() : void {
		if ($this->dataProvider !== null) {
			$this->dataProvider->close();
		}
	}
}