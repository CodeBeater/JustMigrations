<?php
  namespace CodeBeater;

  class Database {
    private $connection = null;
    private static $instance = null;
    private static $migrationPath = null;

    public function __construct(
      $hostname,
      $database,
      $user,
      $password,
      $migrationPath = "migrations/"
    ) {
      $this->connection = new PDO("mysql:host={$hostname};dbname={$database};charset=utf8", $user, $password);
      $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

      $this->migrationPath = $migrationPath . "/";
    }

    public static function getInstance(
      $hostname = null,
      $database = null,
      $user = null,
      $password = null,
      $migrationPath = null
    ) {
      if (!isset(static::$instance)) {
        if (!isset($hostname) || !isset($database) || !isset($user) || !isset($password)) {
          throw new Exception("Attempted to get database instance without one being available.");
          return false;
        } 
        static::$instance = new static($hostname, $database, $user, $password, $migrationPath); 
      }

      return static::$instance;
    } 

    public function getConnection() {
      return $this->connection;
    }

    public function runMigrations() {
      $this->setupMigrations();
      $latestMigration = $this->getLatestMigration();

      //Getting all currently available migration files    
      $possibleMigrations = scandir($this->migrationPath, SCANDIR_SORT_ASCENDING);
      foreach ($possibleMigrations as $migration) {
        if ($migration == "." || $migration == "..") {
          continue;
        }

        //Parsing migration name and checking if the id is bigger than the last ran migration
        $migrationMeta = $this->parseMigrationMeta($migration);
        
        //Running the migration
        if ($migrationMeta['id'] > $latestMigration) {
          $success = $this->executeMigration($this->migrationPath . $migration, "UP");
          //If successful, register it in the migraitons table
          if ($success) {
            $latestMigration = $migrationMeta['id'];
            $this->registerMigration($migrationMeta['id'], $migration);
          } else {
            throw new Exception("There was an error trying to run a migration ({$migration})");
          }
        }
      }
    }

    private function registerMigration($id, $file) {
      $stmt = $this->getConnection()->prepare("INSERT INTO `migrations` (`id`, `file`) VALUES (:id, :file)");
      $stmt->execute(array(
        ":id" => $id,
        ":file" => $file
      ));

      return;
    }

    private function executeMigration($path, $direction) {
      $validDirections = ["UP", "DOWN"];
      if (!in_array($direction, $validDirections)) {
        throw new Exception("Invalid migration direction ({$direction}) for migration {$path}");
      }

      //Getting migration contents and preparing query
      $migration = $this->parseMigration($path);
      $stmt = $this->getConnection()->prepare($migration[$direction]);
      $stmt->execute();

      return ($stmt->errorCode() === "00000");
    }

    private function getLatestMigration() {
      //Attempting to get the latest migraiton on the database
      $getLatestMigration = $this->getConnection()->prepare("SELECT * FROM `migrations` WHERE 1 ORDER BY `id` DESC LIMIT 1");
      $getLatestMigration->execute();

      if ($getLatestMigration->rowCount() > 0) {
        $getLatestMigration->execute();
        $latestMigration = $getLatestMigration->fetch(PDO::FETCH_ASSOC); 
        return $latestMigration['id'];
      }

      return -1;
    }

    private function setupMigrations() {
      //Checking if the database already has a migration history
      $checkForMigrations = $this->getConnection()->prepare(
        file_get_contents(__DIR__ . "/queries/FindMigrationsTable.sql", "utf8")
      );
      $checkForMigrations->execute();

      //If it isn't, then we create it
      if ($checkForMigrations->rowCount() < 1) {
        $createMigrationsTable = $this->getConnection()->prepare(
          file_get_contents(__DIR__ . "/queries/CreateMigrationsTable.sql", "utf8")
        );
        $createMigrationsTable->execute();
      }
    }

    private function parseMigration($file) {
      //Preapring return
      $return = [
        "up" => "",
        "down" => ""
      ];

      //Reading the migration file
      $fileFromDisk = file_get_contents($file, "utf8");
      $fileFromDisk = explode(PHP_EOL, $fileFromDisk);

      //Iterating the file line by line and attempting to find delimiters
      $upDelimiter = 0;
      $downDelimiter = 0;
      $endDelimiter = 0;
      foreach ($fileFromDisk as $line => $content) {
        if ($content === "UP:") {
          $upDelimiter = $line;
          continue;
        }

        if ($content === "DOWN:") {
          $downDelimiter = $line;
          continue;
        }

        if ($content === "END_MIGRATION") {
          $endDelimiter = $line;
          continue;
        }
      }

      //Checking if all delimiters were found
      if (!($downDelimiter != 0 && $endDelimiter != 0)) {
        throw new Exception("Malformed migration file found at: {$file}");
      }

      //Extracting the queries
      $upQuery = array_slice(
        $fileFromDisk,
        $upDelimiter + 1, //Start of the "UP:" tag
        ($downDelimiter - $upDelimiter) - 1 //The ammount of lines between "UP:" and "DOWN:"
      );
      $downQuery = array_slice(
        $fileFromDisk,
        $downDelimiter + 1, //Start of the "DOWN:" tag
        ($endDelimiter - $downDelimiter) - 1 //The ammount of line between "DOWN:" and "END_MIGRATION"
      );

      //Turning the queries back into strings
      $return['UP'] = implode(PHP_EOL, $upQuery);
      $return['DOWN'] = implode(PHP_EOL, $downQuery);

      return $return;
    }

    private function parseMigrationMeta($file) {
      $fileName = explode(" - ", $file);

      $toBeReturned = [
        "id" => $fileName[0],
        "name" => $fileName[1]
      ];

      return $toBeReturned;
    }
  }
?>