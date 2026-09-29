/**
 * Support-Infobox ("Dir gefällt der Artikel von …? Überlege ein Abo
 * abzuschließen oder zu spenden"), zur Lesezeit zwischen zwei Absätze gesetzt.
 * Vanilla-Port von merlin-nextcloud/src/support-box.js.
 *
 * Bewusst nicht im gespeicherten Content: Export, TTS und Highlight-XPaths
 * bleiben unberührt (siehe Service\SupportBoxService). Die Box trägt
 * data-hl-exclude, damit die XPath-Zählung der Highlights sie übergeht und
 * Highlights plattformübergreifend gleich auflösen.
 *
 * Aufruf: MerlinSupportBox.insert(container, box, seed, i18n)
 *   container  #article-body (Artikeltext bereits per innerHTML gesetzt)
 *   box        {siteName, subscribeUrl, donationsUrl, accentColor, iconUrl?} oder null
 *   seed       stabiler Wert (Artikel-ID), damit die Position gleich bleibt
 *   i18n       Objekt mit den supportBox.*-Keys (siehe Translator::forJs)
 */
(function () {
    'use strict';

    var MIN_PARAGRAPHS = 4;
    var EXCLUDED_ANCESTORS = 'blockquote, figure, ul, ol, table, aside, .merlin-infobox, [data-hl-exclude]';

    /** Nur absolute http(s)-URLs; landet in einem href/src. */
    function safeHttpUrl(value) {
        if (typeof value !== 'string') return null;
        try {
            var url = new URL(value);
            return (url.protocol === 'http:' || url.protocol === 'https:') ? url.href : null;
        } catch (e) {
            return null;
        }
    }

    /** FNV-1a: stabiler 32-Bit-Hash eines beliebigen Seeds (Artikel-ID). */
    function hashSeed(seed) {
        var h = 0x811c9dc5;
        var str = String(seed);
        for (var i = 0; i < str.length; i++) {
            h ^= str.charCodeAt(i);
            h = Math.imul(h, 0x01000193);
        }
        return h >>> 0;
    }

    /** Direkte, nicht leere <p>-Kinder des Containers mit den meisten davon. */
    function findParagraphs(root) {
        var containers = [root].concat(Array.prototype.slice.call(root.querySelectorAll('div, section, article, main')))
            .filter(function (el) { return el === root || !el.closest(EXCLUDED_ANCESTORS); });
        var best = [];
        containers.forEach(function (container) {
            var paragraphs = Array.prototype.filter.call(container.children, function (el) {
                return el.tagName === 'P' && el.textContent.trim() !== '';
            });
            if (paragraphs.length > best.length) best = paragraphs;
        });
        return best;
    }

    function buildSentence(box, i18n) {
        var subscribeUrl = safeHttpUrl(box.subscribeUrl);
        var donationsUrl = safeHttpUrl(box.donationsUrl);
        if (!subscribeUrl && !donationsUrl) return null;

        var template;
        if (subscribeUrl && donationsUrl) template = i18n['supportBox.both'];
        else if (subscribeUrl) template = i18n['supportBox.subscribeOnly'];
        else template = i18n['supportBox.donateOnly'];

        var links = {
            subscribe: subscribeUrl && { href: subscribeUrl, label: i18n['supportBox.subscribeLabel'] },
            donate: donationsUrl && { href: donationsUrl, label: i18n['supportBox.donateLabel'] },
        };

        // Platzhalter selbst auflösen statt Markup einzuschleusen: alles bleibt Textknoten.
        var p = document.createElement('p');
        p.className = 'merlin-support-box__text';
        template.split(/(\{subscribe\}|\{donate\})/).forEach(function (part) {
            var match = /^\{(subscribe|donate)\}$/.exec(part);
            var link = match && links[match[1]];
            if (link) {
                var a = document.createElement('a');
                a.href = link.href;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = link.label;
                p.appendChild(a);
            } else if (part) {
                p.appendChild(document.createTextNode(part));
            }
        });
        return p;
    }

    function insert(container, box, seed, i18n) {
        if (!container || !box) return;
        var old = container.querySelector('.merlin-support-box');
        if (old) old.remove();

        var sentence = buildSentence(box, i18n);
        if (!sentence) return;

        var paragraphs = findParagraphs(container);
        if (paragraphs.length < MIN_PARAGRAPHS) return;
        // Nach dem 2. bis (n-1). Absatz, nie ganz vorn oder am Ende.
        var index = 1 + (hashSeed(seed) % (paragraphs.length - 2));

        var accent = /^#[0-9a-fA-F]{6}$/.test(box.accentColor || '') ? box.accentColor : '#FF3B30';
        var wrapper = document.createElement('div');
        wrapper.className = 'merlin-support-box';
        wrapper.setAttribute('data-hl-exclude', '');
        wrapper.setAttribute('role', 'note');
        wrapper.style.setProperty('--merlin-support-accent', accent);

        var header = document.createElement('div');
        header.className = 'merlin-support-box__header';

        var iconUrl = safeHttpUrl(box.iconUrl);
        if (iconUrl) {
            var img = document.createElement('img');
            img.className = 'merlin-support-box__icon';
            img.alt = '';
            img.loading = 'lazy';
            img.referrerPolicy = 'no-referrer';
            // Kaputtes/blockiertes Icon: einfach weglassen, die Box bleibt vollständig.
            img.addEventListener('error', function () { img.remove(); });
            img.src = iconUrl;
            header.appendChild(img);
        }

        var title = document.createElement('p');
        title.className = 'merlin-support-box__title';
        title.textContent = (i18n['supportBox.title'] || '').replace('{site}', box.siteName || '');
        header.appendChild(title);

        wrapper.appendChild(header);
        wrapper.appendChild(sentence);
        paragraphs[index].after(wrapper);
    }

    window.MerlinSupportBox = { insert: insert };
})();
