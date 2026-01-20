<?php

namespace App\Traits;

trait WithValidate
{
    /**
     * Restituisce le regole di validazione per i campi del modello
     * 
     * Deve essere sovrascritto nella classe che usa il trait.
     * Le regole possono essere stringhe (es: 'required', 'min:2', 'max:100', 'sometimes') 
     * oppure callback personalizzate.
     * 
     * Esempio con stringhe:
     * ['name' => ['required', 'min:2', 'max:100'], 'email' => ['required', 'email']]
     * 
     * Esempio con "sometimes" (valida solo se il campo è presente):
     * ['email' => ['sometimes', 'required', 'email']]
     * - Se il campo email è presente, deve essere required e email valida
     * - Se il campo email non è presente, viene saltato completamente
     * 
     * Esempio con callback:
     * ['username' => [
     *     'required',
     *     function($field, $value, $data) {
     *         if (strlen($value) < 3) {
     *             return "Il campo {$field} deve avere almeno 3 caratteri";
     *         }
     *         return null;
     *     }
     * ]]
     * 
     * @return array Regole di validazione
     */
    protected static function validationRules(): array
    {
        return [];
    }

    /**
     * Restituisce i messaggi personalizzati per le regole di validazione
     * 
     * Deve essere sovrascritto nella classe che usa il trait.
     * 
     * Formato: ['field.rule' => 'Messaggio personalizzato']
     * 
     * @return array Messaggi personalizzati
     */
    protected static function validationMessages(): array
    {
        return [];
    }

    /**
     * Valida i dati passati come array
     * @param array $data Dati da validare
     * @return array Array vuoto se valido, altrimenti array di errori
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $validationRules = static::validationRules();

        foreach ($validationRules as $field => $rules) {
            // Controlla se c'è la regola "sometimes"
            $hasSometimes = in_array('sometimes', $rules, true);
            
            // Se c'è "sometimes" e il campo non è presente, salta tutte le regole per questo campo
            if ($hasSometimes && !array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field] ?? null;

            foreach ($rules as $rule) {
                // Salta la regola "sometimes" stessa (non è una regola di validazione)
                if ($rule === 'sometimes') {
                    continue;
                }

                $error = null;

                // Se la regola è una callback, chiamala direttamente
                if (is_callable($rule)) {
                    $error = call_user_func($rule, $field, $value, $data);
                    // alternativa: $error = $rule($field, $value, $data);
                    // non funziona se la callback è una stringa (riferimento a una funzione)
                }
                // Se la regola è una stringa, usa il sistema di validazione standard
                elseif (is_string($rule)) {
                    $error = static::checkRule($field, $value, $rule);
                }

                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Controlla una singola regola
     * @param string $field Nome del campo
     * @param mixed $value Valore da validare
     * @param string $rule Regola come stringa ('required', 'min:2', 'max:100')
     * @return string|null Messaggio di errore o null se valido
     */
    protected static function checkRule(string $field, mixed $value, string $rule): ?string
    {
        // Se la regola contiene ':', estrai nome e parametro (es: 'min:2')
        if (strpos($rule, ':') !== false) {
            $parts = explode(':', $rule);
            $ruleName = $parts[0];
            $param = $parts[1] ?? null;
        } else {
            // Altrimenti usa direttamente il nome della regola (es: 'required', 'email')
            $ruleName = $rule;
            $param = null;
        }

        // Cerca il messaggio personalizzato nel metodo validationMessages()
        $validationMessages = static::validationMessages();
        $messageKey = "{$field}.{$ruleName}";
        $customMessage = $validationMessages[$messageKey] ?? null;

        switch ($ruleName) {
            case 'required':
                if ($value === null || $value === '') {
                    return $customMessage ?? "Il campo {$field} è obbligatorio";
                }
                break;

            case 'min':
                if ($value !== null && $value !== '') {
                    if(is_string($value)) {
                        if(strlen($value) < (int)$param) {
                            return $customMessage ?? "Il campo {$field} deve avere almeno {$param} caratteri";
                        }
                    } else if(is_numeric($value)) {
                        if($value < (int)$param) {
                            return $customMessage ?? "Il campo {$field} deve essere maggiore o uguale a {$param}";
                        }
                    }
                }
                break;

            case 'max':
                if ($value !== null && $value !== '') {
                    if(is_string($value)) {
                        if(strlen($value) > (int)$param) {
                            return $customMessage ?? "Il campo {$field} non può avere più di {$param} caratteri";
                        }
                    } else if(is_numeric($value)) {
                        if($value > (int)$param) {
                            return $customMessage ?? "Il campo {$field} deve essere minore o uguale a {$param}";
                        }
                    }
                }
                break;

            case 'numeric':
                if ($value !== null && $value !== '' && !is_numeric($value)) {
                    return $customMessage ?? "Il campo {$field} deve essere un numero";
                }
                break;

            case 'email':
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return $customMessage ?? "Il campo {$field} deve essere un'email valida";
                }
                break;

        }

        return null;
    }
}