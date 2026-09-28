# Traductions — module Coach

Text domain : `rk-coach-hub`

`load_plugin_textdomain()` cherche ici les fichiers `rk-coach-hub-{locale}.mo`
(ex. `rk-coach-hub-ar.mo`). Ce dossier était absent du plugin : aucune
traduction n'était donc chargée, même si ~515 chaînes du module passent par `__()`.

## Générer le POT

```bash
wp i18n make-pot . Modules/Coach/languages/rk-coach-hub.pot \
  --domain=rk-coach-hub --include=Modules/Coach
```

## Note

Voir la note équivalente côté module Children.
Tant qu'elles n'y sont pas passées, une traduction complète reste impossible.
