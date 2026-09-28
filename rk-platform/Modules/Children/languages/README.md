# Traductions — module Children

Text domain : `rk-my-children`

`load_plugin_textdomain()` cherche ici les fichiers `rk-my-children-{locale}.mo`
(ex. `rk-my-children-ar.mo`). Ce dossier était absent du plugin : aucune
traduction n'était donc chargée, même si ~673 chaînes du module passent par `__()`.

## Générer le POT

```bash
wp i18n make-pot . Modules/Children/languages/rk-my-children.pot \
  --domain=rk-my-children --include=Modules/Children
```

## Note

~3 900 chaînes arabes sont encore codées en dur hors de `__()` dans ce module.
Tant qu'elles n'y sont pas passées, une traduction complète reste impossible.
