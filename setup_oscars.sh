#!/bin/bash
# Oscar-Import Schnellstart-Script

echo "🏆 Oscar-Import Setup wird gestartet..."
echo ""

# 1. Migration
echo "1️⃣ Starte Datenbank-Migration..."
curl -s "http://localhost/movies/scripts/migrate_add_oscar_data.php"

echo ""
echo ""

# 2. Import
echo "2️⃣ Importiere Oscar-Daten..."
curl -s "http://localhost/movies/mod/import_oscars.php?mode=initial&limit=1000&verbose=1"

echo ""
echo ""

# 3. Admin-Panel öffnen
echo "3️⃣ Öffne Admin-Panel..."
echo ""
echo "   👉 http://localhost/movies/mod/import_oscars_admin.php"
echo ""
echo "✅ Setup abgeschlossen!"
