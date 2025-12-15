<?php

// CLIENT HTTP REQUEST -> fetch
// BACKEND -> handles request (builds and sends response)
// CLIENT HTTP handles response -> does something

/* Routes per gestione utenti */


use App\Utils\Response;
use App\Models\User;
use App\Utils\Request;
use Pecee\SimpleRouter\SimpleRouter as Router;

/**
 * GET /api/users - Lista utenti
 */
Router::get('/users', function () {
    try {
        $users = User::all();
        Response::success($users)->send();
    } catch (\Exception $e) {
        Response::error('Errore nel recupero della lista utenti: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * GET /api/users/{id} - Lista utenti
 */
Router::get('/users/{id}', function ($id) {
    try {
        $user = User::find($id);

        if($user === null) {
            Response::error('Utente non trovato', Response::HTTP_NOT_FOUND)->send();
        }

        Response::success($user)->send();
    } catch (\Exception $e) {
        Response::error('Errore nel recupero della lista utenti: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});


/**
 * POST /api/users - Crea nuovo utente
 */
Router::post('/users', function () {
    try {
        $request = new Request();
        $data = $request->json();

        // Validazione
        if(!isset($data['name']) || !isset($data['email'])) {
            Response::error('Nome e email sono obbligatori', Response::HTTP_BAD_REQUEST, array_map(fn($field) => "Il campo {$field} è obbligatorio", ['name', 'email']))->send();
            return;
        }

        $errors = User::validate($data);
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        $user = User::create($data);

        Response::success($user, Response::HTTP_CREATED, "Utente creato con successo")->send();
    } catch (\Exception $e) {
        Response::error('Errore durante la creazione del nuovo utente: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

Router::match(['put', 'patch'], '/users/{id}', function($id) {
    try {
        $request = new Request();
        $data = $request->json();

        $user = User::find($id);
        if($user === null) {
            Response::error('Utente non trovato', Response::HTTP_NOT_FOUND)->send();
        }

        $errors = User::validate(array_merge($data, ['id' => $id]));
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        $user->update($data);

        Response::success($user, Response::HTTP_OK, "Utente aggiornato con successo")->send();
    } catch (\Exception $e) {
        Response::error('Errore durante l\'aggiornamento dell\' utente: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

Router::delete('/users/{id}', function($id) {
    try {
        $user = User::find($id);
        if($user === null) {
            Response::error('Utente non trovato', Response::HTTP_NOT_FOUND)->send();
        }

        $user->delete();

        Response::success(null, Response::HTTP_OK, "Utente eliminato con successo")->send();
    } catch (\Exception $e) {
        Response::error('Errore durante l\'eliminazione dell\' utente: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});