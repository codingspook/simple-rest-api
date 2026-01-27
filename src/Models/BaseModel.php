<?php

namespace App\Models;

use App\Database\JSONDB;
use App\Database\DB;
use App\Traits\HasRelations;

abstract class BaseModel
{
    use HasRelations;

    public ?int $id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    protected static string $collection;
    
    /**
     * Driver database da utilizzare: 'json' o 'database'
     * Può essere sovrascritto nelle classi figlie
     */
    protected static string $driver = 'database';
    
    /**
     * Nome della tabella nel database (se driver = 'database')
     * Se non specificato, usa il valore di $collection
     */
    protected static ?string $table = null;
    
    // Cache delle relazioni caricate
    protected array $relations = [];
    
    // Relazioni da caricare con eager loading (per il prossimo metodo statico chiamato)
    protected static array $eagerLoad = [];

    public function __construct(array $data = [])
    {
        $this->fill($data);
    }

    /**
     * Ottiene il prossimo ID disponibile
     */
    protected static function getNextId(): int
    {
        if (static::$driver === 'json') {
            return JSONDB::getNextId(static::$collection);
        } else {
            throw new \Exception("getNextId non supportato per driver database.");
        }
    }

    /**
     * Ottiene il nome della tabella
     */
    protected static function getTableName(): string
    {
        return static::$table ?? static::$collection;
    }

    /**
     * Imposta le relazioni da caricare con eager loading
     * 
     * @param string|array $relations Nome della relazione o array di nomi
     * @return static Oggetto istanza del modello corrente con metodo find() come istanza
     */
    public static function with(string|array $relations): static
    {
        static::$eagerLoad = is_array($relations) ? $relations : [$relations];
        
        // Restituisce l'istanza del modello corrente
        return new static();
    }

    /**
     * Legge tutti i record dalla collection/tabella
     */
    public static function all(): array
    {
        $rows = [];
        
        if (static::$driver === 'json') {
            $rows = JSONDB::read(static::$collection);
        } else {
            // Se ci sono relazioni da caricare con eager loading, usa JOIN
            if (!empty(static::$eagerLoad)) {
                $models = static::allWithJoins();
                // Le relazioni sono già caricate nei modelli, reset eagerLoad
                static::$eagerLoad = [];
                return $models;
            } else {
                $rows = DB::select("SELECT * FROM " . static::getTableName());
            }
        }
        
        $models = array_map(fn($row) => new static($row), $rows);
        
        // Se ci sono relazioni da caricare con eager loading, caricale
        if (!empty(static::$eagerLoad)) {
            static::eagerLoadRelations($models);
            // Reset dopo l'uso
            static::$eagerLoad = [];
        }
        
        return $models;
    }

    /**
     * Trova un record per ID (metodo statico)
     */
    public static function find(int $id): ?static
    {
        $row = null;
        if (static::$driver === 'json') {
            $collection = JSONDB::read(static::$collection);
            foreach ($collection as $item) {
                if (isset($item['id']) && $item['id'] === $id) {
                    $row = $item;
                    break;
                }
            }
        } else {
            // Se ci sono relazioni da caricare con eager loading, usa JOIN
            if (!empty(static::$eagerLoad)) {
                $models = static::findWithJoins($id);
                if (!empty($models)) {
                    // Le relazioni sono già caricate nei modelli
                    static::$eagerLoad = [];
                    return $models[0];
                }
                return null;
            } else {
                $result = DB::select("SELECT * FROM " . static::getTableName() . " WHERE id = :id", ['id' => $id]);
                $row = $result[0] ?? null;
            }
        }
        
        if (!$row) {
            return null;
        }
        
        // Se le relazioni sono già state caricate con JOIN, il modello è già stato restituito
        // Altrimenti crea il modello normalmente
        $model = new static($row);
        
        // Se ci sono relazioni da caricare con eager loading, caricale
        if (!empty(static::$eagerLoad)) {
            static::eagerLoadRelations([$model]);
            // Reset dopo l'uso
            static::$eagerLoad = [];
        }
        
        return $model;
    }

    /**
     * Filtra i record in base a una condizione WHERE
     * 
     * @param string $column Nome della colonna
     * @param mixed $operator Operatore o valore (se si usa '=' come operatore di default)
     * @param mixed $value Valore da confrontare (opzionale se $operator è il valore)
     * @param string|array|null $relations Relazioni da caricare con eager loading (opzionale)
     * @return array Array di modelli che soddisfano la condizione
     */
    public static function where(string $column, mixed $operator, mixed $value = null, string|array|null $relations = null): array
    {
        // Se viene passato $relations come parametro, imposta eagerLoad
        if ($relations !== null) {
            static::$eagerLoad = is_array($relations) ? $relations : [$relations];
        }
        
        // Validazione operatore - controlla se $operator è un operatore valido
        $allowedOperators = ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];
        $operatorUpper = strtoupper($operator);
        
        // Se $value è null e $operator non è un operatore valido, allora $operator è il valore
        if ($value === null && !in_array($operatorUpper, $allowedOperators)) {
            $value = $operator;
            $operator = '=';
        } else {
            $operator = $operatorUpper;
        }
        
        // Validazione finale operatore
        if (!in_array($operator, $allowedOperators)) {
            throw new \InvalidArgumentException("Operatore non valido: {$operator}");
        }
        
        $models = [];
        
        if (static::$driver === 'json') {
            // Gestione driver JSON
            $collection = JSONDB::read(static::$collection);
            $filtered = array_filter($collection, function($item) use ($column, $operator, $value) {
                if (!isset($item[$column])) {
                    return false;
                }
                
                $columnValue = $item[$column];
                
                return match($operator) {
                    '=' => $columnValue == $value,
                    '!=', '<>' => $columnValue != $value,
                    '>' => $columnValue > $value,
                    '<' => $columnValue < $value,
                    '>=' => $columnValue >= $value,
                    '<=' => $columnValue <= $value,
                    'LIKE' => str_contains(strtolower($columnValue), strtolower(str_replace('%', '', $value))),
                    'NOT LIKE' => !str_contains(strtolower($columnValue), strtolower(str_replace('%', '', $value))),
                    'IN' => is_array($value) && in_array($columnValue, $value),
                    'NOT IN' => is_array($value) && !in_array($columnValue, $value),
                    default => false
                };
            });
            
            $models = array_map(fn($row) => new static($row), array_values($filtered));
        } else {
            // Gestione driver database
            // Se ci sono relazioni da caricare con eager loading, usa JOIN
            if (!empty(static::$eagerLoad)) {
                $models = static::whereWithJoins($column, $operator, $value);
                // Le relazioni sono già caricate nei modelli, reset eagerLoad
                static::$eagerLoad = [];
                return $models;
            }
            
            // Query normale senza JOIN
            if (in_array($operator, ['IN', 'NOT IN'])) {
                // Gestione speciale per IN e NOT IN
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("Il valore per gli operatori IN/NOT IN deve essere un array");
                }
                
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $sql = "SELECT * FROM " . static::getTableName() . " WHERE {$column} {$operator} ({$placeholders})";
                $rows = DB::select($sql, array_values($value));
            } else {
                // Query normale con placeholder nominale
                $sql = "SELECT * FROM " . static::getTableName() . " WHERE {$column} {$operator} :value";
                $rows = DB::select($sql, ['value' => $value]);
            }
            
            $models = array_map(fn($row) => new static($row), $rows);
        }
        
        // Se ci sono relazioni da caricare con eager loading, caricale
        if (!empty(static::$eagerLoad)) {
            static::eagerLoadRelations($models);
            // Reset dopo l'uso
            static::$eagerLoad = [];
        }
        
        return $models;
    }

    /**
     * Inserisce un nuovo record nel database
     */
    public static function create(array $data): static
    {
        $model = new static($data);
        $model->save();
        return $model;
    }

    /**
     * Aggiorna un record nel database
     */
    public function update(array $data): static
    {
        $this->fill($data);
        $this->save();
        return $this;
    }

    /**
     * Riempie il modello con i dati passati
     * @param array $data Dati da riempire
     * @return static
     */
    public function fill(array $data): static
    {
        foreach($data as $key => $value) {
            if(property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        return $this;
    }

    /**
     * Salva il record nel database
     */
    public function save(): void
    {
        $isNew = !isset($this->id);
        $now = date('Y-m-d H:i:s');
        
        // Timestamp di creazione e aggiornamento
        $this->created_at = $this->created_at ?: $now; // ?: elvis operator per assegnare il valore di default se non è settato
        $this->updated_at = $now; // aggiorniamo a prescindere se è nuovo o no

        if (static::$driver === 'json') {
            $collectionData = JSONDB::read(static::$collection);
            if ($isNew) {
                $this->id = JSONDB::getNextId(static::$collection);
                $collectionData[] = $this->toArray();
            } else {
                $collectionData = array_map(function ($item) {
                    if ($item['id'] === $this->id) {
                        return $this->toArray();
                    }
                    return $item;
                }, $collectionData);
            }
            JSONDB::write(static::$collection, $collectionData);
        } else {
            
            // i bindings sono gli array di valori da inserire nella query
            // ['name' => 'Mario', 'email' => 'mario@example.com', ...]
            $bindings = array_filter($this->toArray(), fn($key) => $key !== 'id', ARRAY_FILTER_USE_KEY);

            // dobbiamo ottenere i nomi delle colonne per la query nel formato:
            // ['name', 'email', ...]
            $columns = array_keys($bindings);

            // dobbiamo ottenere i placeholders per la query nel formato:
            // [':name', ':email', ...]
            $placeholders = array_map(fn($col) => ":{$col}", $columns);

            if ($isNew) {
                $this->id = DB::insert(sprintf("INSERT INTO %s (%s) VALUES (%s)", static::getTableName(), implode(', ', $columns), implode(', ', $placeholders)), $bindings);
                // la query INSERT è tipo:
                // INSERT INTO users (name, email) VALUES (:name, :email)
            } else {
                // mappiamo le colonne con i valori per la query nel formato e 
                // ['name = :name', 'email = :email', ...]
                $columnWithValues = array_map(fn($col) => "{$col} = :{$col}", $columns);
                // la query UPDATE è tipo:
                // UPDATE users SET name = :name, email = :email WHERE id = :id
                // Aggiungiamo id ai bindings perché è necessario per la clausola WHERE
                $bindings['id'] = $this->id;
                DB::update(sprintf("UPDATE %s SET %s WHERE id = :id", static::getTableName(), implode(', ', $columnWithValues)), $bindings);
            }
        }
    }

    public function delete(): int
    {
        $result = 0;
        if(static::$driver === 'json') {
            $collection = static::all();
            $newCollection = array_filter($collection, fn($item) => $item->id !== $this->id);
            $result = JSONDB::write(static::$collection, $newCollection);
        } else {
            $result = DB::delete("DELETE FROM " . static::getTableName() . " WHERE id = :id", ['id' => $this->id]);
        }
        if($result === 0) {
            throw new \Exception("Errore durante l'eliminazione dell'utente");
        }
        return $result;
    }

    /**
     * Metodo magico per intercettare chiamate a metodi quando viene usato with()->find(), with()->all() o with()->where()
     * 
     * @param string $method Nome del metodo chiamato
     * @param array $arguments Argomenti passati al metodo
     * @return mixed Risultato del metodo statico chiamato
     */
    public function __call(string $method, array $arguments)
    {
        // Se viene chiamato find() o all() su un'istanza restituita da with(), 
        // chiama il metodo statico corrispondente mantenendo lo stato di eagerLoad
        if ($method === 'find' && !empty($arguments)) {
            return static::find($arguments[0]);
        }
        
        if ($method === 'all' && empty($arguments)) {
            return static::all();
        }
        
        // Se viene chiamato where() su un'istanza restituita da with(),
        // chiama il metodo statico corrispondente mantenendo lo stato di eagerLoad
        if ($method === 'where' && count($arguments) >= 2) {
            // where() può avere 2, 3 o 4 parametri: (column, operator, value, relations)
            // Ma se viene chiamato dopo with(), le relazioni sono già in static::$eagerLoad
            // quindi passiamo solo i primi 3 parametri (column, operator, value)
            return static::where(...$arguments);
        }

        // Se il metodo non esiste, lancia un'eccezione
        throw new \BadMethodCallException("Metodo {$method} non trovato nella classe " . get_class($this));
    }

    public function toArray(): array
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        $result = [];
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            // Escludiamo le proprietà statiche e di configurazione
            if (in_array($propertyName, ['collection', 'driver', 'table', 'relations'])) {
                continue;
            }
            // Includiamo solo i valori non null (o tutti se necessario)
            $result[$propertyName] = $property->getValue($this);
        }

        // Aggiungi le relazioni caricate (anche se vuote o null)
        foreach ($this->relations as $relationName => $relationData) {
            if (is_array($relationData)) {
                // Relazione hasMany: array di modelli (può essere vuoto)
                $result[$relationName] = array_map(function($model) {
                    return $model instanceof BaseModel ? $model->toArray() : $model;
                }, $relationData);
            } elseif ($relationData instanceof BaseModel) {
                // Relazione belongsTo o hasOne: singolo modello
                $result[$relationName] = $relationData->toArray();
            } elseif ($relationData === null) {
                // Relazione null (belongsTo/hasOne senza dati)
                $result[$relationName] = null;
            } else {
                // Altri tipi di dati
                $result[$relationName] = $relationData;
            }
        }

        return $result;
    }
}
