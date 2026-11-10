/* MAXXPET Bonus-System: Admin-Modul „Bonuspunkte“ (ohne Build-Schritt, reines JavaScript). */
(function () {
    'use strict';
    if (!window.Shopware || !Shopware.Component || !Shopware.Module) { return; }

    var BASE = '/_action/maxxpet-bonus';
    var ABOUT_VERSION = '1.8.0';

    function http() { return Shopware.Application.getContainer('init').httpClient; }
    function headers() {
        return {
            Accept: 'application/json',
            Authorization: 'Bearer ' + Shopware.Service('loginService').getToken(),
            'Content-Type': 'application/json'
        };
    }
    function fmt(n) { return Number(n || 0).toLocaleString('de-DE'); }
    function euro(n) { return Number(n || 0).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }); }
    function parse(s) { return new Date(String(s).replace(' ', 'T') + 'Z'); }
    function dt(s) {
        if (!s) { return ''; }
        var d = parse(s);
        return isNaN(d) ? String(s).slice(0, 10) : d.toLocaleDateString('de-DE');
    }

    var PRESETS = [
        'Entschädigung Lieferverzug',
        'Kulanz',
        'Aktion / Gewinnspiel',
        'Korrektur einer Fehlbuchung',
        'Übertrag aus altem System'
    ];


var SETTINGS_TPL = `
<div class="mxa-settings" v-else-if="tab === 'settings'">
  <sw-card title="Allgemein" position-identifier="mxs-general">
    <label class="mxa-check"><input type="checkbox" v-model="cfg.active"> Bonus-System aktiv</label>
    <p class="mxa-hint">Aus: keine neuen Punkte, kein Einlösen, die Seite „Meine Punkte“ ist nicht erreichbar. Vorhandene Buchungen bleiben erhalten.</p>
    <label class="mxa-field">Teilnahme
      <select class="mxa-input" v-model="cfg.enrollment">
        <option value="auto">Automatisch: jeder Kunde mit Konto nimmt teil</option>
        <option value="optin">Per Beitritt: der Kunde meldet sich an (Checkbox bei Registrierung und Kasse, Knopf unter „Meine Punkte“)</option>
      </select>
    </label>
    <label class="mxa-field">Teilnehmende Kundengruppen
      <select class="mxa-input" multiple size="4" v-model="cfg.customerGroupIds">
        <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
      </select>
      <small>Nichts auswählen = alle Gruppen. Für mehrere Gruppen Strg (Mac: Cmd) gedrückt halten.</small>
    </label>
  </sw-card>

  <sw-card title="Punkte und Einlösen" position-identifier="mxs-points">
    <div class="mxa-grid">
      <label class="mxa-field">Punkte je 1 € Warenwert<input class="mxa-input" type="number" step="0.1" min="0" v-model.number="cfg.pointsPerEuro"><small>Basis: Bestellwert ohne Versand und nach Rabatten.</small></label>
      <label class="mxa-field">Wert eines Punktes (€)<input class="mxa-input" type="number" step="0.001" min="0.001" v-model.number="cfg.pointValue"><small>{{ rateHint }}</small></label>
      <label class="mxa-field">Sperrfrist (Tage)<input class="mxa-input" type="number" step="1" min="0" v-model.number="cfg.lockDays"><small>0 = sofort nach „Bezahlt“. 14 = erst nach der Widerrufsfrist.</small></label>
      <label class="mxa-field">Mindestmenge zum Einlösen (Punkte)<input class="mxa-input" type="number" step="1" min="1" v-model.number="cfg.minRedeem"></label>
      <label class="mxa-field">Mindest-Warenwert zum Einlösen (€)<input class="mxa-input" type="number" step="1" min="0" v-model.number="cfg.minOrderValue"><small>0 = keiner. Darunter sieht der Kunde, wie viel noch fehlt.</small></label>
      <label class="mxa-field">Höchstanteil am Warenwert (%)<input class="mxa-input" type="number" step="1" min="0" max="100" v-model.number="cfg.maxRedeemPercent"><small>0 = keine Obergrenze.</small></label>
    </div>
    <label class="mxa-check"><input type="checkbox" v-model="cfg.combineWithLevelDiscount"> Treuerabatt und Punkte-Einlösung dürfen kombiniert werden</label>
    <p class="mxa-hint">Aus: Löst der Kunde Punkte ein, entfällt der Treuerabatt der Stufe.</p>
  </sw-card>

  <sw-card title="Stufen" position-identifier="mxs-levels">
    <label class="mxa-field">Stufen-Modell
      <select class="mxa-input" v-model="cfg.levelModel">
        <option value="lifetime">Lebenslang: alle jemals verdienten Punkte zählen</option>
        <option value="rolling12">Rollierend: nur die Punkte der letzten 12 Monate zählen</option>
        <option value="off">Aus: reines Punkteprogramm ohne Stufen</option>
      </select>
    </label>
    <table class="mxa-table" v-if="cfg.levelModel !== 'off'">
      <thead><tr><th>Name</th><th>Ab Statuspunkten</th><th>Punkte-Faktor</th><th>Treuerabatt (%)</th><th></th></tr></thead>
      <tbody>
        <tr v-for="(l, i) in levelRows" :key="i">
          <td><input class="mxa-input" type="text" v-model="l.name" placeholder="z. B. Silber"></td>
          <td><input class="mxa-input" type="number" min="0" step="1" v-model.number="l.min" :disabled="i === 0"></td>
          <td><input class="mxa-input" type="number" min="0.1" step="0.05" v-model.number="l.mult"></td>
          <td><input class="mxa-input" type="number" min="0" max="100" step="0.5" v-model.number="l.discount"></td>
          <td><button type="button" class="mxa-btn mxa-btn--small" @click="levelRows.splice(i, 1)" :disabled="levelRows.length <= 1" aria-label="Stufe entfernen">Entfernen</button></td>
        </tr>
      </tbody>
    </table>
    <button v-if="cfg.levelModel !== 'off'" type="button" class="mxa-btn" @click="addLevel">Stufe hinzufügen</button>
    <p class="mxa-hint" v-if="cfg.levelModel !== 'off'">Faktor 1,15 = 15 % mehr Punkte je Euro. Die erste Stufe gilt immer ab 0 Punkten. Ein Treuerabatt wird im Warenkorb als eigene Zeile abgezogen.</p>
  </sw-card>

  <sw-card title="Weitere Aktionen" position-identifier="mxs-actions">
    <p class="mxa-hint">Punkte für einmalige Aktionen. 0 = aus.</p>
    <div class="mxa-grid">
      <label class="mxa-field">Registrierung<input class="mxa-input" type="number" min="0" step="1" v-model.number="cfg.pointsRegistration"></label>
      <label class="mxa-field">Newsletter-Anmeldung<input class="mxa-input" type="number" min="0" step="1" v-model.number="cfg.pointsNewsletter"></label>
      <label class="mxa-field">Produktbewertung (je Produkt)<input class="mxa-input" type="number" min="0" step="1" v-model.number="cfg.pointsReview"></label>
      <label class="mxa-field">Geburtstag (jährlich)<input class="mxa-input" type="number" min="0" step="1" v-model.number="cfg.pointsBirthday"></label>
    </div>
  </sw-card>

  <sw-card title="Empfehlungssystem" position-identifier="mxs-referral">
    <label class="mxa-check"><input type="checkbox" v-model="cfg.referralEnabled"> Empfehlungssystem aktiv</label>
    <div class="mxa-grid">
      <label class="mxa-field">Provision: Punkte je 1 € Warenwert<input class="mxa-input" type="number" min="0" step="0.1" v-model.number="cfg.referralPointsPerEuro"><small>Für jede bezahlte Bestellung des Geworbenen, lebenslang.</small></label>
      <label class="mxa-field">Willkommenspunkte für den Geworbenen<input class="mxa-input" type="number" min="0" step="1" v-model.number="cfg.referralWelcome"><small>0 = keine.</small></label>
    </div>
  </sw-card>

  <sw-card title="Gratisprodukte" position-identifier="mxs-gifts">
    <p class="mxa-hint">Geschenke ab einem Warenwert, optional ab einer Stufe. „Automatisch“ legt das Geschenk selbst in den Warenkorb, bei „Zur Auswahl“ wählt der Kunde eines aus. Es wird kein Lagerbestand abgebucht, die Bestellung zeigt Artikelnummer und Bezeichnung zum Verpacken.</p>
    <table class="mxa-table" v-if="giftRows.length">
      <thead><tr><th>Artikelnummer</th><th>Bezeichnung</th><th>Ab Warenwert (€)</th><th>Ab Stufe</th><th>Art</th><th></th></tr></thead>
      <tbody>
        <tr v-for="(g, i) in giftRows" :key="i">
          <td><input class="mxa-input" type="text" v-model="g.sku" placeholder="ART-1001"></td>
          <td><input class="mxa-input" type="text" v-model="g.label" placeholder="Beispiel-Geschenk"></td>
          <td><input class="mxa-input" type="number" min="0" step="1" v-model.number="g.min"></td>
          <td><select class="mxa-input" v-model="g.level"><option value="">jede</option><option v-for="l in levelRows" :key="l.name" :value="l.name">{{ l.name }}</option></select></td>
          <td><select class="mxa-input" v-model="g.mode"><option value="auto">Automatisch</option><option value="wahl">Zur Auswahl</option></select></td>
          <td><button type="button" class="mxa-btn mxa-btn--small" @click="giftRows.splice(i, 1)">Entfernen</button></td>
        </tr>
      </tbody>
    </table>
    <p class="mxa-hint" v-else>Noch keine Regel.</p>
    <button type="button" class="mxa-btn" @click="giftRows.push({ sku: '', label: '', min: 50, level: '', mode: 'auto' })">Regel hinzufügen</button>
  </sw-card>

  <div class="mxa-savebar">
    <button type="button" class="mxa-btn mxa-btn--primary" :disabled="saving" @click="saveSettings">{{ saving ? 'Speichere …' : 'Einstellungen speichern' }}</button>
    <button type="button" class="mxa-btn" :disabled="saving" @click="loadSettings">Änderungen verwerfen</button>
  </div>
</div>`;

var ABOUT_TPL = `
<div class="mxa-about" v-else>
  <sw-card title="Danke!" position-identifier="mxs-about">
    <div class="mxa-about-hero">
      <button type="button" class="mxa-egg" @click="eggClick" aria-label="Stern">
        <svg viewBox="0 0 24 24" width="56" height="56"><path fill="currentColor" d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.3 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/></svg>
      </button>
      <h3>Bonus-System</h3>
      <p class="mxa-hint">Version ${ABOUT_VERSION}</p>
    </div>
    <p class="mxa-about-text">Aus einer Idee ist ein Treueprogramm geworden: mit Punkten, Stufen, Gratisprodukten, Empfehlungen und einer eigenen Verwaltung. Das hat viel Planung, Testen auf dem Testshop und genaues Hinsehen gebraucht. Vielen Dank an alle, die mitgedacht und mitgetestet haben.</p>
    <div class="mxa-credits">
      <div><span>Ideengeber und Hersteller</span><strong>Markus Czarnetzki, Oberhausen, NRW</strong></div>
      <div><span>Webseite</span><strong><a href="https://macdata.de" target="_blank" rel="noopener noreferrer">macdata.de</a></strong></div>
      <div><span>Programmierung</span><strong>Markus Czarnetzki &amp; Claude, KI-Assistent von Anthropic, im Auftrag von MAXXPET</strong></div>
      <div><span>Gebaut für</span><strong>Shopware 6.7</strong></div>
    </div>
    <p class="mxa-copy">© 1999 macdata.de · Plugin © 2026 Markus Czarnetzki</p>
    <p class="mxa-about-foot" v-if="egg">🎉 Gefunden! Jeder Punkt zählt, besonders der letzte. Danke fürs Ausprobieren.</p>
    <p class="mxa-hint" v-else>Tipp: Manche Sterne mögen es, wenn man öfter hinschaut.</p>
  </sw-card>
</div>`;

    var TEMPLATE = `
<sw-page class="maxxpet-bonus-index">
  <template #smart-bar-header><h2>Bonuspunkte</h2></template>
  <template #smart-bar-actions>
    <button class="mxa-btn" type="button" @click="exportCsv">CSV-Export</button>
    <button class="mxa-btn" type="button" @click="openSettings">Einstellungen</button>
  </template>
  <template #content>
    <sw-card-view>
      <div class="mxa-tabs" role="tablist">
        <button type="button" role="tab" :class="{ 'is-on': tab === 'main' }" @click="tab = 'main'">Übersicht</button>
        <button type="button" role="tab" :class="{ 'is-on': tab === 'settings' }" @click="openSettings">Einstellungen</button>
        <button type="button" role="tab" :class="{ 'is-on': tab === 'about' }" @click="tab = 'about'">Danke</button>
      </div>
      <template v-if="tab === 'main'">
      <div class="mxa-stats" v-if="stats">
        <div class="mxa-stat"><span>Offene Punkte</span><strong>{{ fmt(stats.outstanding) }}</strong><small>Verbindlichkeit {{ euro(stats.outstandingEuro) }}</small></div>
        <div class="mxa-stat"><span>Vergeben</span><strong>{{ fmt(stats.issued) }}</strong><small>{{ fmt(stats.reversed) }} zurückgebucht</small></div>
        <div class="mxa-stat"><span>Eingelöst</span><strong>{{ fmt(stats.redeemed) }}</strong><small>{{ euro(stats.redeemedEuro) }} Rabatt</small></div>
        <div class="mxa-stat"><span>Kunden mit Punkten</span><strong>{{ fmt(stats.customers) }}</strong><small>{{ fmt(stats.referrals) }} Empfehlungen · {{ fmt(stats.blocked) }} gesperrt</small></div>
      </div>

      <sw-card title="Kunden" position-identifier="maxxpet-bonus-list">
        <div class="mxa-toolbar">
          <input class="mxa-input" type="search" v-model="search" @input="onSearch" placeholder="Suchen: Name, E-Mail oder Kundennummer">
          <select class="mxa-input mxa-select" v-model="filter" @change="load(1)" aria-label="Filter">
            <option value="active">Kunden im Programm</option>
            <option value="positive">Mit verfügbaren Punkten</option>
            <option value="referrers">Werber</option>
            <option value="blocked">Gesperrte</option>
            <option value="all">Alle Kunden</option>
          </select>
          <span class="mxa-count" v-if="!loading">{{ fmt(list.total) }} Treffer</span>
          <span class="mxa-count" v-else>Lade …</span>
        </div>

        <table class="mxa-table" v-if="list.items.length">
          <thead>
            <tr>
              <th class="mxa-sort" @click="setSort('name')">Kunde {{ arrow('name') }}</th>
              <th class="mxa-sort" @click="setSort('email')">E-Mail {{ arrow('email') }}</th>
              <th class="mxa-r mxa-sort" @click="setSort('available')">Verfügbar {{ arrow('available') }}</th>
              <th class="mxa-r mxa-sort" @click="setSort('pending')">Ausstehend {{ arrow('pending') }}</th>
              <th class="mxa-r">Wert</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in list.items" :key="c.id" class="mxa-click" tabindex="0" @click="open(c.id)" @keyup.enter="open(c.id)">
              <td><strong>{{ c.name }}</strong><br><small>{{ c.customerNumber }}</small></td>
              <td>{{ c.email }}</td>
              <td class="mxa-r"><strong>{{ fmt(c.available) }}</strong></td>
              <td class="mxa-r">{{ fmt(c.pending) }}</td>
              <td class="mxa-r">{{ euro(c.available * stats.pointValue) }}</td>
              <td>
                <span class="mxa-badge mxa-badge--bad" v-if="c.blocked">gesperrt</span>
                <span class="mxa-badge mxa-badge--ok" v-else-if="c.member || c.available || c.pending">aktiv</span>
              </td>
            </tr>
          </tbody>
        </table>
        <p class="mxa-empty" v-else-if="!loading">Keine Kunden gefunden. Ohne Suchbegriff erscheinen nur Kunden im Programm; mit dem Filter „Alle Kunden“ siehst du jeden.</p>

        <div class="mxa-pager" v-if="list.total > limit">
          <button class="mxa-btn" type="button" :disabled="page <= 1" @click="load(page - 1)">Zurück</button>
          <span>Seite {{ page }} von {{ totalPages }}</span>
          <button class="mxa-btn" type="button" :disabled="page >= totalPages" @click="load(page + 1)">Weiter</button>
        </div>
      </sw-card>

      <sw-card title="Bestellungen nachbuchen" position-identifier="maxxpet-bonus-import">
        <p class="mxa-hint">Findet bezahlte Bestellungen ohne Punktebuchung, zum Beispiel aus der Zeit vor der Aktivierung des Bonus-Systems, und bucht sie nach. Zuerst die Vorschau ansehen.</p>
        <div class="mxa-toolbar">
          <label>Bestellungen ab <input class="mxa-input mxa-date" type="date" v-model="imp.since"></label>
          <button class="mxa-btn" type="button" :disabled="!imp.since" @click="doImport(false)">Vorschau</button>
          <button class="mxa-btn mxa-btn--primary" type="button" :disabled="!imp.since || !imp.previewed" @click="doImport(true)">Jetzt nachbuchen</button>
        </div>
        <pre v-if="imp.result" class="mxa-pre">{{ imp.result }}</pre>
      </sw-card>
      </template>
      ${SETTINGS_TPL}
      ${ABOUT_TPL}
    </sw-card-view>

    <div class="mxa-overlay" v-if="detail || drawerLoading" @click.self="close">
      <aside class="mxa-drawer" role="dialog" aria-modal="true" aria-label="Kundendetails">
        <p v-if="drawerLoading && !detail" class="mxa-hint">Lade …</p>
        <template v-if="detail">
          <header class="mxa-dhead">
            <div>
              <h3>{{ detail.name }}</h3>
              <div class="mxa-sub">{{ detail.email }} · {{ detail.customerNumber }}</div>
              <div class="mxa-badges">
                <span class="mxa-badge mxa-badge--bad" v-if="detail.state.blocked">gesperrt</span>
                <span class="mxa-badge mxa-badge--ok" v-else-if="detail.participant">nimmt teil</span>
                <span class="mxa-badge" v-else>nimmt nicht teil</span>
                <span class="mxa-badge mxa-badge--lvl" v-if="detail.account.levelsOn && detail.participant">Level {{ detail.account.level.name }}</span>
                <span class="mxa-badge" v-if="detail.referral.count">{{ detail.referral.count }} geworben</span>
              </div>
            </div>
            <button class="mxa-x" type="button" @click="close" aria-label="Schließen">×</button>
          </header>

          <div class="mxa-kpis">
            <div><span>Verfügbar</span><strong>{{ fmt(detail.account.available) }}</strong><small>{{ euro(detail.account.available * detail.pointValue) }}</small></div>
            <div><span>Ausstehend</span><strong>{{ fmt(detail.account.pending) }}</strong></div>
            <div v-if="detail.account.levelsOn"><span>Statuspunkte</span><strong>{{ fmt(detail.account.status) }}</strong>
              <small v-if="detail.account.next">noch {{ fmt(detail.account.missing) }} bis {{ detail.account.next.name }}</small><small v-else>höchstes Level</small></div>
          </div>
          <div class="mxa-bar" v-if="detail.account.levelsOn && detail.account.next"><span :style="{ width: detail.account.progress + '%' }"></span></div>

          <div class="mxa-links">
            <button class="mxa-btn mxa-btn--small" type="button" @click="goCustomer(detail.id)">Kunde im Admin öffnen</button>
            <button class="mxa-btn mxa-btn--small" type="button" @click="doBlock(!detail.state.blocked)">{{ detail.state.blocked ? 'Sperre aufheben' : 'Kunden sperren' }}</button>
          </div>

          <section class="mxa-book">
            <h4>Punkte buchen</h4>
            <div class="mxa-quick">
              <button type="button" class="mxa-chip" v-for="n in [50, 100, 250, 500, -50, -100]" :key="n" @click="book.points = n">{{ n > 0 ? '+' : '' }}{{ n }}</button>
            </div>
            <div class="mxa-row">
              <input class="mxa-input mxa-pts" type="number" v-model.number="book.points" placeholder="Punkte (+/−)">
              <select class="mxa-input" v-model="book.preset" @change="applyPreset" aria-label="Vorlage Begründung">
                <option value="">Vorlage Begründung …</option>
                <option v-for="p in presets" :key="p" :value="p">{{ p }}</option>
              </select>
            </div>
            <input class="mxa-input mxa-full" type="text" v-model="book.reason" maxlength="200" placeholder="Begründung (der Kunde sieht sie in seinem Konto)">
            <div class="mxa-preview" v-if="book.points">Neuer Stand: <strong>{{ fmt(detail.account.available + book.points) }}</strong> Punkte ({{ book.points > 0 ? '+' : '' }}{{ fmt(book.points) }})</div>
            <button class="mxa-btn mxa-btn--primary" type="button" :disabled="!canBook" @click="doBook">Buchen</button>
          </section>

          <h4>Buchungen</h4>
          <p class="mxa-hint" v-if="!detail.entries.length">Noch keine Buchungen.</p>
          <table class="mxa-table mxa-table--small" v-else>
            <thead><tr><th>Datum</th><th>Vorgang</th><th class="mxa-r">Punkte</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="(e, i) in detail.entries" :key="i">
                <td>{{ dt(e.createdAt) }}</td>
                <td>
                  <a href="#" v-if="e.orderId" @click.prevent="goOrder(e.orderId)">{{ e.reason || e.label }}</a>
                  <span v-else>{{ e.reason || e.label }}</span>
                </td>
                <td class="mxa-r" :class="e.points < 0 ? 'mxa-minus' : 'mxa-plus'">{{ e.points > 0 ? '+' : '' }}{{ fmt(e.points) }}</td>
                <td>{{ e.pending ? 'ab ' + dt(e.availableAt) : 'verfügbar' }}</td>
              </tr>
            </tbody>
          </table>
        </template>
      </aside>
    </div>
  </template>
</sw-page>`;

    var STYLE = `
.mxa-about-hero{text-align:center;padding:10px 0 4px}.mxa-about-hero h3{margin:6px 0 0;font-size:22px;color:#043477}
.mxa-egg{border:0;background:#e3f4e4;color:#27832a;width:92px;height:92px;border-radius:50%;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:transform .15s}
.mxa-egg:hover{transform:scale(1.06)}.mxa-egg:active{transform:scale(.94)}
.mxa-about-text{max-width:640px;margin:12px auto;text-align:center;color:#173b59;line-height:1.55}
.mxa-credits{max-width:640px;margin:16px auto;display:grid;gap:8px}
.mxa-credits div{background:#f4f8fd;border-radius:8px;padding:10px 14px;display:flex;flex-direction:column}
.mxa-credits span{font-size:12px;color:#52667a;text-transform:uppercase;letter-spacing:.04em}.mxa-credits strong{color:#043477}
.mxa-credits a{color:#0649a8}.mxa-copy{text-align:center;color:#52667a;font-size:13px;margin:14px 0 4px}
.mxa-about-foot{text-align:center;font-weight:700;color:#1b6b1f}.mxa-about .mxa-hint{text-align:center}
.mxa-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid #b6c3d1;background:#fff;color:#1b2a3a;border-radius:6px;padding:8px 14px;min-height:38px;font:inherit;font-weight:600;cursor:pointer}
.mxa-btn:hover:not(:disabled){background:#f4f8fd;border-color:#0649a8}
.mxa-btn:disabled{opacity:.5;cursor:not-allowed}
.mxa-btn--primary{background:#0649a8;border-color:#0649a8;color:#fff}.mxa-btn--primary:hover:not(:disabled){background:#043477;border-color:#043477}
.mxa-btn--small{padding:4px 10px;min-height:30px;font-size:13px}
.mxa-tabs{display:flex;gap:4px;margin-bottom:14px;border-bottom:2px solid #d9e2ee}
.mxa-tabs button{border:0;background:none;padding:10px 18px;font:inherit;font-weight:600;color:#52667a;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px}
.mxa-tabs button.is-on{color:#0649a8;border-bottom-color:#0649a8}
.mxa-settings .mxa-field{display:flex;flex-direction:column;gap:4px;margin:10px 0;font-weight:600;color:#173b59}
.mxa-settings .mxa-field small{font-weight:400;color:#52667a}
.mxa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:4px 18px}
.mxa-check{display:flex;gap:8px;align-items:center;margin:8px 0;font-weight:600;color:#173b59}
.mxa-settings .mxa-table td{vertical-align:middle;padding:6px 6px}.mxa-settings .mxa-table .mxa-input{width:100%}
.mxa-settings sw-card,.mxa-settings .sw-card{margin-bottom:16px}
.mxa-savebar{position:sticky;bottom:0;display:flex;gap:10px;padding:12px 0;background:linear-gradient(transparent,#f0f2f5 30%)}
.mxa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px}
.mxa-stat{background:#fff;border:1px solid #d9e2ee;border-top:3px solid #27832a;border-radius:8px;padding:12px 14px;display:flex;flex-direction:column}
.mxa-stat span{font-size:12px;color:#52667a;text-transform:uppercase;letter-spacing:.04em}
.mxa-stat strong{font-size:28px;line-height:1.2;color:#043477}
.mxa-stat small{color:#52667a}
.mxa-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:6px 0 12px}
.mxa-input{padding:8px 10px;border:1px solid #c5d0dc;border-radius:6px;background:#fff;min-height:38px;font:inherit}
.mxa-toolbar .mxa-input[type=search]{flex:1;min-width:220px}
.mxa-select{min-width:200px}.mxa-date{margin-left:6px}
.mxa-count{color:#52667a;font-size:13px}
.mxa-table{width:100%;border-collapse:collapse}
.mxa-table th{text-align:left;font-size:12px;color:#52667a;padding:8px;border-bottom:2px solid #d9e2ee;white-space:nowrap}
.mxa-table td{padding:10px 8px;border-bottom:1px solid #e6edf6;vertical-align:top}
.mxa-table--small td{padding:7px 6px;font-size:13px}
.mxa-sort{cursor:pointer;user-select:none}.mxa-sort:hover{color:#043477}
.mxa-r{text-align:right!important;white-space:nowrap}
.mxa-click{cursor:pointer}.mxa-click:hover,.mxa-click:focus{background:#f4f8fd;outline:none}
.mxa-badge{display:inline-block;font-size:12px;font-weight:600;padding:2px 9px;border-radius:999px;background:#eef2f6;color:#52667a;margin:2px 4px 2px 0}
.mxa-badge--ok{background:#e3f4e4;color:#1b6b1f}.mxa-badge--bad{background:#fde7e5;color:#b3261e}.mxa-badge--lvl{background:#e6eefb;color:#0649a8}
.mxa-plus{color:#1f7a22;font-weight:700}.mxa-minus{color:#b3261e;font-weight:700}
.mxa-empty,.mxa-hint{color:#52667a}
.mxa-pager{display:flex;gap:12px;align-items:center;justify-content:center;margin-top:14px}
.mxa-pre{background:#f4f8fd;padding:10px;border-radius:6px;white-space:pre-wrap;max-height:260px;overflow:auto}
.mxa-overlay{position:fixed;inset:0;background:rgba(4,52,119,.35);z-index:1000;display:flex;justify-content:flex-end}
.mxa-drawer{width:min(560px,100%);height:100%;background:#fff;box-shadow:-8px 0 30px rgba(0,0,0,.2);overflow-y:auto;padding:20px 22px}
.mxa-dhead{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.mxa-dhead h3{margin:0 0 2px;font-size:20px;color:#043477}
.mxa-sub{color:#52667a;font-size:13px}
.mxa-x{border:0;background:none;font-size:30px;line-height:1;cursor:pointer;color:#52667a;min-width:40px;min-height:40px}
.mxa-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin:16px 0 8px}
.mxa-kpis div{background:#f4f8fd;border-radius:8px;padding:10px 12px;display:flex;flex-direction:column}
.mxa-kpis span{font-size:11px;color:#52667a;text-transform:uppercase}.mxa-kpis strong{font-size:22px;color:#043477}.mxa-kpis small{color:#52667a}
.mxa-bar{height:8px;background:#dbe6f3;border-radius:999px;overflow:hidden;margin-bottom:10px}.mxa-bar span{display:block;height:100%;background:#27832a}
.mxa-links{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
.mxa-book{border:1px solid #d9e2ee;border-radius:10px;padding:12px 14px;margin:14px 0}
.mxa-book h4,.mxa-drawer h4{margin:14px 0 8px;font-size:14px;color:#043477}.mxa-book h4{margin-top:0}
.mxa-quick{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.mxa-chip{border:1px solid #c5d0dc;background:#fff;border-radius:999px;padding:4px 12px;cursor:pointer;font:inherit}.mxa-chip:hover{background:#f4f8fd}
.mxa-row{display:flex;gap:8px;margin-bottom:8px}.mxa-pts{flex:0 0 140px}.mxa-row select{flex:1}
.mxa-full{width:100%;margin-bottom:8px}
.mxa-preview{margin:4px 0 10px;color:#173b59}
@media (max-width:700px){.mxa-row{flex-direction:column}.mxa-pts{flex:1}}
`;

    Shopware.Component.register('maxxpet-bonus-index', {
        template: TEMPLATE,
        mixins: [Shopware.Mixin.getByName('notification')],
        data: function () {
            return {
                stats: null, search: '', filter: 'active', sort: 'available', dir: 'desc',
                page: 1, limit: 25, loading: false, timer: null,
                list: { total: 0, items: [] },
                detail: null, drawerLoading: false,
                presets: PRESETS,
                tab: 'main', egg: false, eggClicks: 0, konami: [], cfg: {}, levelRows: [], giftRows: [], groups: [], saving: false, cfgLoaded: false,
                book: { points: null, reason: '', preset: '' },
                imp: { since: '', result: '', previewed: false }
            };
        },
        computed: {
            totalPages: function () { return Math.max(1, Math.ceil(this.list.total / this.limit)); },
            rateHint: function () {
                var v = Number(this.cfg.pointValue) || 0, p = Number(this.cfg.pointsPerEuro) || 0;
                return '100 Punkte = ' + euro(100 * v) + '. Rückvergütung etwa ' + (p * v * 100).toLocaleString('de-DE', { maximumFractionDigits: 2 }) + ' % (ohne Stufen-Faktor).';
            },
            canBook: function () {
                return !!this.book.points && Number(this.book.points) !== 0 && String(this.book.reason || '').trim().length > 2;
            }
        },
        created: function () {
            this.addStyle();
            this.load(1);
            this._onKey = function (e) {
                if (e.key === 'Escape' && this.detail) { this.close(); }
                var seq = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];
                this.konami.push(e.key);
                if (this.konami.length > seq.length) { this.konami.shift(); }
                if (this.konami.join(',') === seq.join(',')) { this.konami = []; this.tab = 'about'; this.celebrate(); }
            }.bind(this);
            document.addEventListener('keydown', this._onKey);
        },
        beforeUnmount: function () { document.removeEventListener('keydown', this._onKey); },
        methods: {
            fmt: fmt, euro: euro, dt: dt,
            addStyle: function () {
                if (document.getElementById('mxa-style')) { return; }
                var s = document.createElement('style');
                s.id = 'mxa-style';
                s.textContent = STYLE;
                document.head.appendChild(s);
            },
            fail: function (e) {
                var m = e && e.response && e.response.data && e.response.data.error ? e.response.data.error : 'Aktion fehlgeschlagen.';
                this.createNotificationError({ message: m });
            },
            arrow: function (col) { return this.sort === col ? (this.dir === 'asc' ? '▲' : '▼') : ''; },
            setSort: function (col) {
                if (this.sort === col) { this.dir = this.dir === 'asc' ? 'desc' : 'asc'; }
                else { this.sort = col; this.dir = col === 'name' || col === 'email' ? 'asc' : 'desc'; }
                this.load(1);
            },
            onSearch: function () {
                var me = this;
                clearTimeout(me.timer);
                me.timer = setTimeout(function () { me.load(1); }, 350);
            },
            load: function (page) {
                var me = this;
                me.page = page;
                me.loading = true;
                http().get(BASE + '/overview', {
                    headers: headers(),
                    params: { search: me.search, page: page, limit: me.limit, filter: me.filter, sort: me.sort, dir: me.dir }
                }).then(function (r) { me.stats = r.data.stats; me.list = r.data.list; })
                    .catch(function (e) { me.fail(e); })
                    .then(function () { me.loading = false; });
            },
            open: function (id) {
                var me = this;
                me.drawerLoading = true;
                http().get(BASE + '/customer/' + id, { headers: headers() })
                    .then(function (r) { me.detail = r.data; me.book = { points: null, reason: '', preset: '' }; })
                    .catch(function (e) { me.fail(e); })
                    .then(function () { me.drawerLoading = false; });
            },
            close: function () { this.detail = null; this.drawerLoading = false; },
            applyPreset: function () { if (this.book.preset) { this.book.reason = this.book.preset; } },
            doBook: function () {
                var me = this, p = Number(me.book.points);
                var msg = (p > 0 ? '+' : '') + p + ' Punkte buchen für ' + me.detail.name + '?\nBegründung: ' + me.book.reason;
                if ((p < 0 || Math.abs(p) >= 1000) && !window.confirm(msg)) { return; }
                http().post(BASE + '/customer/' + me.detail.id + '/book', { points: p, reason: me.book.reason }, { headers: headers() })
                    .then(function () {
                        me.createNotificationSuccess({ message: 'Gebucht: ' + (p > 0 ? '+' : '') + p + ' Punkte.' });
                        me.open(me.detail.id);
                        me.load(me.page);
                    })
                    .catch(function (e) { me.fail(e); });
            },
            doBlock: function (block) {
                var me = this;
                if (block && !window.confirm('Kunde sperren? Vorhandene Punkte bleiben, es gibt aber keine neuen Punkte und kein Einlösen mehr.')) { return; }
                http().post(BASE + '/customer/' + me.detail.id + '/block', { block: block }, { headers: headers() })
                    .then(function () {
                        me.createNotificationSuccess({ message: block ? 'Kunde gesperrt.' : 'Sperre aufgehoben.' });
                        me.open(me.detail.id);
                        me.load(me.page);
                    })
                    .catch(function (e) { me.fail(e); });
            },
            goCustomer: function (id) { this.$router.push({ name: 'sw.customer.detail', params: { id: id } }); },
            goOrder: function (id) { this.$router.push({ name: 'sw.order.detail', params: { id: id } }); },


            eggClick: function () {
                this.eggClicks += 1;
                if (this.eggClicks >= 7) { this.eggClicks = 0; this.celebrate(); }
            },
            celebrate: function () {
                this.egg = true;
                if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
                var cv = document.createElement('canvas');
                cv.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:99999';
                cv.width = window.innerWidth;
                cv.height = window.innerHeight;
                document.body.appendChild(cv);
                var ctx = cv.getContext('2d'), colors = ['#0649a8', '#27832a', '#35a936', '#e7f1fa', '#f5b700'], parts = [], i;
                for (i = 0; i < 160; i++) {
                    parts.push({ x: cv.width / 2, y: cv.height / 3, vx: (Math.random() - 0.5) * 16, vy: Math.random() * -14 - 4, s: 5 + Math.random() * 7, c: colors[i % colors.length], r: Math.random() * 6, vr: (Math.random() - 0.5) * 0.4 });
                }
                var start = Date.now();
                (function frame() {
                    var age = Date.now() - start;
                    ctx.clearRect(0, 0, cv.width, cv.height);
                    parts.forEach(function (q) {
                        q.vy += 0.35; q.x += q.vx; q.y += q.vy; q.r += q.vr;
                        ctx.save(); ctx.translate(q.x, q.y); ctx.rotate(q.r); ctx.fillStyle = q.c;
                        ctx.globalAlpha = Math.max(0, 1 - age / 3200);
                        ctx.fillRect(-q.s / 2, -q.s / 2, q.s, q.s * 0.6);
                        ctx.restore();
                    });
                    if (age < 3200) { window.requestAnimationFrame(frame); } else if (cv.parentNode) { cv.parentNode.removeChild(cv); }
                })();
            },
            openSettings: function () {
                this.tab = 'settings';
                if (!this.cfgLoaded) { this.loadSettings(); }
            },
            loadSettings: function () {
                var me = this, P = 'MaxxpetBonus.config.';
                http().get('/_action/system-config', { headers: headers(), params: { domain: 'MaxxpetBonus.config' } })
                    .then(function (r) {
                        var c = {}, k;
                        for (k in r.data) { if (k.indexOf(P) === 0) { c[k.slice(P.length)] = r.data[k]; } }
                        var def = { active: true, enrollment: 'auto', customerGroupIds: [], pointsPerEuro: 1, pointValue: 0.03, lockDays: 0, minRedeem: 100, minOrderValue: 0, maxRedeemPercent: 0, combineWithLevelDiscount: true, levelModel: 'lifetime', pointsRegistration: 0, pointsNewsletter: 0, pointsReview: 0, pointsBirthday: 0, referralEnabled: false, referralPointsPerEuro: 0.5, referralWelcome: 0 };
                        for (k in def) { if (c[k] === undefined || c[k] === null || c[k] === '') { c[k] = def[k]; } }
                        if (!Array.isArray(c.customerGroupIds)) { c.customerGroupIds = []; }
                        me.cfg = c;
                        me.levelRows = me.parseLevels(c.levelsText);
                        me.giftRows = me.parseGifts(c.giftRules);
                        me.cfgLoaded = true;
                    })
                    .catch(function (e) { me.fail(e); });
                http().post('/search/customer-group', { limit: 100 }, { headers: headers() })
                    .then(function (r) {
                        me.groups = (r.data.data || []).map(function (g) {
                            var a = g.attributes || g;
                            return { id: g.id, name: (a.translated && a.translated.name) || a.name || g.id };
                        });
                    })
                    .catch(function () { me.groups = []; });
            },
            parseLevels: function (text) {
                var rows = [];
                String(text || '').split(/\r?\n/).forEach(function (line) {
                    line = line.trim();
                    if (!line || line.charAt(0) === '#') { return; }
                    var p = line.split(';').map(function (x) { return x.trim(); });
                    if (p.length < 2 || !p[0]) { return; }
                    rows.push({ name: p[0], min: parseInt(p[1], 10) || 0, mult: parseFloat((p[2] || '1').replace(',', '.')) || 1, discount: parseFloat((p[3] || '0').replace(',', '.')) || 0 });
                });
                if (!rows.length) { rows = [{ name: 'Bronze', min: 0, mult: 1, discount: 0 }, { name: 'Silber', min: 500, mult: 1.15, discount: 0 }, { name: 'Gold', min: 2000, mult: 1.3, discount: 0 }]; }
                return rows;
            },
            parseGifts: function (text) {
                var rows = [];
                String(text || '').split(/\r?\n/).forEach(function (line) {
                    line = line.trim();
                    if (!line || line.charAt(0) === '#') { return; }
                    var p = line.split(';').map(function (x) { return x.trim(); });
                    if (p.length < 2 || !p[0]) { return; }
                    rows.push({ sku: p[0], label: p[1], min: parseFloat((p[2] || '0').replace(',', '.')) || 0, level: p[3] || '', mode: (p[4] || '').toLowerCase() === 'wahl' ? 'wahl' : 'auto' });
                });
                return rows;
            },
            addLevel: function () {
                var last = this.levelRows[this.levelRows.length - 1] || { min: 0, mult: 1 };
                this.levelRows.push({ name: '', min: (Number(last.min) || 0) + 1000, mult: Number(last.mult) || 1, discount: 0 });
            },
            clean: function (s) { return String(s || '').replace(/[;\r\n]/g, ' ').trim(); },
            saveSettings: function () {
                var me = this, c = me.cfg, P = 'MaxxpetBonus.config.';
                function num(v, d) { var n = Number(v); return isFinite(n) && v !== '' && v !== null ? n : d; }
                var levels = [];
                if (c.levelModel !== 'off') {
                    var seen = {};
                    for (var i = 0; i < me.levelRows.length; i++) {
                        var l = me.levelRows[i], name = me.clean(l.name), min = i === 0 ? 0 : Math.max(0, Math.round(num(l.min, 0)));
                        if (!name) { me.createNotificationError({ message: 'Jede Stufe braucht einen Namen.' }); return; }
                        if (seen[min]) { me.createNotificationError({ message: 'Zwei Stufen haben dieselbe Schwelle (' + min + ' Punkte).' }); return; }
                        seen[min] = true;
                        levels.push(name + ';' + min + ';' + num(l.mult, 1) + ';' + num(l.discount, 0));
                    }
                }
                var gifts = [];
                for (var j = 0; j < me.giftRows.length; j++) {
                    var g = me.giftRows[j], sku = me.clean(g.sku), label = me.clean(g.label);
                    if (!sku && !label) { continue; }
                    if (!sku || !label) { me.createNotificationError({ message: 'Gratisprodukt: Artikelnummer und Bezeichnung sind Pflicht.' }); return; }
                    gifts.push(sku + ';' + label + ';' + num(g.min, 0) + ';' + me.clean(g.level) + ';' + (g.mode === 'wahl' ? 'wahl' : 'auto'));
                }
                var payload = {};
                payload[P + 'active'] = !!c.active;
                payload[P + 'enrollment'] = c.enrollment === 'optin' ? 'optin' : 'auto';
                payload[P + 'customerGroupIds'] = c.customerGroupIds || [];
                payload[P + 'pointsPerEuro'] = num(c.pointsPerEuro, 1);
                payload[P + 'pointValue'] = num(c.pointValue, 0.03) > 0 ? num(c.pointValue, 0.03) : 0.03;
                payload[P + 'lockDays'] = Math.max(0, Math.round(num(c.lockDays, 0)));
                payload[P + 'minRedeem'] = Math.max(1, Math.round(num(c.minRedeem, 100)));
                payload[P + 'minOrderValue'] = Math.max(0, num(c.minOrderValue, 0));
                payload[P + 'maxRedeemPercent'] = Math.min(100, Math.max(0, Math.round(num(c.maxRedeemPercent, 0))));
                payload[P + 'combineWithLevelDiscount'] = !!c.combineWithLevelDiscount;
                payload[P + 'levelModel'] = c.levelModel;
                if (c.levelModel !== 'off') { payload[P + 'levelsText'] = levels.join('\n'); }
                payload[P + 'pointsRegistration'] = Math.max(0, Math.round(num(c.pointsRegistration, 0)));
                payload[P + 'pointsNewsletter'] = Math.max(0, Math.round(num(c.pointsNewsletter, 0)));
                payload[P + 'pointsReview'] = Math.max(0, Math.round(num(c.pointsReview, 0)));
                payload[P + 'pointsBirthday'] = Math.max(0, Math.round(num(c.pointsBirthday, 0)));
                payload[P + 'referralEnabled'] = !!c.referralEnabled;
                payload[P + 'referralPointsPerEuro'] = Math.max(0, num(c.referralPointsPerEuro, 0.5));
                payload[P + 'referralWelcome'] = Math.max(0, Math.round(num(c.referralWelcome, 0)));
                payload[P + 'giftRules'] = gifts.join('\n');
                me.saving = true;
                http().post('/_action/system-config', payload, { headers: headers() })
                    .then(function () { me.createNotificationSuccess({ message: 'Einstellungen gespeichert.' }); me.load(me.page); })
                    .catch(function (e) { me.fail(e); })
                    .then(function () { me.saving = false; });
            },
            exportCsv: function () {
                var me = this;
                http().get(BASE + '/export', { headers: headers(), responseType: 'blob' })
                    .then(function (r) {
                        var url = window.URL.createObjectURL(new Blob([r.data], { type: 'text/csv;charset=utf-8' }));
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = 'bonuspunkte-' + new Date().toISOString().slice(0, 10) + '.csv';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(url);
                    })
                    .catch(function (e) { me.fail(e); });
            },
            doImport: function (apply) {
                var me = this;
                if (apply && !window.confirm('Punkte für alle gefundenen Bestellungen jetzt wirklich buchen?')) { return; }
                http().post(BASE + '/import', { since: me.imp.since, apply: apply }, { headers: headers() })
                    .then(function (r) {
                        var d = r.data;
                        me.imp.previewed = !apply && d.count > 0;
                        me.imp.result = d.count + ' Bestellung(en) gefunden' + (apply ? ', ' + d.booked + ' gebucht' : ' (Vorschau, noch nichts gebucht)') + '\n' + d.lines.join('\n');
                        if (apply) { me.imp.previewed = false; me.load(1); }
                    })
                    .catch(function (e) { me.fail(e); });
            }
        }
    });

    Shopware.Module.register('maxxpet-bonus', {
        type: 'plugin',
        name: 'maxxpet-bonus',
        title: 'Bonuspunkte',
        description: 'Treuepunkte, Level und Empfehlungen verwalten',
        color: '#27832a',
        icon: 'regular-star',
        routes: {
            index: {
                component: 'maxxpet-bonus-index',
                path: 'index',
                meta: { parentPath: 'sw.marketing.index' }
            }
        },
        navigation: [{
            id: 'maxxpet-bonus',
            label: 'Bonuspunkte',
            color: '#27832a',
            icon: 'regular-star',
            path: 'maxxpet.bonus.index',
            parent: 'sw-marketing',
            position: 100
        }]
    });
})();
