<?php

namespace App\Models;

use App\Traits\WithValidate;
use App\Database\DB;

class User extends BaseModel {

    use WithValidate;

    public ?string $name = null;
    public ?string $email = null;

    /**
     * Nome della collection
     */
    protected static ?string $table = "users";

    public function __construct(array $data = []) {
        parent::__construct($data);
    }

    protected static function validationRules(): array {
        return [
            "name" => ["required", "min:2", "max:100"],
            "email" => ["sometimes", "required", "email", function($field, $value, $data) {
                if($value !== null && $value !== '') {
                    $user = static::findByEmail($value);
                    $isSameUser = $user !== null && isset($data['id']) && $user->id === (int)$data['id'];
                    return $user !== null && !$isSameUser ? "L'email $value è già in uso" : null;
                }
            }]
        ];
    }

    public static function findByEmail(string $email): ?static
    {
        if(static::$driver === 'json') {
            $collection = self::all();
            return array_find($collection, fn($user) => $user->email === $email);
        }
        $result = DB::select("SELECT * FROM " . static::getTableName() . " WHERE email = :email", ['email' => $email]);
        return $result ? new static($result[0]) : null;
    }

}