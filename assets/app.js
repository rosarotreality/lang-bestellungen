/* =========================================================
   Metzgerei Lang – Bestellseite (ohne Abhängigkeiten)
   - Sortiment & Einstellungen kommen aus data/shop.json
   - Warenkorb wird im localStorage des Browsers gehalten
     (technisch notwendig, kein Tracking, kein Cookie)
   - Bestellung geht per POST an api/bestellung.php
   ========================================================= */
(() => {
  'use strict';

  const DATEN_URL = 'data/shop.json';
  const API_URL = 'api/bestellung.php';
  const SPEICHER_KORB = 'lang-bestellung-korb-v1';
  const SPEICHER_KONTAKT = 'lang-bestellung-kontakt-v1';
  const KORB_GUELTIG_TAGE = 3;

  const WOCHENTAGE = ['so', 'mo', 'di', 'mi', 'do', 'fr', 'sa'];
  const WT_KURZ = { mo: 'Mo', di: 'Di', mi: 'Mi', do: 'Do', fr: 'Fr', sa: 'Sa', so: 'So' };
  const WT_LANG = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
  const MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const euro = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
  const fmtEuro = (cent) => euro.format(cent / 100);
  const pad = (n) => String(n).padStart(2, '0');

  let shop = null;
  const produkte = new Map();
  let korb = [];            // [{ id, option, menge }]
  const auswahl = {};       // Auswahl je Produktkarte: { menge, option }
  let termin = { datum: null, zeit: null };
  let tage = [];            // [{ iso, wt, datumKurz, slots: [{ zeit, gruppe }] }]
  let formStart = 0;
  let sendet = false;
  let letzteBestellung = null;
  let istTest = false;      // Testmodus: Bestellung wird nur simuliert (z. B. auf GitHub Pages)

  /* ---------------- Speicher (fehlertolerant) ---------------- */
  const speicher = {
    lesen(store, key) {
      try { return JSON.parse(window[store].getItem(key) || 'null'); } catch { return null; }
    },
    schreiben(store, key, wert) {
      try { window[store].setItem(key, JSON.stringify(wert)); } catch { /* privater Modus o. Ä. */ }
    },
    loeschen(store, key) {
      try { window[store].removeItem(key); } catch { /* egal */ }
    },
  };

  /* ---------------- Produkt-Helfer ---------------- */
  const istCa = (p) => p.preis_kg != null;
  const zeilenPreis = (p, menge) => (hatPreis(p) ? `${istCa(p) ? 'ca. ' : ''}${fmtEuro(preisCent(p, menge))}` : '');
  const einheitLabel = (p) => (p.einheit === 'kg' ? 'kg' : (p.einheit_label || 'Stück'));

  // Ganzzahlig in Cent rechnen – identisch zu api/bestellung.php
  const centKg = (p) => Math.round(p.preis_kg * 100);
  const hatPreis = (p) => p.preis_kg != null || p.preis_stueck != null;
  let shopHatPreise = true;   // false, solange im Sortiment noch gar keine Preise stehen
  function preisCent(p, menge) {
    if (!hatPreis(p)) return null;
    if (p.einheit === 'kg') return Math.round((centKg(p) * menge) / 1000);              // €/kg × g
    if (p.preis_stueck != null) return Math.round(p.preis_stueck * 100) * menge;        // fester Stückpreis
    return Math.round((centKg(p) * p.stueckgewicht_g) / 1000) * menge;                  // Stück mit kg-Preis
  }

  function mengeText(p, menge) {
    if (p.einheit === 'kg') {
      return menge >= 1000
        ? `${(menge / 1000).toLocaleString('de-DE', { maximumFractionDigits: 3 })} kg`
        : `${menge} g`;
    }
    // Mehrzahl, z. B. "1 Person" / "4 Personen"
    const label = menge !== 1 && p.einheit_label_mehrzahl ? p.einheit_label_mehrzahl : einheitLabel(p);
    return `${menge} ${label}`;
  }

  function preisZeile(p) {
    if (!hatPreis(p)) return '';
    if (p.einheit === 'kg') return `<strong>${fmtEuro(Math.round(p.preis_kg * 100))}</strong> / kg`;
    if (p.preis_stueck != null) return `<strong>${fmtEuro(Math.round(p.preis_stueck * 100))}</strong> / ${esc(einheitLabel(p))}`;
    return `<strong>ca. ${fmtEuro(preisCent(p, 1))}</strong> / ${esc(einheitLabel(p))} · ca. ${p.stueckgewicht_g} g · ${fmtEuro(Math.round(p.preis_kg * 100))}/kg`;
  }

  function mengeKorrigieren(p, m) {
    if (p.einheit === 'kg') {
      // Gramm frei eingebbar: nur ganze Gramm, zwischen min und max
      m = Math.round(Number(String(m).replace(',', '.')));
      if (!Number.isFinite(m) || m <= 0) m = p.min;
      return Math.min(p.max, Math.max(p.min, m));
    }
    m = Number(m) || p.min;
    m = Math.min(p.max, Math.max(p.min, m));
    m = p.min + Math.round((m - p.min) / p.schritt) * p.schritt;
    return Math.min(p.max, Math.max(p.min, m));
  }

  const optionen = (p) => (p.optionen && Array.isArray(p.optionen.werte) ? p.optionen.werte : []);
  const korbKey = (id, option) => `${id}|${option || ''}`;

  /* ---------------- Start ---------------- */
  async function start() {
    try {
      const res = await fetch(DATEN_URL, { cache: 'no-cache' });
      if (!res.ok) throw new Error(res.status);
      shop = await res.json();
    } catch (e) {
      $('#produkte').innerHTML = `<div class="karte"><p>Das Sortiment konnte leider nicht geladen werden.</p>
        <p class="klein">Bitte versuchen Sie es später erneut oder bestellen Sie telefonisch.</p></div>`;
      return;
    }

    shopHatPreise = (shop.produkte || []).some(hatPreis);
    (shop.produkte || []).forEach((p) => {
      p.min = p.min ?? (p.einheit === 'kg' ? 250 : 1);
      p.schritt = p.schritt ?? (p.einheit === 'kg' ? 250 : 1);
      p.max = p.max ?? (p.einheit === 'kg' ? 5000 : 50);
      produkte.set(p.id, p);
      auswahl[p.id] = { menge: mengeKorrigieren(p, p.start ?? p.min), option: optionen(p)[0] || '' };
    });

    const tm = shop.testmodus ?? 'auto';
    istTest = tm === true || (tm === 'auto' && (/\.github\.io$/i.test(location.hostname) || location.protocol === 'file:'));
    const hinweis = $('#demo-hinweis');
    if (istTest) {
      hinweis.innerHTML = '<strong>Testmodus</strong> – Bestellungen werden nicht verschickt.'
        + (shop.demo_hinweis ? ' Preise, Mengen und Abholzeiten sind noch vorläufig.' : '');
      hinweis.classList.add('demo-hinweis--test');
    }
    hinweis.hidden = !(istTest || shop.demo_hinweis);
    betriebEinsetzen();
    hinweiseEinsetzen();
    korbLaden();
    renderKategorien();
    renderProdukte();
    renderKorb();
    bindEvents();
    route();
  }

  function betriebEinsetzen() {
    const b = shop.betrieb || {};
    const links = { website: b.website, impressum: b.impressum, datenschutz: b.datenschutz };
    $$('[data-link]').forEach((a) => { if (links[a.dataset.link]) a.href = links[a.dataset.link]; });
    $('#fuss-adresse').innerHTML = `${esc(b.name)}<br>${esc(b.strasse)}<br>${esc(b.ort)}`;
    const tel = (b.telefon || '').replace(/[^\d+]/g, '');
    $('#fuss-kontakt').innerHTML = `Telefon <a href="tel:${esc(tel)}">${esc(b.telefon)}</a><br><a href="mailto:${esc(b.email)}">${esc(b.email)}</a>`;
    $('#fuss-zeiten').innerHTML = oeffnungszeitenText();
    $('#kopf-adresse').innerHTML = `${esc(b.strasse)} · ${esc(b.ort)}<span class="trenner"> · </span><a href="tel:${esc(tel)}">Tel. ${esc(b.telefon)}</a>`;
    $('#zahlung-hinweis').textContent = b.zahlung_hinweis || '';
  }

  function oeffnungszeitenText() {
    const oz = shop.abholung.oeffnungszeiten || {};
    const reihenfolge = ['mo', 'di', 'mi', 'do', 'fr', 'sa', 'so'];
    const gruppen = new Map();
    reihenfolge.forEach((t, i) => {
      const iv = oz[t] || [];
      if (!iv.length) return;
      const txt = iv.map(([v, b]) => `${v}–${b}`).join(' · ');
      if (!gruppen.has(txt)) gruppen.set(txt, []);
      gruppen.get(txt).push(i);
    });
    const zeilen = [];
    gruppen.forEach((idx, txt) => {
      const teile = [];
      for (let i = 0; i < idx.length; i++) {
        let j = i;
        while (j + 1 < idx.length && idx[j + 1] === idx[j] + 1) j++;
        const lauf = idx.slice(i, j + 1).map((n) => WT_KURZ[reihenfolge[n]]);
        teile.push(lauf.length >= 3 ? `${lauf[0]}–${lauf[lauf.length - 1]}` : lauf.join(', '));
        i = j;
      }
      zeilen.push(`<span class="oz"><span class="oz__tage">${teile.join(', ')}</span>${esc(txt)}</span>`);
    });
    return zeilen.join('') || 'Werden bekanntgegeben.';
  }

  // Bestellschluss (z. B. "2026-12-20T12:00", Uhrzeit in Deutschland)
  function bestellschlussVorbei() {
    const bs = shop.abholung && shop.abholung.bestellschluss;
    return !!bs && berlinJetzt() > Date.parse(`${bs.length === 16 ? bs + ':00' : bs}Z`);
  }
  function bestellschlussText() {
    const bs = shop.abholung && shop.abholung.bestellschluss;
    if (!bs) return '';
    return `${terminText(bs.slice(0, 10), bs.slice(11, 16))}`;
  }

  function hinweiseEinsetzen() {
    const liste = [...(shop.hinweise || [])];
    if (shop.abholung && shop.abholung.bestellschluss && !bestellschlussVorbei()) {
      liste.unshift(`<strong>Bestellschluss:</strong> ${esc(bestellschlussText())}`);
    }
    const box = $('#hinweise');
    box.hidden = !liste.length;
    box.innerHTML = liste.map((h) => `<p>${h}</p>`).join('');   // Texte stammen aus shop.json (eigene Pflege)

    if (bestellschlussVorbei()) {
      document.body.classList.add('geschlossen');
      const b = shop.betrieb;
      const banner = $('#demo-hinweis');
      banner.innerHTML = `Die Online-Bestellung ist geschlossen. Gerne nehmen wir Ihre Bestellung telefonisch entgegen: <a href="tel:${esc((b.telefon || '').replace(/[^\d+]/g, ''))}">${esc(b.telefon)}</a>`;
      banner.classList.add('demo-hinweis--test');
      banner.hidden = false;
    }
  }

  /* ---------------- Warenkorb laden/speichern ---------------- */
  function korbLaden() {
    const gespeichert = speicher.lesen('localStorage', SPEICHER_KORB);
    korb = [];
    if (!gespeichert || !Array.isArray(gespeichert.positionen)) return;
    if (Date.now() - (gespeichert.zeit || 0) > KORB_GUELTIG_TAGE * 864e5) { speicher.loeschen('localStorage', SPEICHER_KORB); return; }
    gespeichert.positionen.forEach((pos) => {
      const p = produkte.get(pos.id);
      if (!p || p.verfuegbar === false) return;
      const opts = optionen(p);
      const option = opts.length ? (opts.includes(pos.option) ? pos.option : null) : '';
      if (option === null) return;
      korb.push({ id: p.id, option, menge: mengeKorrigieren(p, pos.menge), notiz: typeof pos.notiz === 'string' ? pos.notiz.slice(0, 120) : '' });
    });
  }

  function korbSpeichern() {
    if (!korb.length) { speicher.loeschen('localStorage', SPEICHER_KORB); return; }
    speicher.schreiben('localStorage', SPEICHER_KORB, { zeit: Date.now(), positionen: korb });
  }

  const korbSumme = () => korb.reduce((s, pos) => s + (preisCent(produkte.get(pos.id), pos.menge) || 0), 0);
  const summeText = () => `${korbIstCa() ? 'ca. ' : ''}${fmtEuro(korbSumme())}`;
  function korbHinweis() {
    const teile = [];
    if (korb.some((pos) => !hatPreis(produkte.get(pos.id)))) {
      teile.push(shopHatPreise ? 'Artikel ohne Preisangabe berechnen wir bei Abholung.' : 'Abgerechnet wird bei Abholung nach tatsächlichem Gewicht bzw. Stückzahl.');
    } else if (korbIstCa()) teile.push('Bei Gewichtsware rechnen wir nach tatsächlichem Gewicht ab.');
    teile.push(shop.betrieb.zahlung_hinweis || '');
    return teile.join(' ');
  }
  const korbIstCa = () => korb.some((pos) => istCa(produkte.get(pos.id)));

  /* ---------------- Rendern: Sortiment ---------------- */
  function renderKategorien() {
    const vorhanden = new Set(shop.produkte.map((p) => p.kategorie));
    $('#kategorien').innerHTML = shop.kategorien
      .filter((k) => vorhanden.has(k.id))
      .map((k) => `<a href="#kat-${esc(k.id)}" data-kat="${esc(k.id)}">${esc(k.name)}</a>`)
      .join('');
  }

  function renderProdukte() {
    const html = shop.kategorien.map((k) => {
      const liste = shop.produkte.filter((p) => p.kategorie === k.id);
      if (!liste.length) return '';
      return `<section class="kategorie" id="kat-${esc(k.id)}" aria-labelledby="h-${esc(k.id)}">
        <div class="kategorie__kopf"><h2 id="h-${esc(k.id)}">${esc(k.name)}</h2><span class="balken" aria-hidden="true"></span></div>
        ${liste.map(produktHtml).join('')}
      </section>`;
    }).join('');
    $('#produkte').innerHTML = html;
    beobachteKategorien();
  }

  function produktHtml(p) {
    const aus = p.verfuegbar === false;
    const a = auswahl[p.id];
    const opts = optionen(p);
    const bild = p.bild ? `<img class="produkt__bild" src="${esc(p.bild)}" alt="" loading="lazy">` : '';
    const steuerung = aus
      ? '<span class="produkt__aus">Derzeit nicht verfügbar</span>'
      : `${opts.length ? `<select class="auswahl" data-option="${esc(p.id)}" aria-label="${esc(p.optionen.label || 'Ausführung')} für ${esc(p.name)}">
            ${opts.map((o) => `<option${o === a.option ? ' selected' : ''}>${esc(o)}</option>`).join('')}
          </select>` : ''}
          ${mengeHtml(p, a.menge, `data-karte="${esc(p.id)}"`)}
          <button class="btn btn--klein" type="button" data-hinzu="${esc(p.id)}">In den Korb</button>`;
    return `<article class="produkt${bild ? ' produkt--bild' : ''}${aus ? ' produkt--aus' : ''}">
      ${bild}
      <div>
        <h3>${esc(p.name)}</h3>
        ${p.beschreibung ? `<p class="produkt__text">${esc(p.beschreibung)}</p>` : ''}
        <p class="produkt__preis">${preisZeile(p)}</p>
      </div>
      <div class="produkt__steuerung">${steuerung}</div>
    </article>`;
  }

  function mengeHtml(p, menge, attr, klein = false) {
    return `<div class="menge${klein ? ' menge--klein' : ''}" ${attr}>
      <button type="button" data-schritt="-1" aria-label="Weniger ${esc(p.name)}"${menge <= p.min ? ' disabled' : ''}>−</button>
      ${p.einheit === 'kg'
        ? `<label class="menge__feld"><input class="menge__eingabe" type="number" inputmode="numeric" min="${p.min}" max="${p.max}" step="1"
             value="${menge}" aria-label="Menge in Gramm für ${esc(p.name)} (${p.min}–${p.max} g)"><span aria-hidden="true">g</span></label>`
        : `<output aria-live="polite">${esc(mengeText(p, menge))}</output>`}
      <button type="button" data-schritt="1" aria-label="Mehr ${esc(p.name)}"${menge >= p.max ? ' disabled' : ''}>+</button>
    </div>`;
  }

  function mengeAnzeigeAktualisieren(el, p, menge) {
    const feld = $('.menge__eingabe', el);
    if (feld) feld.value = menge; else $('output', el).textContent = mengeText(p, menge);
    $('[data-schritt="-1"]', el).disabled = menge <= p.min;
    $('[data-schritt="1"]', el).disabled = menge >= p.max;
  }

  function beobachteKategorien() {
    if (!('IntersectionObserver' in window)) return;
    const links = $$('#kategorien a');
    const obs = new IntersectionObserver((eintraege) => {
      eintraege.forEach((e) => {
        if (!e.isIntersecting) return;
        links.forEach((l) => l.classList.toggle('aktiv', `kat-${l.dataset.kat}` === e.target.id));
        const aktiv = links.find((l) => l.classList.contains('aktiv'));
        if (aktiv) aktiv.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
      });
    }, { rootMargin: '-80px 0px -65% 0px' });
    $$('.kategorie').forEach((s) => obs.observe(s));
  }

  /* ---------------- Rendern: Warenkorb ---------------- */
  function renderKorb() {
    const inhalt = $('#korb-inhalt');
    if (!korb.length) {
      inhalt.innerHTML = '<p class="korb__leer">Noch nichts ausgewählt. Legen Sie Produkte aus dem Sortiment in den Warenkorb.</p>';
    } else {
      inhalt.innerHTML = korb.map((pos, i) => {
        const p = produkte.get(pos.id);
        return `<div class="zeile">
          <div class="zeile__name">${esc(p.name)}${pos.option ? `<span class="zeile__option">${esc(pos.option)}</span>` : ''}</div>
          <div class="zeile__preis">${zeilenPreis(p, pos.menge)}</div>
          <div class="zeile__steuerung">
            ${mengeHtml(p, pos.menge, `data-zeile="${i}"`, true)}
            <button class="zeile__entfernen" type="button" data-entfernen="${i}">Entfernen</button>
          </div>
          <input class="zeile__notiz" type="text" data-notiz="${i}" maxlength="120" value="${esc(pos.notiz || '')}"
            placeholder="${esc(p.notiz_hinweis || 'Notiz, z. B. Zuschnitt oder Personenzahl')}" aria-label="Notiz zu ${esc(p.name)}">
        </div>`;
      }).join('');
    }
    $('#korb-summe').textContent = summeText();
    $('#korb-summe').parentElement.hidden = !shopHatPreise;
    $('#korb-hinweis').textContent = korb.length ? korbHinweis() : '';
    $('#zur-kasse').disabled = !korb.length || bestellschlussVorbei();

    const anzahl = korb.length;
    $('#leiste-anzahl').textContent = `${anzahl} ${anzahl === 1 ? 'Position' : 'Positionen'}`;
    $('#leiste-summe').textContent = shopHatPreise ? summeText() : '';
    leisteAktualisieren();
  }

  function leisteAktualisieren() {
    const zeigen = korb.length > 0 && aktuelleAnsicht() === 'sortiment';
    $('#leiste').hidden = !zeigen;
    document.body.classList.toggle('mit-leiste', zeigen);
  }

  function korbOeffnen(auf) {
    $('#korb').classList.toggle('offen', auf);
    $('#abdunkeln').hidden = !auf;
    document.body.style.overflow = auf ? 'hidden' : '';
    if (auf) $('#korb .korb__schliessen').focus();
  }

  const ansagen = (text) => { const el = $('#ansage'); el.textContent = ''; setTimeout(() => { el.textContent = text; }, 30); };

  /* ---------------- Aktionen ---------------- */
  function hinzufuegen(id, knopf) {
    const p = produkte.get(id);
    const a = auswahl[id];
    const key = korbKey(id, a.option);
    const vorhanden = korb.find((pos) => korbKey(pos.id, pos.option) === key);
    if (vorhanden) vorhanden.menge = mengeKorrigieren(p, vorhanden.menge + a.menge);
    else korb.push({ id, option: a.option, menge: a.menge, notiz: '' });
    korbSpeichern();
    renderKorb();

    if (knopf) {
      knopf.textContent = '✓ Im Korb';
      knopf.classList.add('ok');
      clearTimeout(knopf._t);
      knopf._t = setTimeout(() => { knopf.textContent = 'In den Korb'; knopf.classList.remove('ok'); }, 1400);
    }
    const leiste = $('#leiste-summe');
    leiste.classList.remove('puls'); void leiste.offsetWidth; leiste.classList.add('puls');
    ansagen(`${mengeText(p, a.menge)} ${p.name}${a.option ? ` (${a.option})` : ''} in den Warenkorb gelegt.`);
  }

  function bindEvents() {
    // Produktkarten
    $('#produkte').addEventListener('click', (e) => {
      const schritt = e.target.closest('[data-schritt]');
      if (schritt) {
        const box = schritt.closest('[data-karte]');
        if (!box) return;
        const p = produkte.get(box.dataset.karte);
        const a = auswahl[p.id];
        a.menge = mengeKorrigieren(p, a.menge + Number(schritt.dataset.schritt) * p.schritt);
        mengeAnzeigeAktualisieren(box, p, a.menge);
        return;
      }
      const hinzu = e.target.closest('[data-hinzu]');
      if (hinzu) hinzufuegen(hinzu.dataset.hinzu, hinzu);
    });
    $('#produkte').addEventListener('change', (e) => {
      const sel = e.target.closest('[data-option]');
      if (sel) auswahl[sel.dataset.option].option = sel.value;
      const feld = e.target.closest('.menge__eingabe');
      if (feld) {
        const box = feld.closest('[data-karte]');
        const p = produkte.get(box.dataset.karte);
        auswahl[p.id].menge = mengeKorrigieren(p, feld.value);
        mengeAnzeigeAktualisieren(box, p, auswahl[p.id].menge);
      }
    });
    // Eingetippte Gramm sofort übernehmen (Korrektur auf min/max erst beim Verlassen des Feldes)
    $('#produkte').addEventListener('input', (e) => {
      const feld = e.target.closest('.menge__eingabe');
      if (!feld) return;
      const p = produkte.get(feld.closest('[data-karte]').dataset.karte);
      const wert = Math.round(Number(feld.value));
      if (wert >= p.min && wert <= p.max) auswahl[p.id].menge = wert;
    });
    $('#produkte').addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.closest('.menge__eingabe')) { e.preventDefault(); e.target.blur(); }
    });

    // Kategorien-Navigation (ohne Hash, damit das Routing nicht stört)
    $('#kategorien').addEventListener('click', (e) => {
      const a = e.target.closest('a[data-kat]');
      if (!a) return;
      e.preventDefault();
      document.getElementById(`kat-${a.dataset.kat}`)?.scrollIntoView({ behavior: 'smooth' });
    });

    // Warenkorb
    $('#korb-inhalt').addEventListener('change', (e) => {
      const feld = e.target.closest('.menge__eingabe');
      if (!feld) return;
      const i = Number(feld.closest('[data-zeile]').dataset.zeile);
      const pos = korb[i];
      if (!pos) return;
      pos.menge = mengeKorrigieren(produkte.get(pos.id), feld.value);
      korbSpeichern(); renderKorb();
    });
    $('#korb-inhalt').addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.closest('.menge__eingabe')) { e.preventDefault(); e.target.blur(); }
    });

    $('#korb-inhalt').addEventListener('click', (e) => {
      const schritt = e.target.closest('[data-schritt]');
      if (schritt) {
        const i = Number(schritt.closest('[data-zeile]').dataset.zeile);
        const pos = korb[i];
        const p = produkte.get(pos.id);
        pos.menge = mengeKorrigieren(p, pos.menge + Number(schritt.dataset.schritt) * p.schritt);
        korbSpeichern(); renderKorb();
        $(`[data-zeile="${i}"] [data-schritt="${schritt.dataset.schritt}"]`)?.focus();
        return;
      }
      const weg = e.target.closest('[data-entfernen]');
      if (weg) {
        const pos = korb.splice(Number(weg.dataset.entfernen), 1)[0];
        korbSpeichern(); renderKorb();
        ansagen(`${produkte.get(pos.id).name} entfernt.`);
      }
    });

    $('#korb-inhalt').addEventListener('input', (e) => {
      const feld = e.target.closest('[data-notiz]');
      if (!feld) return;
      const pos = korb[Number(feld.dataset.notiz)];
      if (pos) { pos.notiz = feld.value.slice(0, 120); korbSpeichern(); }
    });

    // Globale Aktionen
    document.addEventListener('click', (e) => {
      const el = e.target.closest('[data-aktion]');
      if (!el) return;
      const aktion = el.dataset.aktion;
      if (aktion === 'korb-auf') korbOeffnen(true);
      if (aktion === 'korb-zu') korbOeffnen(false);
      if (aktion === 'zur-kasse' && korb.length) { korbOeffnen(false); location.hash = 'kasse'; }
      if (aktion === 'zum-sortiment') location.hash = '';
      if (aktion === 'neu') { letzteBestellung = null; location.hash = ''; }
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && $('#korb').classList.contains('offen')) korbOeffnen(false); });

    // Kasse
    $('#tage').addEventListener('click', (e) => {
      const b = e.target.closest('[data-datum]');
      if (!b) return;
      termin = { datum: b.dataset.datum, zeit: null };
      renderTage(); renderZeiten(); renderTermin();
      feldFehler('datum', '');
      $('#zeit-block').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
    $('#zeiten').addEventListener('click', (e) => {
      const b = e.target.closest('[data-zeit]');
      if (!b) return;
      termin.zeit = b.dataset.zeit;
      renderZeiten(); renderTermin();
      feldFehler('zeit', '');
    });
    [$('#tage'), $('#zeiten')].forEach((gruppe) => gruppe.addEventListener('keydown', pfeilNavigation));

    const form = $('#bestellformular');
    form.addEventListener('input', (e) => {
      if (e.target.name) {
        kontaktSpeichern();
        const feld = e.target.closest('.feld');
        if (feld && feld.classList.contains('feld--fehler')) setzeFeldFehler(e.target.name, '');
      }
    });
    form.addEventListener('submit', absenden);

    window.addEventListener('hashchange', route);
    window.addEventListener('storage', (e) => {
      if (e.key !== SPEICHER_KORB) return;
      korbLaden(); renderKorb();
      if (aktuelleAnsicht() === 'kasse') renderUebersicht();
    });
  }

  function pfeilNavigation(e) {
    if (!['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp'].includes(e.key)) return;
    const knoepfe = $$('button', e.currentTarget);
    const i = knoepfe.indexOf(document.activeElement);
    if (i < 0) return;
    e.preventDefault();
    const n = knoepfe[(i + (e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : -1) + knoepfe.length) % knoepfe.length];
    n.focus(); n.click();
  }

  /* ---------------- Routing ---------------- */
  function aktuelleAnsicht() {
    const h = location.hash.replace('#', '');
    if (h === 'kasse' && korb.length && !bestellschlussVorbei()) return 'kasse';
    if (h === 'danke' && letzteBestellung) return 'danke';
    return 'sortiment';
  }

  function route() {
    const ansicht = aktuelleAnsicht();
    if (location.hash && location.hash !== `#${ansicht}` && ansicht === 'sortiment') {
      history.replaceState(null, '', location.pathname + location.search);
    }
    $$('.ansicht').forEach((s) => { s.hidden = s.id !== `ansicht-${ansicht}`; });
    korbOeffnen(false);
    leisteAktualisieren();
    if (ansicht === 'kasse') kasseVorbereiten();
    if (ansicht !== 'sortiment') {
      window.scrollTo({ top: 0, behavior: 'instant' });
      $(`#ansicht-${ansicht} h1`)?.setAttribute('tabindex', '-1');
      $(`#ansicht-${ansicht} h1`)?.focus({ preventScroll: true });
    }
  }

  /* ---------------- Abholtermine ---------------- */
  // Uhrzeit in Deutschland als "Wanduhr"-Millisekunden (UTC-Rechnung ohne Zeitzonen-Sprünge)
  function berlinJetzt() {
    const teile = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(new Date());
    const g = (t) => Number(teile.find((x) => x.type === t).value);
    return Date.UTC(g('year'), g('month') - 1, g('day'), g('hour'), g('minute'));
  }
  const isoTag = (ms) => new Date(ms).toISOString().slice(0, 10);
  const minuten = (hhmm) => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m; };

  function berechneTage() {
    const a = shop.abholung;
    const jetzt = berlinJetzt();
    const fruehestens = jetzt + (a.vorlauf_stunden || 0) * 36e5;
    let start = Date.UTC(new Date(jetzt).getUTCFullYear(), new Date(jetzt).getUTCMonth(), new Date(jetzt).getUTCDate());
    if (a.erster_abholtag) start = Math.max(start, Date.parse(`${a.erster_abholtag}T00:00:00Z`));
    const geschlossen = new Set(a.geschlossen || []);
    const slot = a.slot_minuten || 30;
    const ergebnis = [];

    // Feste Abholtage (z. B. nur 22.–24.12.) haben Vorrang vor dem normalen Zeitraum
    const feste = a.abholtage && typeof a.abholtage === 'object' && Object.keys(a.abholtage).length
      ? Object.keys(a.abholtage).sort() : null;
    const tageListe = feste
      ? feste.map((iso) => Date.parse(`${iso}T00:00:00Z`))
      : Array.from({ length: (a.tage_im_voraus || 14) + 1 }, (_, i) => start + i * 864e5);

    for (const tagMs of tageListe) {
      const iso = isoTag(tagMs);
      if (geschlossen.has(iso)) continue;
      const wtIndex = new Date(tagMs).getUTCDay();
      const eigene = feste && Array.isArray(a.abholtage[iso]) && a.abholtage[iso].length ? a.abholtage[iso] : null;
      const intervalle = eigene || (a.oeffnungszeiten || {})[WOCHENTAGE[wtIndex]] || [];
      const slots = [];
      intervalle.forEach(([von, bis], gruppe) => {
        for (let t = minuten(von); t + slot <= minuten(bis); t += slot) {
          if (tagMs + t * 6e4 >= fruehestens) slots.push({ zeit: `${pad(Math.floor(t / 60))}:${pad(t % 60)}`, gruppe });
        }
      });
      if (!slots.length) continue;
      const d = new Date(tagMs);
      ergebnis.push({
        iso, slots, wtIndex,
        wt: WT_KURZ[WOCHENTAGE[wtIndex]],
        datumKurz: `${pad(d.getUTCDate())}.${pad(d.getUTCMonth() + 1)}.`,
      });
    }
    return ergebnis;
  }

  function terminText(datumIso, zeit) {
    const d = new Date(`${datumIso}T00:00:00Z`);
    return `${WT_LANG[d.getUTCDay()]}, ${d.getUTCDate()}. ${MONATE[d.getUTCMonth()]} ${d.getUTCFullYear()}${zeit ? `, ${zeit} Uhr` : ''}`;
  }

  /* ---------------- Kasse ---------------- */
  function kasseVorbereiten() {
    if (!formStart) formStart = Date.now();
    tage = berechneTage();
    const tag = tage.find((t) => t.iso === termin.datum);
    if (!tag) termin = { datum: null, zeit: null };
    else if (termin.zeit && !tag.slots.some((s) => s.zeit === termin.zeit)) termin.zeit = null;
    renderTage(); renderZeiten(); renderTermin(); renderUebersicht(); kontaktLaden();
    $('#formfehler').hidden = true;
  }

  function renderTage() {
    if (!tage.length) {
      $('#tage').innerHTML = `<p class="keine-tage">Aktuell sind keine Abholtermine buchbar. Bitte rufen Sie uns an: ${esc(shop.betrieb.telefon)}</p>`;
      return;
    }
    $('#tage').innerHTML = tage.map((t) => {
      const an = t.iso === termin.datum;
      return `<button type="button" class="tag" role="radio" aria-checked="${an}" tabindex="${an || (!termin.datum && t === tage[0]) ? 0 : -1}"
        data-datum="${t.iso}" aria-label="${esc(terminText(t.iso))}">
        <span class="tag__wt">${t.wt}</span><span class="tag__datum">${t.datumKurz}</span></button>`;
    }).join('');
  }

  function renderZeiten() {
    const tag = tage.find((t) => t.iso === termin.datum);
    $('#zeit-block').hidden = !tag;
    if (!tag) return;
    const gruppen = new Set(tag.slots.map((s) => s.gruppe));
    let letzte = -1;
    $('#zeiten').innerHTML = tag.slots.map((s, i) => {
      let kopf = '';
      if (gruppen.size > 1 && s.gruppe !== letzte) {
        kopf = `<div class="zeiten__gruppe">${minuten(s.zeit) < 12 * 60 ? 'Vormittag' : 'Nachmittag'}</div>`;
        letzte = s.gruppe;
      }
      const an = s.zeit === termin.zeit;
      return `${kopf}<button type="button" class="zeit" role="radio" aria-checked="${an}" tabindex="${an || (!termin.zeit && i === 0) ? 0 : -1}" data-zeit="${s.zeit}">${s.zeit}</button>`;
    }).join('');
  }

  function renderTermin() {
    const ok = termin.datum && termin.zeit;
    $('#uebersicht-termin').hidden = !ok;
    if (ok) $('#uebersicht-termin-text').textContent = terminText(termin.datum, termin.zeit);
  }

  function renderUebersicht() {
    $('#uebersicht-liste').innerHTML = korb.map((pos) => {
      const p = produkte.get(pos.id);
      return `<div class="zeile zeile--fest">
        <span class="zeile__menge">${esc(mengeText(p, pos.menge))}</span>
        <span class="zeile__name">${esc(p.name)}${pos.option ? `<span class="zeile__option">${esc(pos.option)}</span>` : ''}${pos.notiz ? `<span class="zeile__option zeile__option--notiz">${esc(pos.notiz)}</span>` : ''}</span>
        <span class="zeile__preis">${zeilenPreis(p, pos.menge)}</span>
      </div>`;
    }).join('');
    $('#uebersicht-summe').textContent = fmtEuro(korbSumme());
    $('#uebersicht-summe').parentElement.hidden = !shopHatPreise;
    $('#zahlung-hinweis').textContent = korbHinweis();
    $('#uebersicht-summe').previousElementSibling.innerHTML = korbIstCa() ? 'Summe <em>ca.</em>' : 'Summe';
  }

  const FELDER = ['vorname', 'nachname', 'strasse', 'ort', 'telefon', 'email', 'anmerkung'];

  function kontaktSpeichern() {
    const f = $('#bestellformular');
    const daten = {};
    FELDER.forEach((n) => { daten[n] = f.elements[n].value; });
    speicher.schreiben('sessionStorage', SPEICHER_KONTAKT, daten);   // nur für diesen Tab, wird beim Schließen gelöscht
  }
  function kontaktLaden() {
    const daten = speicher.lesen('sessionStorage', SPEICHER_KONTAKT);
    if (!daten) return;
    const f = $('#bestellformular');
    FELDER.forEach((n) => { if (typeof daten[n] === 'string' && !f.elements[n].value) f.elements[n].value = daten[n]; });
  }

  function setzeFeldFehler(name, text) {
    const input = $('#bestellformular').elements[name];
    if (!input) return;
    const feld = input.closest('.feld');
    const box = $('.feldfehler', feld);
    feld.classList.toggle('feld--fehler', !!text);
    box.hidden = !text; box.textContent = text;
    input.setAttribute('aria-invalid', text ? 'true' : 'false');
  }
  function feldFehler(id, text) {
    const el = $(`#fehler-${id}`);
    el.hidden = !text; el.textContent = text;
  }

  function pruefen(daten) {
    const fehler = {};
    if (!termin.datum) fehler.datum = 'Bitte wählen Sie einen Abholtag.';
    else if (!termin.zeit) fehler.zeit = 'Bitte wählen Sie eine Uhrzeit.';
    if (!daten.vorname.trim()) fehler.vorname = 'Bitte geben Sie Ihren Vornamen an.';
    if (!daten.nachname.trim()) fehler.nachname = 'Bitte geben Sie Ihren Nachnamen an.';
    if (!daten.strasse.trim()) fehler.strasse = 'Bitte geben Sie Straße und Hausnummer an.';
    if (!daten.ort.trim()) fehler.ort = 'Bitte geben Sie PLZ und Ort an.';
    const tel = daten.telefon.trim();
    if (!/^[0-9+()/\-\s]{6,30}$/.test(tel) || tel.replace(/\D/g, '').length < 6) fehler.telefon = 'Bitte geben Sie eine gültige Telefonnummer an.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(daten.email.trim())) fehler.email = 'Bitte geben Sie eine gültige E-Mail-Adresse an.';
    return fehler;
  }

  function fehlerAnzeigen(fehler) {
    FELDER.forEach((n) => setzeFeldFehler(n, fehler[n] || ''));
    feldFehler('datum', fehler.datum || '');
    feldFehler('zeit', fehler.zeit || '');
    const erstes = ['datum', 'zeit', ...FELDER].find((k) => fehler[k]);
    if (!erstes) return;
    if (erstes === 'datum') $('#tage button')?.focus();
    else if (erstes === 'zeit') $('#zeiten button')?.focus();
    else $('#bestellformular').elements[erstes].focus();
  }

  function formFehler(text) {
    const box = $('#formfehler');
    box.hidden = !text;
    box.innerHTML = text;
  }

  async function absenden(e) {
    e.preventDefault();
    if (sendet) return;
    const f = $('#bestellformular');
    const daten = {};
    FELDER.forEach((n) => { daten[n] = f.elements[n].value; });
    const fehler = pruefen(daten);
    fehlerAnzeigen(fehler);
    formFehler('');
    if (Object.keys(fehler).length) return;

    const telefon = esc(shop.betrieb.telefon);
    const knopf = $('#absenden');
    sendet = true;
    knopf.disabled = true;
    knopf.textContent = 'Wird gesendet …';

    const nutzlast = {
      positionen: korb.map(({ id, option, menge, notiz }) => ({ id, option, menge, notiz: (notiz || '').trim() })),
      abholung: { datum: termin.datum, zeit: termin.zeit },
      kunde: {
        vorname: daten.vorname.trim(), nachname: daten.nachname.trim(),
        strasse: daten.strasse.trim(), ort: daten.ort.trim(),
        telefon: daten.telefon.trim(), email: daten.email.trim(),
      },
      anmerkung: daten.anmerkung.trim(),
      website: f.elements.website.value,
      dauer: Date.now() - formStart,
    };

    try {
      if (istTest) {
        await new Promise((r) => setTimeout(r, 700));
        erfolg({ ok: true, bestellnummer: testNummer(), bestaetigung_gesendet: false, test: true }, nutzlast);
        return;
      }
      const res = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(nutzlast),
      });
      let antwort = {};
      try { antwort = await res.json(); } catch { /* keine JSON-Antwort */ }

      if (res.ok && antwort.ok) {
        erfolg(antwort, nutzlast);
        return;
      }
      if (antwort.felder) fehlerAnzeigen(antwort.felder);
      if (res.status === 409) {           // Termin inzwischen nicht mehr frei
        tage = berechneTage(); termin = { datum: null, zeit: null };
        renderTage(); renderZeiten(); renderTermin();
      }
      formFehler(esc(antwort.fehler || 'Die Bestellung konnte leider nicht gesendet werden.') +
        ` Bei Problemen erreichen Sie uns telefonisch unter <strong>${telefon}</strong>.`);
    } catch {
      formFehler(`Keine Verbindung zum Server. Bitte prüfen Sie Ihre Internetverbindung oder bestellen Sie telefonisch unter <strong>${telefon}</strong>.`);
    } finally {
      sendet = false;
      knopf.disabled = false;
      knopf.textContent = 'Zahlungspflichtig bestellen';
    }
  }

  function artikelNr(p, option) {
    if (p.artikelnr == null) return '';
    return typeof p.artikelnr === 'object' ? String(p.artikelnr[option] ?? '') : String(p.artikelnr);
  }

  function testNummer() {
    const zeichen = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const d = new Date();
    let code = '';
    for (let i = 0; i < 4; i++) code += zeichen[Math.floor(Math.random() * zeichen.length)];
    return `TEST-${String(d.getFullYear()).slice(2)}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${code}`;
  }

  // Nur Testmodus: Textfassung der Mail an die Metzgerei (wie api/lib/Vorlagen.php)
  function testMailText(nummer, n) {
    const fuell = (s, l, links = false) => { s = String(s); const f = ' '.repeat(Math.max(0, l - [...s].length)); return links ? f + s : s + f; };
    const k = n.kunde;
    const zeilen = korb.map((pos) => {
      const p = produkte.get(pos.id);
      const nr = artikelNr(p, pos.option);
      return `  ${fuell(mengeText(p, pos.menge), 10)} ${fuell((nr ? nr + '  ' : '') + p.name, 28)} ${fuell(`${zeilenPreis(p, pos.menge)}`, 13, true)}`
        + (pos.option ? `\n             → ${pos.option}` : '')
        + (pos.notiz ? `\n             → Notiz: ${pos.notiz}` : '');
    }).join('\n');
    const ca = korbIstCa();
    return [
      `Betreff: Bestellung ${nummer} · Abholung ${terminText(n.abholung.datum, n.abholung.zeit)} · ${k.vorname} ${k.nachname}`,
      '',
      `NEUE BESTELLUNG ${nummer}`,
      '='.repeat(44),
      '',
      `Abholung:  ${terminText(n.abholung.datum, n.abholung.zeit)}`,
      '',
      `Kunde:     ${k.vorname} ${k.nachname}`,
      `Adresse:   ${k.strasse}, ${k.ort}`,
      `Telefon:   ${k.telefon}`,
      `E-Mail:    ${k.email}`,
      '',
      'Positionen:',
      zeilen,
      `  ${'-'.repeat(54)}`,
      ...(shopHatPreise ? [`  ${fuell('Summe', 39)} ${fuell(`${ca ? 'ca. ' : ''}${fmtEuro(korbSumme())}`, 13, true)}`] : []),
      ...(n.anmerkung ? ['', 'Anmerkung des Kunden:', n.anmerkung] : []),
    ].join('\n');
  }

  function erfolg(antwort, nutzlast) {
    letzteBestellung = antwort;
    const b = shop.betrieb;
    const vorschau = $('#test-vorschau');
    vorschau.hidden = !antwort.test;
    if (antwort.test) $('pre', vorschau).textContent = testMailText(antwort.bestellnummer, nutzlast);
    $('#titel-danke').textContent = `Vielen Dank, ${nutzlast.kunde.vorname}!`;
    $('#danke-text').textContent = 'Ihre Bestellung ist bei uns eingegangen. Wir richten alles frisch für Sie her – sollte etwas nicht verfügbar sein, melden wir uns bei Ihnen.';
    $('#danke-nr').textContent = antwort.bestellnummer;
    $('#danke-termin').textContent = terminText(nutzlast.abholung.datum, nutzlast.abholung.zeit);
    $('#danke-adresse').innerHTML = `${esc(b.name)}, ${esc(b.strasse)}, ${esc(b.ort)}`;
    $('#danke-mail').textContent = antwort.test
      ? 'Testmodus: Es wurde keine Mail verschickt.'
      : antwort.bestaetigung_gesendet
      ? `Eine Bestätigung haben wir an ${nutzlast.kunde.email} geschickt. ${b.zahlung_hinweis || ''}`
      : `${b.zahlung_hinweis || ''}`;

    korb = [];
    korbSpeichern();
    renderKorb();
    speicher.loeschen('sessionStorage', SPEICHER_KONTAKT);
    $('#bestellformular').reset();
    termin = { datum: null, zeit: null };
    formStart = 0;
    $$('.ansicht').forEach((s) => { s.hidden = s.id !== 'ansicht-danke'; });
    location.hash = 'danke';
  }

  // Fallback-Schriftzug, falls das Logo nicht lädt (ohne Inline-Script, wegen CSP)
  const logo = document.getElementById('logo');
  if (logo) {
    const ersatz = () => { logo.hidden = true; logo.nextElementSibling.hidden = false; };
    if (logo.complete && logo.naturalWidth === 0) ersatz(); else logo.addEventListener('error', ersatz);
  }

  document.addEventListener('DOMContentLoaded', start);
})();
