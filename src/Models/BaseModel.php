<?php

namespace App\Models;

use App\Database\JSONDB;
use App\Database\DB;

abstract class BaseModel
{
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
        $useJoins = false;
        
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
        return $models;
    }

    /**
     * Carica tutti i record con JOIN per le relazioni eager load
     * 
     * @return array Array di modelli con relazioni caricate
     */
    protected static function allWithJoins(): array
    {
        $mainTable = static::getTableName();
        $mainTableAlias = 'main';
        $selects = ["{$mainTableAlias}.*"];
        $joins = [];
        $relationInfo = [];
        
        // Costruisce JOIN per ogni relazione eager load
        foreach (static::$eagerLoad as $relation) {
            $parts = explode('.', $relation);
            $firstRelation = $parts[0];
            
            // Verifica se esiste il metodo relazione
            $sampleModel = new static();
            if (!method_exists($sampleModel, $firstRelation) || !$sampleModel->isRelationMethod($firstRelation)) {
                continue;
            }
            
            // Usa reflection per chiamare il metodo protetto e capire il tipo di relazione
            $reflection = new \ReflectionClass($sampleModel);
            $method = $reflection->getMethod($firstRelation);
            $method->setAccessible(true);
            $relationResult = $method->invoke($sampleModel);
            
            // Determina il tipo di relazione e costruisce il JOIN appropriato
            $joinInfo = static::buildJoinForRelation($firstRelation, $mainTableAlias);
            if ($joinInfo) {
                $joins[] = $joinInfo['join'];
                $selects[] = $joinInfo['select'];
                $relationInfo[$firstRelation] = $joinInfo;
            }
        }
        
        // Costruisce la query finale
        $query = "SELECT " . implode(", ", $selects) . " FROM {$mainTable} AS {$mainTableAlias}";
        if (!empty($joins)) {
            $query .= " " . implode(" ", $joins);
        }
        
        $rows = DB::select($query);
        
        // Separa i risultati JOIN nei modelli corretti
        return static::separateJoinResults($rows, $relationInfo, $mainTableAlias);
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
     * Trova un record per ID con JOIN per le relazioni eager load
     * 
     * @param int $id ID del record
     * @return array Array di modelli con relazioni caricate
     */
    protected static function findWithJoins(int $id): array
    {
        $mainTable = static::getTableName();
        $mainTableAlias = 'main';
        $selects = ["{$mainTableAlias}.*"];
        $joins = [];
        $relationInfo = [];
        
        // Costruisce JOIN per ogni relazione eager load
        foreach (static::$eagerLoad as $relation) {
            $parts = explode('.', $relation);
            $firstRelation = $parts[0];
            
            // Verifica se esiste il metodo relazione
            $sampleModel = new static();
            if (!method_exists($sampleModel, $firstRelation) || !$sampleModel->isRelationMethod($firstRelation)) {
                continue;
            }
            
            // Costruisce il JOIN appropriato
            $joinInfo = static::buildJoinForRelation($firstRelation, $mainTableAlias);
            if ($joinInfo) {
                $joins[] = $joinInfo['join'];
                // Per le relazioni, seleziona i campi con alias espliciti
                $selects[] = $joinInfo['select'];
                $relationInfo[$firstRelation] = $joinInfo;
            }
        }
        
        // Se non ci sono JOIN da fare, usa il metodo normale
        if (empty($joins)) {
            $result = DB::select("SELECT * FROM " . static::getTableName() . " WHERE id = :id", ['id' => $id]);
            $row = $result[0] ?? null;
            if (!$row) {
                return [];
            }
            $model = new static($row);
            // Carica le relazioni usando il metodo normale
            static::eagerLoadRelations([$model]);
            static::$eagerLoad = [];
            return [$model];
        }
        
        // Costruisce la query finale
        $query = "SELECT " . implode(", ", $selects) . " FROM {$mainTable} AS {$mainTableAlias}";
        if (!empty($joins)) {
            $query .= " " . implode(" ", $joins);
        }
        $query .= " WHERE {$mainTableAlias}.id = :id";
        
        $rows = DB::select($query, ['id' => $id]);
        
        // Separa i risultati JOIN nei modelli corretti
        return static::separateJoinResults($rows, $relationInfo, $mainTableAlias);
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
     * Relazione uno-a-molti (hasMany)
     * 
     * @param string $related Nome della classe del modello correlato
     * @param string|null $foreignKey Nome della foreign key (default: nome modello corrente + "_id")
     * @param string $localKey Nome della chiave locale (default: "id")
     * @return array Array di istanze del modello correlato
     */
    protected function hasMany(string $related, ?string $foreignKey = null, string $localKey = 'id'): array
    {
        if ($foreignKey === null) {
            // Estrae il nome del modello corrente (es: "User" -> "user")
            $modelName = $this->getModelName();
            $foreignKey = strtolower($modelName) . '_id';
        }

        $localValue = $this->$localKey;
        if ($localValue === null) {
            return [];
        }

        // Usa il driver del modello correlato (se disponibile) o quello corrente come fallback
        $driver = property_exists($related, 'driver') ? $related::$driver : static::$driver;
        
        if ($driver === 'json') {
            // Per JSON, mantieni il comportamento originale
            $relatedCollection = $this->readRelatedCollection($related);
            if (empty($relatedCollection)) {
                return [];
            }

            $results = [];
            foreach ($relatedCollection as $item) {
                if (isset($item[$foreignKey]) && $item[$foreignKey] === $localValue) {
                    $results[] = new $related($item);
                }
            }
            return $results;
        } else {
            // Per database, usa query diretta con WHERE invece di JOIN
            // (per hasMany non serve JOIN, basta filtrare per foreign key)
            $tableName = $this->getRelatedTableName($related);
            $rows = DB::select(
                "SELECT * FROM {$tableName} WHERE {$foreignKey} = :localValue",
                ['localValue' => $localValue]
            );
            
            return array_map(fn($row) => new $related($row), $rows);
        }
    }

    /**
     * Relazione molti-a-uno (belongsTo)
     * 
     * @param string $related Nome della classe del modello correlato
     * @param string|null $foreignKey Nome della foreign key (default: nome relazione + "_id")
     * @param string $ownerKey Nome della chiave del modello correlato (default: "id")
     * @return mixed|null Istanza del modello correlato o null
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, string $ownerKey = 'id')
    {
        // Per ottenere il nome della relazione, dobbiamo chiamare questo metodo dal contesto del metodo relazione
        // Quindi assumiamo che la foreign key sia passata o derivata dal nome del modello correlato
        if ($foreignKey === null) {
            // Estrae il nome del modello correlato (es: "User" -> "user")
            $relatedName = $this->getModelNameFromClass($related);
            $foreignKey = strtolower($relatedName) . '_id';
        }

        $foreignValue = $this->$foreignKey ?? null;
        if ($foreignValue === null) {
            return null;
        }

        // Usa il driver del modello correlato (se disponibile) o quello corrente come fallback
        $driver = property_exists($related, 'driver') ? $related::$driver : static::$driver;
        
        if ($driver === 'json') {
            // Per JSON, mantieni il comportamento originale
            $relatedCollection = $this->readRelatedCollection($related);
            if (empty($relatedCollection)) {
                return null;
            }

            foreach ($relatedCollection as $item) {
                if (isset($item[$ownerKey]) && $item[$ownerKey] === $foreignValue) {
                    return new $related($item);
                }
            }
            return null;
        } else {
            // Per database, usa query diretta con WHERE invece di leggere tutta la tabella
            $tableName = $this->getRelatedTableName($related);
            $rows = DB::select(
                "SELECT * FROM {$tableName} WHERE {$ownerKey} = :foreignValue",
                ['foreignValue' => $foreignValue]
            );
            
            return !empty($rows) ? new $related($rows[0]) : null;
        }
    }

    /**
     * Relazione uno-a-uno (hasOne)
     * 
     * @param string $related Nome della classe del modello correlato
     * @param string|null $foreignKey Nome della foreign key (default: nome modello corrente + "_id")
     * @param string $localKey Nome della chiave locale (default: "id")
     * @return mixed|null Istanza del modello correlato o null
     */
    protected function hasOne(string $related, ?string $foreignKey = null, string $localKey = 'id')
    {
        if ($foreignKey === null) {
            $modelName = $this->getModelName();
            $foreignKey = strtolower($modelName) . '_id';
        }

        $localValue = $this->$localKey;
        if ($localValue === null) {
            return null;
        }

        // Usa il driver del modello correlato (se disponibile) o quello corrente come fallback
        $driver = property_exists($related, 'driver') ? $related::$driver : static::$driver;
        
        if ($driver === 'json') {
            // Per JSON, mantieni il comportamento originale
            $relatedCollection = $this->readRelatedCollection($related);
            if (empty($relatedCollection)) {
                return null;
            }

            foreach ($relatedCollection as $item) {
                if (isset($item[$foreignKey]) && $item[$foreignKey] === $localValue) {
                    return new $related($item);
                }
            }
            return null;
        } else {
            // Per database, usa query diretta con WHERE e LIMIT 1
            $tableName = $this->getRelatedTableName($related);
            $rows = DB::select(
                "SELECT * FROM {$tableName} WHERE {$foreignKey} = :localValue LIMIT 1",
                ['localValue' => $localValue]
            );
            
            return !empty($rows) ? new $related($rows[0]) : null;
        }
    }

    /**
     * Legge la collection di un modello correlato
     * Supporta sia driver JSON che database
     * 
     * @param string $related Nome della classe del modello correlato
     * @return array Array di record
     */
    private function readRelatedCollection(string $related): array
    {
        // Usa il driver del modello correlato (se disponibile) o quello corrente come fallback
        $driver = property_exists($related, 'driver') ? $related::$driver : static::$driver;
        
        if ($driver === 'json') {
            return JSONDB::read($related::$collection);
        } else {
            $tableName = $this->getRelatedTableName($related);
            return DB::select("SELECT * FROM " . $tableName);
        }
    }

    /**
     * Ottiene il nome della tabella per un modello correlato
     * 
     * @param string $related Nome della classe del modello correlato
     * @return string Nome della tabella
     */
    private function getRelatedTableName(string $related): string
    {
        if (property_exists($related, 'table') && $related::$table !== null) {
            return $related::$table;
        }
        return $related::$collection;
    }

    /**
     * Costruisce il JOIN SQL per una relazione
     * 
     * @param string $relationName Nome della relazione
     * @param string $mainTableAlias Alias della tabella principale
     * @return array|null Array con 'join' e 'select' o null se non può costruire il JOIN
     */
    protected static function buildJoinForRelation(string $relationName, string $mainTableAlias): ?array
    {
        $sampleModel = new static();
        if (!method_exists($sampleModel, $relationName) || !$sampleModel->isRelationMethod($relationName)) {
            return null;
        }

        // Usa reflection per analizzare il metodo relazione
        $reflection = new \ReflectionClass($sampleModel);
        $method = $reflection->getMethod($relationName);
        $method->setAccessible(true);
        
        // Legge il codice sorgente del metodo per capire il tipo di relazione
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $fileLines = file($filename);
        $methodCode = implode('', array_slice($fileLines, $startLine - 1, $endLine - $startLine + 1));
        
        // Determina il tipo di relazione e la classe correlata
        $isBelongsTo = strpos($methodCode, 'belongsTo') !== false;
        $isHasMany = strpos($methodCode, 'hasMany') !== false;
        $isHasOne = strpos($methodCode, 'hasOne') !== false;
        
        if (!$isBelongsTo && !$isHasMany && !$isHasOne) {
            return null;
        }
        
        // Estrae la classe correlata dal codice
        preg_match('/belongsTo\(\s*([^,)]+)/', $methodCode, $belongsToMatch);
        preg_match('/hasMany\(\s*([^,)]+)/', $methodCode, $hasManyMatch);
        preg_match('/hasOne\(\s*([^,)]+)/', $methodCode, $hasOneMatch);
        
        $relatedClass = null;
        if (!empty($belongsToMatch[1])) {
            $relatedClass = trim($belongsToMatch[1], " '\" \t\n\r\0\x0B");
        } elseif (!empty($hasManyMatch[1])) {
            $relatedClass = trim($hasManyMatch[1], " '\" \t\n\r\0\x0B");
        } elseif (!empty($hasOneMatch[1])) {
            $relatedClass = trim($hasOneMatch[1], " '\" \t\n\r\0\x0B");
        }
        
        // Gestisce il caso in cui la classe è referenziata come Post::class
        if ($relatedClass && strpos($relatedClass, '::class') !== false) {
            $relatedClass = trim(str_replace('::class', '', $relatedClass));
            // Se non ha namespace completo, prova ad aggiungere il namespace del modello corrente
            if (strpos($relatedClass, '\\') === false) {
                $currentNamespace = (new \ReflectionClass(static::class))->getNamespaceName();
                $relatedClass = $currentNamespace . '\\' . $relatedClass;
            }
        }
        
        if (!$relatedClass || !class_exists($relatedClass)) {
            return null;
        }
        
        // Ottiene il nome della tabella correlata
        $relatedTable = static::getRelatedTableNameStatic($relatedClass);
        $relatedAlias = $relationName;
        
        // Ottiene i campi della tabella correlata usando reflection
        $relatedReflection = new \ReflectionClass($relatedClass);
        $relatedProperties = $relatedReflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        $relatedFields = [];
        foreach ($relatedProperties as $prop) {
            $propName = $prop->getName();
            // Esclude proprietà di sistema
            if (!in_array($propName, ['collection', 'driver', 'table', 'relations'])) {
                $relatedFields[] = "{$relatedAlias}.{$propName} AS {$relatedAlias}_{$propName}";
            }
        }
        
        // Costruisce il JOIN in base al tipo di relazione
        if ($isBelongsTo) {
            // belongsTo: JOIN sulla foreign key del modello corrente
            $relatedName = static::getModelNameFromClassStatic($relatedClass);
            $foreignKey = strtolower($relatedName) . '_id';
            $join = "LEFT JOIN {$relatedTable} AS {$relatedAlias} ON {$mainTableAlias}.{$foreignKey} = {$relatedAlias}.id";
            $select = implode(", ", $relatedFields);
        } elseif ($isHasMany || $isHasOne) {
            // hasMany/hasOne: JOIN sulla foreign key del modello correlato
            $currentModelName = static::getModelNameFromClassStatic(static::class);
            $foreignKey = strtolower($currentModelName) . '_id';
            $join = "LEFT JOIN {$relatedTable} AS {$relatedAlias} ON {$mainTableAlias}.id = {$relatedAlias}.{$foreignKey}";
            $select = implode(", ", $relatedFields);
        } else {
            return null;
        }
        
        return [
            'join' => $join,
            'select' => $select,
            'relatedClass' => $relatedClass,
            'relatedAlias' => $relatedAlias,
            'isBelongsTo' => $isBelongsTo,
            'isHasMany' => $isHasMany,
            'isHasOne' => $isHasOne
        ];
    }

    /**
     * Ottiene il nome della tabella per un modello correlato (versione statica)
     * 
     * @param string $related Nome della classe del modello correlato
     * @return string Nome della tabella
     */
    protected static function getRelatedTableNameStatic(string $related): string
    {
        if (property_exists($related, 'table') && $related::$table !== null) {
            return $related::$table;
        }
        return $related::$collection;
    }

    /**
     * Estrae il nome del modello da una classe (versione statica)
     * 
     * @param string $class Nome completo della classe
     * @return string Nome del modello
     */
    protected static function getModelNameFromClassStatic(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    /**
     * Separa i risultati JOIN nei modelli corretti
     * 
     * @param array $rows Risultati della query JOIN
     * @param array $relationInfo Informazioni sulle relazioni
     * @param string $mainTableAlias Alias della tabella principale
     * @return array Array di record principali con relazioni caricate
     */
    protected static function separateJoinResults(array $rows, array $relationInfo, string $mainTableAlias): array
    {
        if (empty($rows)) {
            return [];
        }
        
        $results = [];
        $groupedByMainId = [];
        
        // Raggruppa i risultati per ID principale
        foreach ($rows as $row) {
            $mainId = $row['id'] ?? null;
            if ($mainId === null) {
                continue;
            }
            
            if (!isset($groupedByMainId[$mainId])) {
                // Estrae i dati principali (campi senza prefisso di relazione)
                $mainData = [];
                foreach ($row as $key => $value) {
                    // I campi delle relazioni hanno il prefisso: alias_campo
                    $isRelationData = false;
                    foreach ($relationInfo as $relName => $info) {
                        if (strpos($key, $info['relatedAlias'] . '_') === 0) {
                            $isRelationData = true;
                            break;
                        }
                    }
                    if (!$isRelationData) {
                        $mainData[$key] = $value;
                    }
                }
                $groupedByMainId[$mainId] = [
                    'main' => $mainData,
                    'relations' => []
                ];
            }
            
            // Estrae i dati delle relazioni
            foreach ($relationInfo as $relName => $info) {
                $relatedAlias = $info['relatedAlias'];
                $relatedClass = $info['relatedClass'];
                $relatedData = [];
                $hasData = false;
                
                // Estrae i campi con prefisso alias_
                foreach ($row as $key => $value) {
                    $prefix = $relatedAlias . '_';
                    if (strpos($key, $prefix) === 0) {
                        $fieldName = substr($key, strlen($prefix));
                        // Se tutti i campi sono null, la relazione non esiste
                        if ($value !== null) {
                            $hasData = true;
                        }
                        $relatedData[$fieldName] = $value;
                    }
                }
                
                // Inizializza la relazione se non esiste ancora
                if (!isset($groupedByMainId[$mainId]['relations'][$relName])) {
                    if ($info['isHasMany']) {
                        $groupedByMainId[$mainId]['relations'][$relName] = [];
                    } else {
                        $groupedByMainId[$mainId]['relations'][$relName] = null;
                    }
                }
                
                // Se la relazione ha dati, aggiungili
                if ($hasData && !empty($relatedData)) {
                    // Per hasMany, raggruppa più record
                    if ($info['isHasMany']) {
                        // Verifica se questo record correlato è già stato aggiunto
                        $relatedId = $relatedData['id'] ?? null;
                        $alreadyAdded = false;
                        if ($relatedId !== null) {
                            foreach ($groupedByMainId[$mainId]['relations'][$relName] as $existing) {
                                if (is_array($existing) && ($existing['id'] ?? null) === $relatedId) {
                                    $alreadyAdded = true;
                                    break;
                                }
                            }
                        }
                        if (!$alreadyAdded) {
                            $groupedByMainId[$mainId]['relations'][$relName][] = $relatedData;
                        }
                    } else {
                        // Per belongsTo e hasOne, un solo record (solo se non è già stato impostato)
                        if ($groupedByMainId[$mainId]['relations'][$relName] === null) {
                            $groupedByMainId[$mainId]['relations'][$relName] = $relatedData;
                        }
                    }
                }
            }
        }
        
        // Costruisce i modelli con le relazioni caricate
        foreach ($groupedByMainId as $data) {
            $model = new static($data['main']);
            
            // Carica le relazioni nella cache del modello
            foreach ($data['relations'] as $relName => $relData) {
                $relInfo = $relationInfo[$relName];
                $relatedClass = $relInfo['relatedClass'];
                
                if ($relInfo['isHasMany']) {
                    // Array di modelli correlati (può essere vuoto)
                    if (is_array($relData) && !empty($relData)) {
                        $relatedModels = array_map(fn($row) => new $relatedClass($row), $relData);
                        $model->relations[$relName] = $relatedModels;
                    } else {
                        // Array vuoto se non ci sono dati
                        $model->relations[$relName] = [];
                    }
                } else {
                    // Singolo modello correlato (può essere null)
                    if ($relData !== null && is_array($relData)) {
                        $model->relations[$relName] = new $relatedClass($relData);
                    } else {
                        // null se non ci sono dati
                        $model->relations[$relName] = null;
                    }
                }
            }
            
            $results[] = $model;
        }
        
        // Se è un singolo risultato (find), restituisce solo il primo elemento
        // Ma per all() restituisce tutti
        return $results;
    }

    /**
     * Estrae il nome del modello dalla classe corrente
     * 
     * @return string Nome del modello (es: "User" da "App\Models\User")
     */
    private function getModelName(): string
    {
        $className = get_class($this);
        $parts = explode('\\', $className);
        return end($parts);
    }

    /**
     * Estrae il nome del modello da una classe
     * 
     * @param string $class Nome completo della classe
     * @return string Nome del modello (es: "User" da "App\Models\User")
     */
    private function getModelNameFromClass(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    /**
     * Metodo magico per accedere alle relazioni come proprietà dinamiche
     * 
     * @param string $name Nome della proprietà/relazione
     * @return mixed Valore della proprietà o risultato della relazione
     */
    public function __get(string $name)
    {
        // Se la relazione è già caricata nella cache, restituiscila
        if (isset($this->relations[$name])) {
            return $this->relations[$name];
        }

        // Verifica se esiste un metodo relazione con questo nome
        if (method_exists($this, $name) && $this->isRelationMethod($name)) {
            $relation = $this->$name();
            // Salva nella cache
            $this->relations[$name] = $relation;
            return $relation;
        }

        // Se non è una relazione, prova ad accedere alla proprietà normalmente
        // Questo gestisce anche le proprietà protette/pubbliche esistenti
        if (property_exists($this, $name)) {
            return $this->$name;
        }

        return null;
    }

    /**
     * Metodo magico per intercettare chiamate a metodi quando viene usato with()->find() o with()->all()
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

        // Se il metodo non esiste, lancia un'eccezione
        throw new \BadMethodCallException("Metodo {$method} non trovato nella classe " . get_class($this));
    }

    /**
     * Verifica se un metodo è un metodo relazione
     * 
     * @param string $method Nome del metodo
     * @return bool True se è un metodo relazione
     */
    protected function isRelationMethod(string $method): bool
    {
        // Verifica che il metodo esista e sia protetto
        $reflection = new \ReflectionClass($this);
        if (!$reflection->hasMethod($method)) {
            return false;
        }

        $methodReflection = $reflection->getMethod($method);
        
        // Verifica che sia protetto (come le relazioni dovrebbero essere)
        if (!$methodReflection->isProtected()) {
            return false;
        }

        // Verifica che non sia statico
        if ($methodReflection->isStatic()) {
            return false;
        }

        return true;
    }

    /**
     * Carica le relazioni con eager loading per un array di modelli
     * Ottimizzato per usare query batch quando il driver è 'database'
     * 
     * @param array $models Array di modelli
     * @return void
     */
    protected static function eagerLoadRelations(array $models): void
    {
        if (empty($models) || empty(static::$eagerLoad)) {
            return;
        }

        foreach (static::$eagerLoad as $relation) {
            // Gestisce relazioni annidate (es: 'posts.user')
            $parts = explode('.', $relation);
            $firstRelation = $parts[0];
            
            // Verifica se tutti i modelli hanno questo metodo relazione
            $hasRelation = false;
            foreach ($models as $model) {
                if (method_exists($model, $firstRelation) && $model->isRelationMethod($firstRelation)) {
                    $hasRelation = true;
                    break;
                }
            }
            
            if (!$hasRelation) {
                continue;
            }
            
            // Carica la relazione per tutti i modelli
            // Le relazioni sono già ottimizzate per usare WHERE invece di leggere tutta la tabella
            foreach ($models as $model) {
                if (method_exists($model, $firstRelation) && $model->isRelationMethod($firstRelation)) {
                    $relationResult = $model->$firstRelation();
                    $model->relations[$firstRelation] = $relationResult;
                    
                    // Se ci sono relazioni annidate, caricale ricorsivamente
                    if (count($parts) > 1) {
                        $nestedRelations = implode('.', array_slice($parts, 1));
                        $nestedModels = is_array($relationResult) ? $relationResult : [$relationResult];
                        $nestedModels = array_filter($nestedModels, function($m) { return $m !== null; });
                        
                        if (!empty($nestedModels)) {
                            $originalEagerLoad = static::$eagerLoad;
                            static::$eagerLoad = [$nestedRelations];
                            static::eagerLoadRelations($nestedModels);
                            static::$eagerLoad = $originalEagerLoad;
                        }
                    }
                }
            }
        }
    }


    /**
     * Carica le relazioni specificate su questa istanza
     * 
     * @param string|array $relations Nome della relazione o array di nomi
     * @return $this
     */
    public function load(string|array $relations): static
    {
        $relations = is_array($relations) ? $relations : [$relations];
        
        foreach ($relations as $relation) {
            // Gestisce relazioni annidate (es: 'posts.user')
            $parts = explode('.', $relation);
            $firstRelation = $parts[0];
            
            if (method_exists($this, $firstRelation) && $this->isRelationMethod($firstRelation)) {
                $relationResult = $this->$firstRelation();
                $this->relations[$firstRelation] = $relationResult;
                
                // Se ci sono relazioni annidate, caricale ricorsivamente
                if (count($parts) > 1) {
                    $nestedRelations = implode('.', array_slice($parts, 1));
                    $nestedModels = is_array($relationResult) ? $relationResult : [$relationResult];
                    // Rimuove null dai risultati
                    $nestedModels = array_filter($nestedModels, function($m) { return $m !== null; });
                    
                    if (!empty($nestedModels)) {
                        foreach ($nestedModels as $nestedModel) {
                            $nestedModel->load($nestedRelations);
                        }
                    }
                }
            }
        }
        
        return $this;
    }

    /**
     * Carica le relazioni specificate solo se non sono già caricate
     * 
     * @param string|array $relations Nome della relazione o array di nomi
     * @return $this
     */
    public function loadMissing(string|array $relations): static
    {
        $relations = is_array($relations) ? $relations : [$relations];
        
        foreach ($relations as $relation) {
            // Estrae il nome della prima relazione (prima del punto se ci sono relazioni annidate)
            $parts = explode('.', $relation);
            $firstRelation = $parts[0];
            
            // Carica solo se non è già nella cache
            if (!isset($this->relations[$firstRelation])) {
                $this->load($relation);
            }
        }
        
        return $this;
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
