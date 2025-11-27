<?php

namespace App\Models;

use App\Utils\DB;

abstract class BaseModel
{
    protected ?int $id = null;
    protected ?string $created_at = null;
    protected ?string $updated_at = null;

    protected static string $collection;

    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
        $this->updated_at = $data['updated_at'] ?? null;
    }

    public static function all(): array
    {
        $collection = DB::read(static::$collection);
        if (empty($collection)) {
            return [];
        }
        return array_map(function ($item) {
            return new static($item);
        }, $collection);
    }

    public static function find(int $id): ?static
    {
        $collection = static::all();
        return array_find($collection, function ($item) use ($id) {
            return $item->id === $id;
        });
    }

    public static function create(array $data): ?static
    {
        try {
            $model = new static($data);
            $model->save();
            return $model;
        } catch (\Exception $e) {
            throw new \Exception('Errore nel salvataggio del record: ' . $e->getMessage());
        }
    }

    public static function update(int $id, array $data): ?static
    {
        $model = static::find($id);
        if (empty($model)) {
            throw new \Exception("Record $id non trovato nella collection " . static::$collection);
        }
        // Unisce i dati esistenti con quelli nuovi
        $data = array_merge($model->toArray(), $data);
        // Crea un nuovo modello con i dati aggiornati e salva
        $model = new static($data);
        $model->save();
        return $model;
    }

    public static function delete(int $id): ?bool
    {
        $collection = static::all();
        $found = false;
        foreach ($collection as $item) {
            if ($item->id === $id) {
                $found = true;
                // Filtra la collection rimuovendo l'elemento con l'id specificato
                $filteredCollection = array_filter($collection, function ($item) use ($id) {
                    return $item->id !== $id;
                });
                // Converte gli oggetti in array e ripristina le chiavi sequenziali
                $collectionArray = array_values(array_map(function ($item) {
                    return $item->toArray();
                }, $filteredCollection));
                return DB::write(static::$collection, $collectionArray);
            }
        }
        if (!$found) {
            throw new \Exception("Record $id non trovato nella collection " . static::$collection);
        }
        return null;
    }

    public function save(): void
    {
        try {
            $collectionArray = DB::read(static::$collection);
            $isNew = empty($this->id);

            if ($isNew) {
                // Se l'id è vuoto, creo un nuovo id (inserimento di un nuovo record)
                $this->id = DB::getNextId(static::$collection);
                $this->created_at = date('Y-m-d H:i:s');
                $this->updated_at = date('Y-m-d H:i:s');
                $collectionArray[] = $this->toArray();
            } else {
                // Se l'id è presente, aggiorno il record (mantiene created_at esistente)
                if (empty($this->created_at)) {
                    $this->created_at = date('Y-m-d H:i:s');
                }
                $this->updated_at = date('Y-m-d H:i:s');
                $collectionArray = array_map(function ($item) {
                    if ($item['id'] === $this->id) {
                        return $this->toArray();
                    }
                    return $item;
                }, $collectionArray);
            }
            DB::write(static::$collection, $collectionArray);
        } catch (\Exception $e) {
            throw new \Exception('Errore nel salvataggio del record: ' . $e->getMessage());
        }
    }

    public function toArray(): array
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties();

        $result = [];
        foreach ($properties as $property) {
            if ($property->getName() === "collection") {
                continue;
            }
            $result[$property->getName()] = $property->getValue($this);
        }

        return $result;
    }
}
