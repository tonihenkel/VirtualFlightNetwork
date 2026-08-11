# Generierte VFN-Daten

`airport-layouts.tar.gz` enthält die generierten weltweiten Airport-Layouts aus
`htdocs/data/airport_layouts/`. Die mehr als 100.000 Einzeldateien bleiben aus
Git ausgeschlossen, damit Status, Commits und Klonen schnell bleiben.

Wiederherstellung unter Windows:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/restore_airport_layouts.ps1
```

Nach einer vollständigen Neugenerierung muss das Archiv bewusst erneut erzeugt
und committed werden.
