# Template Simple REST API

Template minimale per creare backend REST API in PHP

## Installazione

### Tramite Composer create-project

```bash
composer create-project codingspook/simple-rest-api nome-progetto
```

### Setup iniziale

1. **Installa le dipendenze** (se non già fatto):

```bash
composer install
```

2. **Configura il web server** per puntare alla directory `public/`

## Struttura del Progetto

```
nome-progetto/
├── config/
│   └── cors.php         # Configurazione CORS
├── routes/
│   ├── users.php        # Definizione route per la risorsa utenti
│   └── index.php        # Definizione route
├── public/
│   └── index.php        # Entry point
├── src/
│   ├── bootstrap.php    # Bootstrap dell'applicazione
│   ├── Models/
│   │   ├── BaseModel.php       # Classe BaseModel
│   │   └── User.php            # Esempio model
│   └── Utils/
│       ├── DB.php              # Classe DB
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
