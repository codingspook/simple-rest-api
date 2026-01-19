# Simple Rest API

Template minimale per creare backend REST API in PHP

## Installazione

### Tramite Composer create-project

```bash
composer create-project codingspook/simple-rest-api nome-progetto
```

### Setup iniziale

1. **Configura il web server** per puntare alla directory `public/` (se non è già configurato)

2. **Configura la connessione al database** in `config/database.php`

3. **Configura il CORS** in `config/cors.php`

4. **Configura le route** in `routes/index.php`

## Struttura del Progetto

```
nome-progetto/
├── config/
│   ├── database.php     # Configurazione database
│   └── cors.php         # Configurazione CORS
├── routes/
│   └── index.php        # Definizione route
├── public/
│   └── index.php        # Entry point
├── src/
│   ├── bootstrap.php    # Bootstrap dell'applicazione
│   ├── Database/
│   ├── ├── DB.php              # Classe DB
│   │   └── JSONDB.php          # Classe JSONDB
│   ├── Models/
│   │   └── BaseModel.php       # Classe BaseModel
│   └── Utils/
│       ├── Request.php         # Classe Request
│       └── Response.php        # Gestione risposte JSON
├── composer.json        # Dipendenze Composer
└── README.md           # Questo file
```

## Comandi Utili

```bash
# Installa dipendenze
composer install

# Aggiorna autoload dopo aggiunta classi
composer dump-autoload

# Avvia server di sviluppo (PHP built-in)
php -S localhost:8000 -t public
```

## Licenza

MIT

## Supporto

Per domande o problemi, consulta la documentazione o apri una issue sul repository.

---

**Buon coding! 🚀**
