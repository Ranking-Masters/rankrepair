# Externe bibliotheek

`3d-force-graph.min.js` — versie 1.73.4, MIT-licentie,
https://github.com/vasturiano/3d-force-graph

Meegeleverd in plaats van via een CDN geladen, om drie redenen: veel
klantomgevingen blokkeren externe scripts in de beheerdersomgeving, een CDN-storing
mag geen leeg Data-scherm opleveren, en een gepind bestand kan niet onder je
handen veranderen.

De bundel bevat three.js. Hij wordt alleen ingeladen wanneer iemand het
Data-tabblad opent, niet op de rest van de add-on.

Bijwerken: het bestand vervangen door een nieuwe `dist/3d-force-graph.min.js`
en de versie hierboven aanpassen.
