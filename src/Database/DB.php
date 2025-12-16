<?php

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Classe DB per gestione database
 * 
 * Interfaccia semplice simile a Laravel DB facade.
 * Scrivi la query SQL completa con placeholders, la classe si occupa della sicurezza.
 * 
 * SICUREZZA: Usa sempre placeholders (? o :nome) per i valori!
 * 
 * Esempi:
 *   DB::select("SELECT * FROM users WHERE id = :id", ['id' => 1]);
 *   DB::insert("INSERT INTO users (name, email) VALUES (:name, :email)", ['name' => 'Mario', 'email' => 'mario@example.com']);
 *   DB::update("UPDATE users SET name = :name WHERE id = :id", ['name' => 'Luigi', 'id' => 1]);
 *   DB::delete("DELETE FROM users WHERE id = :id", ['id' => 1]);
 */
class DB
{
    private static ?PDO $connection = null;
    private static ?array $config = null;

    /**
     * Ottiene la connessione PDO al database
     */
    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::createConnection();
        }
        return self::$connection;
    }

    /**
     * Crea una nuova connessione al database
     */
    private static function createConnection(): PDO
    {
        $config = self::getConfig();
        $driver = $config['driver'] ?? 'mysql';

        // Costruisce il DSN (Data Source Name) in base al driver
        switch ($driver) {
            case 'mysql':
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $config['host'],
                    $config['port'] ?? 3306,
                    $config['database'],
                    $config['charset'] ?? 'utf8mb4'
                );
                break;

            case 'pgsql':
                $dsn = sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    $config['host'],
                    $config['port'] ?? 5432,
                    $config['database']
                );
                break;

            case 'sqlite':
                $dsn = 'sqlite:' . $config['sqlite_database'];
                break;

            default:
                throw new RuntimeException("Driver database non supportato: {$driver}");
        }

        // Opzioni PDO per sicurezza
        // ATTR_EMULATE_PREPARES => false: usa prepared statements reali del database
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // IMPORTANTE per sicurezza!
        ];
        $options = array_merge($defaultOptions, $config['options'] ?? []);

        try {
            // creiamo la connessione al database istanza PDO
            // passiamo il DSN (Data Source Name), il username, la password e le opzioni PDO
            return new PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, $options);
        } catch (PDOException $e) {
            throw new RuntimeException("Errore connessione database: " . $e->getMessage());
        }
    }

    /**
     * Carica la configurazione del database
     */
    private static function getConfig(): array
    {
        if (self::$config === null) {
            $configPath = __DIR__ . '/../../config/database.php';
            if (!file_exists($configPath)) {
                throw new RuntimeException("File di configurazione database non trovato");
            }
            self::$config = require $configPath;
        }
        return self::$config;
    }

    /**
     * Esegue una query SELECT
     * 
     * Scrivi la query completa con placeholders, i valori vengono passati nell'array.
     * 
     * @param string $query Query SQL con placeholders (es: "SELECT * FROM users WHERE id = :id")
     * @param array $bindings Valori da sostituire ai placeholders (es: ['id' => 1])
     * @return array Risultati della query
     * 
     * Esempi:
     *   DB::select("SELECT * FROM users WHERE age > :age", ['age' => 18]);
     *   DB::select("SELECT * FROM users WHERE name LIKE :name", ['name' => '%Mario%']);
     */
    public static function select(string $query, array $bindings = []): array
    {
        try {
            // 1. Prepara la query (il database la analizza)
            $stmt = self::connection()->prepare($query);
            
            // 2. Esegue la query sostituendo i placeholders con i valori
            // I valori vengono automaticamente escapati dal database (sicuro!)
            $stmt->execute($bindings);
            
            // 3. Restituisce tutti i risultati come array associativo
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new RuntimeException("Errore SELECT: " . $e->getMessage());
        }
    }

    /**
     * Inserisce un nuovo record
     * 
     * Scrivi la query INSERT completa con placeholders.
     * 
     * @param string $query Query INSERT con placeholders
     * @param array $bindings Valori da inserire
     * @return int ID del record inserito (0 se non disponibile, es. tabelle pivot senza sequenza)
     * 
     * Esempi:
     *   DB::insert("INSERT INTO users (name, email) VALUES (:name, :email)", ['name' => 'Mario', 'email' => 'mario@example.com']);
     *   DB::insert("INSERT INTO users (name, email) VALUES (?, ?)", ['Mario', 'mario@example.com']);
     */
    public static function insert(string $query, array $bindings = []): int
    {
        try {
            $stmt = self::connection()->prepare($query);
            $stmt->execute($bindings);
            
            // Per PostgreSQL, lastInsertId() può fallire se non c'è una sequenza nella tabella
            // (es. tabelle pivot senza colonna id con sequenza)
            // In questo caso, restituiamo 0 invece di lanciare un'eccezione
            try {
                $lastId = self::connection()->lastInsertId();
                // Verifica se lastId è valido (non false, non vuoto, non null)
                if ($lastId === false || $lastId === '' || $lastId === null) {
                    return 0;
                }
                return (int)$lastId;
            } catch (\Exception $e) {
                // Se lastInsertId() fallisce (es. PostgreSQL senza sequenza), restituiamo 0
                // Questo è normale per tabelle pivot senza colonna id con sequenza
                // Catturiamo \Exception invece di solo PDOException per essere sicuri
                return 0;
            }
        } catch (PDOException $e) {
            throw new RuntimeException("Errore INSERT: " . $e->getMessage());
        }
    }

    /**
     * Aggiorna record esistenti
     * 
     * Scrivi la query UPDATE completa con placeholders.
     * 
     * @param string $query Query UPDATE con placeholders
     * @param array $bindings Valori da aggiornare
     * @return int Numero di righe aggiornate
     * 
     * Esempi:
     *   DB::update("UPDATE users SET name = :name WHERE id = :id", ['name' => 'Luigi', 'id' => 1]);
     *   DB::update("UPDATE users SET name = ? WHERE id = ?", ['Luigi', 1]);
     */
    public static function update(string $query, array $bindings = []): int
    {
        try {
            $stmt = self::connection()->prepare($query);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new RuntimeException("Errore UPDATE: " . $e->getMessage());
        }
    }

    /**
     * Elimina record
     * 
     * Scrivi la query DELETE completa con placeholders.
     * 
     * @param string $query Query DELETE con placeholders
     * @param array $bindings Valori per la condizione WHERE
     * @return int Numero di righe eliminate
     * 
     * Esempi:
     *   DB::delete("DELETE FROM users WHERE id = :id", ['id' => 1]);
     *   DB::delete("DELETE FROM users WHERE id = ?", [1]);
     */
    public static function delete(string $query, array $bindings = []): int
    {
        try {
            $stmt = self::connection()->prepare($query);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new RuntimeException("Errore DELETE: " . $e->getMessage());
        }
    }

    /**
     * Esegue una query SQL generica
     * 
     * Usa questo metodo per qualsiasi tipo di query (SELECT, INSERT, UPDATE, DELETE).
     * 
     * IMPORTANTE: Usa sempre placeholders per i valori!
     * SBAGLIATO: DB::query("SELECT * FROM users WHERE id = " . $id);
     * CORRETTO:  DB::query("SELECT * FROM users WHERE id = :id", ['id' => $id]);
     * 
     * @param string $query Query SQL con placeholders
     * @param array $bindings Valori da sostituire
     * @return array|int Array per SELECT, numero righe per INSERT/UPDATE/DELETE
     */
    public static function query(string $query, array $bindings = [])
    {
        try {
            $stmt = self::connection()->prepare($query);
            $stmt->execute($bindings);

            // Se è una SELECT, restituisce i risultati
            if (stripos(trim($query), 'SELECT') === 0) {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Altrimenti restituisce il numero di righe modificate
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new RuntimeException("Errore query: " . $e->getMessage());
        }
    }

    /**
     * Esegue uno statement SQL (CREATE TABLE, ALTER TABLE, ecc.)
     * 
     * @param string $statement Statement SQL
     * @param array $bindings Parametri (se necessario)
     * @return bool
     */
    public static function statement(string $statement, array $bindings = []): bool
    {
        try {
            $stmt = self::connection()->prepare($statement);
            return $stmt->execute($bindings);
        } catch (PDOException $e) {
            throw new RuntimeException("Errore statement: " . $e->getMessage());
        }
    }

    /**
     * Inizia una transazione
     */
    public static function beginTransaction(): bool
    {
        return self::connection()->beginTransaction();
    }

    /**
     * Conferma una transazione
     */
    public static function commit(): bool
    {
        return self::connection()->commit();
    }

    /**
     * Annulla una transazione
     */
    public static function rollBack(): bool
    {
        return self::connection()->rollBack();
    }

    /**
     * Chiude la connessione al database
     * 
     * NOTA: Non è necessario chiamare questo metodo manualmente.
     * PHP chiude automaticamente tutte le connessioni quando lo script termina.
     * PDO chiude anche automaticamente le connessioni quando l'oggetto viene distrutto.
     * 
     * Usa questo metodo solo se vuoi chiudere esplicitamente la connessione
     * prima della fine dello script (es. in test o per liberare risorse).
     */
    public static function disconnect(): void
    {
        self::$connection = null;
    }
}
