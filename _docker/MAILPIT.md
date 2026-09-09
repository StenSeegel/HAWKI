# Mailpit - Email Testing für Development

## Übersicht

Mailpit ist ein Email-Testing-Tool, das alle ausgehenden E-Mails im Development-Modus abfängt. So kannst du sehen, wie E-Mails aussehen, ohne sie tatsächlich zu versenden.

## Zugriff

- **Web UI**: https://mail.hawki.dev
- **SMTP Server**: `mailpit:1025` (innerhalb des Docker-Netzwerks)

## Features

- 📧 Fängt alle ausgehenden E-Mails ab
- 🌐 Webbasierte UI zum Ansehen der E-Mails
- 📱 Responsive Design (funktioniert auch auf Mobilgeräten)
- 🔍 Suchfunktion für E-Mails
- 📎 Zeigt Anhänge an
- 📝 HTML und Plain-Text Ansicht
- 🔄 API für Automatisierung verfügbar

## Konfiguration

Die Mail-Einstellungen in `env/.env.dev` sind bereits für Mailpit konfiguriert:

```env
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=noreply@hawki.dev
MAIL_FROM_NAME="${APP_NAME}"
```

## Verwendung

1. **Container starten**:
   ```bash
   ./deploy-dev.sh
   ```

2. **Web UI öffnen**:
   Öffne https://mail.hawki.dev in deinem Browser

3. **E-Mails testen**:
   - Sende E-Mails über deine Laravel-Anwendung
   - Die E-Mails erscheinen sofort in der Mailpit-UI

## SSL-Zertifikat

Mailpit verwendet die gleichen SSL-Zertifikate wie die Hauptanwendung (`cert.pem` und `key.pem`). 

Wenn du ein eigenes Zertifikat für `mail.hawki.dev` erstellen möchtest, kannst du das Skript `certs/manage-certs.sh` verwenden.

## Erweiterte Einstellungen

Die aktuellen Umgebungsvariablen für Mailpit:

- `MP_MAX_MESSAGES=5000` - Maximale Anzahl gespeicherter E-Mails
- `MP_SMTP_AUTH_ACCEPT_ANY=1` - Akzeptiert jede SMTP-Authentifizierung
- `MP_SMTP_AUTH_ALLOW_INSECURE=1` - Erlaubt unverschlüsselte Verbindungen

## API

Mailpit bietet auch eine REST API unter https://mail.hawki.dev/api/v1

Nützliche Endpoints:
- `GET /api/v1/messages` - Alle E-Mails abrufen
- `GET /api/v1/message/{id}` - Einzelne E-Mail abrufen
- `DELETE /api/v1/messages` - Alle E-Mails löschen

## DNS-Konfiguration

Stelle sicher, dass `mail.hawki.dev` in deiner `/etc/hosts` Datei eingetragen ist:

```bash
sudo nano /etc/hosts
```

Füge folgende Zeile hinzu:
```
127.0.0.1 mail.hawki.dev
```

## Troubleshooting

### E-Mails werden nicht empfangen

1. Prüfe, ob der Container läuft:
   ```bash
   docker ps | grep mailpit
   ```

2. Prüfe die Laravel-Logs:
   ```bash
   docker logs hawki-dev-app
   ```

3. Stelle sicher, dass die `.env` Datei korrekt ist:
   ```bash
   docker exec hawki-dev-app php artisan config:cache
   ```

4. Prüfe die Nginx-Konfiguration:
   ```bash
   docker exec hawki-dev-nginx nginx -t
   ```

### Domain nicht erreichbar

Stelle sicher, dass `mail.hawki.dev` in deiner `/etc/hosts` eingetragen ist:
```bash
cat /etc/hosts | grep mail.hawki.dev
```

### Port bereits belegt

Die Ports werden nicht mehr direkt exponiert, sondern nur über Nginx weitergeleitet.

## Weiterführende Links

- [Mailpit Dokumentation](https://github.com/axllent/mailpit)
- [Mailpit Features](https://github.com/axllent/mailpit#features)
