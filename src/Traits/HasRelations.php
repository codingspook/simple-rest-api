<?php

namespace App\Traits;

use App\Database\JSONDB;
use App\Database\DB;

/**
 * Trait completo per gestire tutte le relazioni
 * Include: relazioni base, many-to-many, eager loading e cache
 * 
 * @property-read array $relations Cache delle relazioni caricate
 * @property-read static $eagerLoad Relazioni da caricare con eager loading (proprietà statica del BaseModel)
 * @property-read string $driver Driver database da utilizzare (proprietà statica del BaseModel)
 */
trait HasRelations
{
    // ============================================================================
    // RELAZIONI BASE (belongsTo, hasMany, hasOne)
    // ============================================================================

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
        $driver = $this->getRelatedDriver($related);
        
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
        $driver = $this->getRelatedDriver($related);
        
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
        $driver = $this->getRelatedDriver($related);
        
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

    // ============================================================================
    // RELAZIONI MANY-TO-MANY (belongsToMany, attach, detach, sync, toggle)
    // ============================================================================

    /**
     * Relazione molti-a-molti (belongsToMany)
     * 
     * @param string $related Nome della classe del modello correlato
     * @param string|null $table Nome della tabella pivot (default: nome_modello1_nome_modello2 in ordine alfabetico)
     * @param string|null $foreignPivotKey Nome della foreign key del modello corrente nella tabella pivot (default: nome_modello_id)
     * @param string|null $relatedPivotKey Nome della foreign key del modello correlato nella tabella pivot (default: nome_modello_correlato_id)
     * @param string $parentKey Nome della chiave del modello corrente (default: "id")
     * @param string $relatedKey Nome della chiave del modello correlato (default: "id")
     * @return array Array di istanze del modello correlato
     */
    protected function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        string $parentKey = 'id',
        string $relatedKey = 'id'
    ): array {
        $localValue = $this->$parentKey;
        if ($localValue === null) {
            return [];
        }

        // Determina il nome della tabella pivot
        if ($table === null) {
            $currentModelName = $this->getModelName();
            $relatedModelName = $this->getModelNameFromClass($related);
            $tables = [strtolower($currentModelName), strtolower($relatedModelName)];
            sort($tables);
            $table = implode('_', $tables);
        }

        // Determina le foreign key nella tabella pivot
        if ($foreignPivotKey === null) {
            $currentModelName = $this->getModelName();
            $foreignPivotKey = strtolower($currentModelName) . '_id';
        }

        if ($relatedPivotKey === null) {
            $relatedModelName = $this->getModelNameFromClass($related);
            $relatedPivotKey = strtolower($relatedModelName) . '_id';
        }

        // Usa il driver del modello correlato (se disponibile) o quello corrente come fallback
        $driver = $this->getRelatedDriver($related);
        
        if ($driver === 'json') {
            // Per JSON, legge dalla collection pivot
            $pivotCollection = JSONDB::read($table);
            if (empty($pivotCollection)) {
                return [];
            }

            // Trova tutti i record pivot che corrispondono al modello corrente
            $relatedIds = [];
            foreach ($pivotCollection as $pivotItem) {
                if (isset($pivotItem[$foreignPivotKey]) && $pivotItem[$foreignPivotKey] === $localValue) {
                    if (isset($pivotItem[$relatedPivotKey])) {
                        $relatedIds[] = $pivotItem[$relatedPivotKey];
                    }
                }
            }

            if (empty($relatedIds)) {
                return [];
            }

            // Legge i modelli correlati
            $relatedCollection = $this->readRelatedCollection($related);
            $results = [];
            foreach ($relatedCollection as $item) {
                if (isset($item[$relatedKey]) && in_array($item[$relatedKey], $relatedIds)) {
                    $results[] = new $related($item);
                }
            }
            return $results;
        } else {
            // Per database, usa JOIN attraverso la tabella pivot
            $relatedTable = $this->getRelatedTableName($related);
            $query = "
                SELECT {$relatedTable}.* 
                FROM {$relatedTable}
                INNER JOIN {$table} ON {$relatedTable}.{$relatedKey} = {$table}.{$relatedPivotKey}
                WHERE {$table}.{$foreignPivotKey} = :localValue
            ";
            
            $rows = DB::select($query, ['localValue' => $localValue]);
            return array_map(fn($row) => new $related($row), $rows);
        }
    }

    /**
     * Aggiunge record alla tabella pivot (many-to-many)
     * 
     * @param string $related Nome della classe del modello correlato
     * @param int|array $ids ID o array di ID da aggiungere
     * @param string|null $table Nome della tabella pivot
     * @param string|null $foreignPivotKey Nome della foreign key del modello corrente
     * @param string|null $relatedPivotKey Nome della foreign key del modello correlato
     * @return void
     */
    public function attach(
        string $related,
        int|array $ids,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null
    ): void {
        $localValue = $this->id;
        if ($localValue === null) {
            throw new \Exception("Il modello deve essere salvato prima di usare attach()");
        }

        // Normalizza gli ID in array
        $ids = is_array($ids) ? $ids : [$ids];

        // Determina il nome della tabella pivot
        if ($table === null) {
            $currentModelName = $this->getModelName();
            $relatedModelName = $this->getModelNameFromClass($related);
            $tables = [strtolower($currentModelName), strtolower($relatedModelName)];
            sort($tables);
            $table = implode('_', $tables);
        }

        // Determina le foreign key nella tabella pivot
        if ($foreignPivotKey === null) {
            $currentModelName = $this->getModelName();
            $foreignPivotKey = strtolower($currentModelName) . '_id';
        }

        if ($relatedPivotKey === null) {
            $relatedModelName = $this->getModelNameFromClass($related);
            $relatedPivotKey = strtolower($relatedModelName) . '_id';
        }

        // Usa il driver del modello correlato (se disponibile) o quello corrente come fallback
        $driver = $this->getRelatedDriver($related);

        if ($driver === 'json') {
            // Per JSON, aggiunge record alla collection pivot
            $pivotCollection = JSONDB::read($table);
            if (!is_array($pivotCollection)) {
                $pivotCollection = [];
            }

            foreach ($ids as $id) {
                // Verifica se il record esiste già
                $exists = false;
                foreach ($pivotCollection as $pivotItem) {
                    if (isset($pivotItem[$foreignPivotKey]) && $pivotItem[$foreignPivotKey] === $localValue &&
                        isset($pivotItem[$relatedPivotKey]) && $pivotItem[$relatedPivotKey] === $id) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $pivotCollection[] = [
                        $foreignPivotKey => $localValue,
                        $relatedPivotKey => $id
                    ];
                }
            }

            JSONDB::write($table, $pivotCollection);
        } else {
            // Per database, inserisce record nella tabella pivot
            foreach ($ids as $id) {
                // Verifica se il record esiste già
                $existing = DB::select(
                    "SELECT * FROM {$table} WHERE {$foreignPivotKey} = :localValue AND {$relatedPivotKey} = :id",
                    ['localValue' => $localValue, 'id' => $id]
                );

                if (empty($existing)) {
                    DB::insert(
                        "INSERT INTO {$table} ({$foreignPivotKey}, {$relatedPivotKey}) VALUES (:localValue, :id)",
                        ['localValue' => $localValue, 'id' => $id]
                    );
                }
            }
        }

        // Invalida la cache della relazione (come Laravel)
        $relationName = $this->getRelationNameForManyToMany($related);
        unset($this->relations[$relationName]);
    }

    /**
     * Rimuove record dalla tabella pivot (many-to-many)
     * 
     * @param string $related Nome della classe del modello correlato
     * @param int|array|null $ids ID o array di ID da rimuovere (null = rimuove tutti)
     * @param string|null $table Nome della tabella pivot
     * @param string|null $foreignPivotKey Nome della foreign key del modello corrente
     * @param string|null $relatedPivotKey Nome della foreign key del modello correlato
     * @return void
     */
    public function detach(
        string $related,
        int|array|null $ids = null,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null
    ): void {
        $localValue = $this->id;
        if ($localValue === null) {
            throw new \Exception("Il modello deve essere salvato prima di usare detach()");
        }

        // Determina il nome della tabella pivot
        if ($table === null) {
            $currentModelName = $this->getModelName();
            $relatedModelName = $this->getModelNameFromClass($related);
            $tables = [strtolower($currentModelName), strtolower($relatedModelName)];
            sort($tables);
            $table = implode('_', $tables);
        }

        // Determina le foreign key nella tabella pivot
        if ($foreignPivotKey === null) {
            $currentModelName = $this->getModelName();
            $foreignPivotKey = strtolower($currentModelName) . '_id';
        }

        if ($relatedPivotKey === null) {
            $relatedModelName = $this->getModelNameFromClass($related);
            $relatedPivotKey = strtolower($relatedModelName) . '_id';
        }

        // Usa il driver del modello correlato (se disponibile) o quello corrente come fallback
        $driver = $this->getRelatedDriver($related);

        if ($driver === 'json') {
            // Per JSON, rimuove record dalla collection pivot
            $pivotCollection = JSONDB::read($table);
            if (!is_array($pivotCollection)) {
                return;
            }

            if ($ids === null) {
                // Rimuove tutti i record per questo modello
                $pivotCollection = array_filter($pivotCollection, function($item) use ($foreignPivotKey, $localValue) {
                    return !isset($item[$foreignPivotKey]) || $item[$foreignPivotKey] !== $localValue;
                });
            } else {
                // Rimuove solo gli ID specificati
                $ids = is_array($ids) ? $ids : [$ids];
                $pivotCollection = array_filter($pivotCollection, function($item) use ($foreignPivotKey, $relatedPivotKey, $localValue, $ids) {
                    if (!isset($item[$foreignPivotKey]) || $item[$foreignPivotKey] !== $localValue) {
                        return true; // Mantieni record di altri modelli
                    }
                    return !isset($item[$relatedPivotKey]) || !in_array($item[$relatedPivotKey], $ids);
                });
            }

            JSONDB::write($table, array_values($pivotCollection));
        } else {
            // Per database, elimina record dalla tabella pivot
            if ($ids === null) {
                // Rimuove tutti i record per questo modello
                DB::delete(
                    "DELETE FROM {$table} WHERE {$foreignPivotKey} = :localValue",
                    ['localValue' => $localValue]
                );
            } else {
                // Rimuove solo gli ID specificati
                $ids = is_array($ids) ? $ids : [$ids];
                $placeholders = [];
                $bindings = ['localValue' => $localValue];
                foreach ($ids as $index => $id) {
                    $key = "id{$index}";
                    $placeholders[] = ":{$key}";
                    $bindings[$key] = $id;
                }
                $query = "DELETE FROM {$table} WHERE {$foreignPivotKey} = :localValue AND {$relatedPivotKey} IN (" . implode(',', $placeholders) . ")";
                DB::delete($query, $bindings);
            }
        }

        // Invalida la cache della relazione (come Laravel)
        $relationName = $this->getRelationNameForManyToMany($related);
        unset($this->relations[$relationName]);
    }

    /**
     * Sincronizza i record nella tabella pivot (many-to-many)
     * Rimuove tutti i record esistenti e aggiunge solo quelli specificati
     * 
     * @param string $related Nome della classe del modello correlato
     * @param array $ids Array di ID da sincronizzare
     * @param string|null $table Nome della tabella pivot
     * @param string|null $foreignPivotKey Nome della foreign key del modello corrente
     * @param string|null $relatedPivotKey Nome della foreign key del modello correlato
     * @return void
     */
    public function sync(
        string $related,
        array $ids,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null
    ): void {
        // Prima rimuove tutti i record esistenti
        $this->detach($related, null, $table, $foreignPivotKey, $relatedPivotKey);
        
        // Poi aggiunge solo quelli specificati
        if (!empty($ids)) {
            $this->attach($related, $ids, $table, $foreignPivotKey, $relatedPivotKey);
        }
        
        // Nota: attach() e detach() invalidano già la cache della relazione
        // Come Laravel, non ricarica automaticamente - devi chiamare load() se necessario
    }

    /**
     * Aggiunge o rimuove record dalla tabella pivot (many-to-many)
     * Se il record esiste, lo rimuove; se non esiste, lo aggiunge
     * 
     * @param string $related Nome della classe del modello correlato
     * @param int|array $ids ID o array di ID da aggiungere/rimuovere
     * @param string|null $table Nome della tabella pivot
     * @param string|null $foreignPivotKey Nome della foreign key del modello corrente
     * @param string|null $relatedPivotKey Nome della foreign key del modello correlato
     * @return void
     */
    public function toggle(
        string $related,
        int|array $ids,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null
    ): void {
        $localValue = $this->id;
        if ($localValue === null) {
            throw new \Exception("Il modello deve essere salvato prima di usare toggle()");
        }

        // Normalizza gli ID in array
        $ids = is_array($ids) ? $ids : [$ids];

        // Determina il nome della tabella pivot
        if ($table === null) {
            $currentModelName = $this->getModelName();
            $relatedModelName = $this->getModelNameFromClass($related);
            $tables = [strtolower($currentModelName), strtolower($relatedModelName)];
            sort($tables);
            $table = implode('_', $tables);
        }

        // Determina le foreign key nella tabella pivot
        if ($foreignPivotKey === null) {
            $currentModelName = $this->getModelName();
            $foreignPivotKey = strtolower($currentModelName) . '_id';
        }

        if ($relatedPivotKey === null) {
            $relatedModelName = $this->getModelNameFromClass($related);
            $relatedPivotKey = strtolower($relatedModelName) . '_id';
        }

        // Usa il driver del modello correlato (se disponibile) o quello corrente come fallback
        $driver = $this->getRelatedDriver($related);

        $toAttach = [];
        $toDetach = [];

        if ($driver === 'json') {
            // Per JSON, verifica quali record esistono già
            $pivotCollection = JSONDB::read($table);
            if (!is_array($pivotCollection)) {
                $pivotCollection = [];
            }

            foreach ($ids as $id) {
                $exists = false;
                foreach ($pivotCollection as $pivotItem) {
                    if (isset($pivotItem[$foreignPivotKey]) && $pivotItem[$foreignPivotKey] === $localValue &&
                        isset($pivotItem[$relatedPivotKey]) && $pivotItem[$relatedPivotKey] === $id) {
                        $exists = true;
                        break;
                    }
                }

                if ($exists) {
                    $toDetach[] = $id;
                } else {
                    $toAttach[] = $id;
                }
            }
        } else {
            // Per database, verifica quali record esistono già
            foreach ($ids as $id) {
                $existing = DB::select(
                    "SELECT * FROM {$table} WHERE {$foreignPivotKey} = :localValue AND {$relatedPivotKey} = :id",
                    ['localValue' => $localValue, 'id' => $id]
                );

                if (!empty($existing)) {
                    $toDetach[] = $id;
                } else {
                    $toAttach[] = $id;
                }
            }
        }

        // Esegue attach e detach
        if (!empty($toAttach)) {
            $this->attach($related, $toAttach, $table, $foreignPivotKey, $relatedPivotKey);
        }
        if (!empty($toDetach)) {
            $this->detach($related, $toDetach, $table, $foreignPivotKey, $relatedPivotKey);
        }
        
        // Nota: attach() e detach() invalidano già la cache della relazione
        // Come Laravel, non ricarica automaticamente - devi chiamare load() se necessario
    }

    // ============================================================================
    // EAGER LOADING E JOIN
    // ============================================================================

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
            // PHP 8.1+ non richiede setAccessible() - i metodi sono accessibili per default
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
     * Filtra i record con JOIN per le relazioni eager load
     * 
     * @param string $column Nome della colonna
     * @param string $operator Operatore SQL
     * @param mixed $value Valore da confrontare
     * @return array Array di modelli con relazioni caricate
     */
    protected static function whereWithJoins(string $column, string $operator, mixed $value): array
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
                $selects[] = $joinInfo['select'];
                $relationInfo[$firstRelation] = $joinInfo;
            }
        }
        
        // Se non ci sono JOIN da fare, usa il metodo normale
        if (empty($joins)) {
            // Query normale senza JOIN
            if (in_array($operator, ['IN', 'NOT IN'])) {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("Il valore per gli operatori IN/NOT IN deve essere un array");
                }
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $sql = "SELECT * FROM " . static::getTableName() . " WHERE {$column} {$operator} ({$placeholders})";
                $rows = DB::select($sql, array_values($value));
            } else {
                $sql = "SELECT * FROM " . static::getTableName() . " WHERE {$column} {$operator} :value";
                $rows = DB::select($sql, ['value' => $value]);
            }
            $models = array_map(fn($row) => new static($row), $rows);
            // Carica le relazioni usando il metodo normale
            static::eagerLoadRelations($models);
            static::$eagerLoad = [];
            return $models;
        }
        
        // Costruisce la query finale con JOIN
        $query = "SELECT " . implode(", ", $selects) . " FROM {$mainTable} AS {$mainTableAlias}";
        if (!empty($joins)) {
            $query .= " " . implode(" ", $joins);
        }
        
        // Aggiunge la condizione WHERE
        $bindings = [];
        if (in_array($operator, ['IN', 'NOT IN'])) {
            if (!is_array($value)) {
                throw new \InvalidArgumentException("Il valore per gli operatori IN/NOT IN deve essere un array");
            }
            $placeholders = [];
            foreach ($value as $index => $val) {
                $key = "value{$index}";
                $placeholders[] = ":{$key}";
                $bindings[$key] = $val;
            }
            $query .= " WHERE {$mainTableAlias}.{$column} {$operator} (" . implode(',', $placeholders) . ")";
        } else {
            $query .= " WHERE {$mainTableAlias}.{$column} {$operator} :value";
            $bindings['value'] = $value;
        }
        
        $rows = DB::select($query, $bindings);
        
        // Separa i risultati JOIN nei modelli corretti
        return static::separateJoinResults($rows, $relationInfo, $mainTableAlias);
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
        // PHP 8.1+ non richiede setAccessible() - i metodi sono accessibili per default
        
        // Legge il codice sorgente del metodo per capire il tipo di relazione
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $fileLines = file($filename);
        $methodCode = implode('', array_slice($fileLines, $startLine - 1, $endLine - $startLine + 1));
        
        // Determina il tipo di relazione e la classe correlata
        $isBelongsTo = strpos($methodCode, 'belongsTo') !== false && strpos($methodCode, 'belongsToMany') === false;
        $isBelongsToMany = strpos($methodCode, 'belongsToMany') !== false;
        $isHasMany = strpos($methodCode, 'hasMany') !== false;
        $isHasOne = strpos($methodCode, 'hasOne') !== false;
        
        if (!$isBelongsTo && !$isBelongsToMany && !$isHasMany && !$isHasOne) {
            return null;
        }
        
        // Estrae la classe correlata dal codice
        preg_match('/belongsToMany\(\s*([^,)]+)/', $methodCode, $belongsToManyMatch);
        preg_match('/belongsTo\(\s*([^,)]+)/', $methodCode, $belongsToMatch);
        preg_match('/hasMany\(\s*([^,)]+)/', $methodCode, $hasManyMatch);
        preg_match('/hasOne\(\s*([^,)]+)/', $methodCode, $hasOneMatch);
        
        $relatedClass = null;
        if (!empty($belongsToManyMatch[1])) {
            $relatedClass = trim($belongsToManyMatch[1], " '\" \t\n\r\0\x0B");
        } elseif (!empty($belongsToMatch[1])) {
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
        } elseif ($isBelongsToMany) {
            // belongsToMany: JOIN attraverso la tabella pivot
            $currentModelName = static::getModelNameFromClassStatic(static::class);
            $relatedModelName = static::getModelNameFromClassStatic($relatedClass);
            $tables = [strtolower($currentModelName), strtolower($relatedModelName)];
            sort($tables);
            $pivotTable = implode('_', $tables);
            $pivotAlias = 'pivot_' . $relationName;
            
            $foreignPivotKey = strtolower($currentModelName) . '_id';
            $relatedPivotKey = strtolower($relatedModelName) . '_id';
            
            $join = "LEFT JOIN {$pivotTable} AS {$pivotAlias} ON {$mainTableAlias}.id = {$pivotAlias}.{$foreignPivotKey} " .
                   "LEFT JOIN {$relatedTable} AS {$relatedAlias} ON {$pivotAlias}.{$relatedPivotKey} = {$relatedAlias}.id";
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
            'isBelongsToMany' => $isBelongsToMany,
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
                    if ($info['isHasMany'] || ($info['isBelongsToMany'] ?? false)) {
                        $groupedByMainId[$mainId]['relations'][$relName] = [];
                    } else {
                        $groupedByMainId[$mainId]['relations'][$relName] = null;
                    }
                }
                
                // Se la relazione ha dati, aggiungili
                if ($hasData && !empty($relatedData)) {
                    // Per hasMany e belongsToMany, raggruppa più record
                    if ($info['isHasMany'] || ($info['isBelongsToMany'] ?? false)) {
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
                
                if ($relInfo['isHasMany'] || ($relInfo['isBelongsToMany'] ?? false)) {
                    // Array di modelli correlati (può essere vuoto) - per hasMany e belongsToMany
                    if (is_array($relData) && !empty($relData)) {
                        $relatedModels = array_map(fn($row) => new $relatedClass($row), $relData);
                        $model->relations[$relName] = $relatedModels;
                    } else {
                        // Array vuoto se non ci sono dati
                        $model->relations[$relName] = [];
                    }
                } else {
                    // Singolo modello correlato (può essere null) - per belongsTo e hasOne
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

    // ============================================================================
    // CACHE E ACCESSO ALLE RELAZIONI
    // ============================================================================

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

    // ============================================================================
    // METODI DI UTILITÀ
    // ============================================================================

    /**
     * Ottiene il driver da una classe correlata in modo sicuro
     * 
     * @param string $related Nome della classe del modello correlato
     * @return string Nome del driver ('json' o 'database')
     */
    protected function getRelatedDriver(string $related): string
    {
        // Usa reflection per verificare e accedere alla proprietà statica in modo sicuro
        // PHP 8.1+ non richiede setAccessible() - le proprietà sono accessibili per default
        if (class_exists($related)) {
            $reflection = new \ReflectionClass($related);
            if ($reflection->hasProperty('driver')) {
                $property = $reflection->getProperty('driver');
                return $property->getValue();
            }
        }
        
        // Fallback al driver del modello corrente
        return static::$driver;
    }

    /**
     * Legge la collection di un modello correlato
     * Supporta sia driver JSON che database
     * 
     * @param string $related Nome della classe del modello correlato
     * @return array Array di record
     */
    protected function readRelatedCollection(string $related): array
    {
        // Usa il driver del modello correlato (se disponibile) o quello corrente come fallback
        $driver = $this->getRelatedDriver($related);
        
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
    protected function getRelatedTableName(string $related): string
    {
        if (property_exists($related, 'table') && $related::$table !== null) {
            return $related::$table;
        }
        return $related::$collection;
    }

    /**
     * Estrae il nome del modello dalla classe corrente
     * 
     * @return string Nome del modello (es: "User" da "App\Models\User")
     */
    protected function getModelName(): string
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
    protected function getModelNameFromClass(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    /**
     * Ottiene il nome della relazione many-to-many per invalidare la cache
     * 
     * @param string $related Nome della classe del modello correlato
     * @return string Nome della relazione (tentativo)
     */
    protected function getRelationNameForManyToMany(string $related): string
    {
        // Prova a trovare il nome della relazione guardando i metodi del modello
        $reflection = new \ReflectionClass($this);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PROTECTED);
        
        foreach ($methods as $method) {
            if ($method->isStatic()) {
                continue;
            }
            
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();
            $fileLines = file($filename);
            $methodCode = implode('', array_slice($fileLines, $startLine - 1, $endLine - $startLine + 1));
            
            if (strpos($methodCode, 'belongsToMany') !== false && strpos($methodCode, $related) !== false) {
                return $method->getName();
            }
        }
        
        // Fallback: usa il nome del modello correlato in lowercase
        return strtolower($this->getModelNameFromClass($related)) . 's';
    }
}
