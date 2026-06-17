# MelisReactOverride

Module Laminas du back-office React de [Melis Platform](https://www.melistechnology.com).

Fournit le mécanisme qui permet d'afficher les outils legacy dans le shell React :

- **`/melis/react-tool-page?key=<melisKey>`** (`PluginViewController`) — assemble une page HTML
  standalone (CSS/JS plateforme + zone de l'outil) servie dans une iframe ; gère tous les pièges
  de rendu legacy (Envato, DataTable, shim Proxy, ressources du module, shell d'onglets,
  pont de notifications…). Surcharge le `PluginViewController` du cœur via config merge.
- **`/melis-react`** (`SpaController`) — sert le shell SPA (`index.html` de MelisCore) pour le
  point d'entrée et les deep-links côté client ; route publique.

> Module chargé via `config/application.config.php` (clé `modules`), **pas** via
> `config/melis.module.load.php` (que l'outil Modules réécrirait).
