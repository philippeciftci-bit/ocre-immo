// V20 phase 11 — Web Component Shadow DOM pour intégration vitrine externe.
// Usage: <script src="https://embed.ocre.immo/ocre-widget.js"></script>
//        <ocre-widget tenant="ozkan" widget="gallery"></ocre-widget>
(function () {
  const API_BASE = 'https://api.ocre.immo/public_embed_v20.php';
  const STYLES = `
    :host { display: block; font-family: Georgia, serif; color: #2A1810; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
    .card { background: #FAF8F2; border: 1px solid #E5DDC8; border-radius: 6px; overflow: hidden; cursor: pointer; transition: transform .15s; }
    .card:hover { transform: translateY(-2px); }
    .card img { width: 100%; height: 180px; object-fit: cover; display: block; }
    .card-body { padding: 12px; }
    .card-title { font-weight: bold; color: #6B4429; margin: 0 0 6px; font-size: 15px; }
    .card-meta { font-size: 13px; color: #6B6B6B; margin: 2px 0; }
    .card-price { color: #8B5E3C; font-weight: bold; font-size: 16px; margin-top: 8px; }
    .search-bar { margin-bottom: 16px; display: flex; gap: 8px; }
    .search-bar input { flex: 1; padding: 10px 14px; border: 1px solid #B89968; border-radius: 4px; font-family: inherit; font-size: 14px; }
    .search-bar button { padding: 10px 18px; background: #8B5E3C; color: white; border: none; border-radius: 4px; cursor: pointer; font-family: inherit; }
    .search-bar button:hover { background: #6B4429; }
    .empty { text-align: center; padding: 40px; color: #6B6B6B; }
  `;

  class OcreWidget extends HTMLElement {
    connectedCallback() {
      const tenant = this.getAttribute('tenant') || '';
      const widget = this.getAttribute('widget') || 'gallery';
      const shadow = this.attachShadow({ mode: 'open' });
      shadow.innerHTML = `<style>${STYLES}</style><div class="root"></div>`;
      this.root = shadow.querySelector('.root');
      this.tenant = tenant;
      this.widget = widget;
      this.render();
    }

    async fetchData(query = '') {
      const url = `${API_BASE}?t=${encodeURIComponent(this.tenant)}&w=${encodeURIComponent(this.widget)}` + (query ? `&q=${encodeURIComponent(query)}` : '');
      const r = await fetch(url);
      return r.json();
    }

    cardHtml(item) {
      const img = item.photo_principale || 'https://embed.ocre.immo/placeholder.jpg';
      const title = item.titre || '';
      const ville = item.ville || '';
      const surface = item.surface ? `${item.surface} m²` : '';
      const prix = item.prix ? `${Number(item.prix).toLocaleString('fr-FR')} €` : '';
      return `<div class="card" data-id="${item.id}">
        <img src="${img}" alt="">
        <div class="card-body">
          <p class="card-title">${title}</p>
          <p class="card-meta">${item.type_bien || ''} · ${ville}</p>
          <p class="card-meta">${surface}</p>
          <p class="card-price">${prix}</p>
        </div>
      </div>`;
    }

    async render() {
      if (this.widget === 'search') {
        this.root.innerHTML = `
          <div class="search-bar">
            <input type="text" placeholder="Rechercher (ville, type, titre)…">
            <button>Chercher</button>
          </div>
          <div class="grid"></div>`;
        const input = this.root.querySelector('input');
        const btn = this.root.querySelector('button');
        const grid = this.root.querySelector('.grid');
        const doSearch = async () => {
          const data = await this.fetchData(input.value.trim());
          grid.innerHTML = (data.items && data.items.length)
            ? data.items.map(i => this.cardHtml(i)).join('')
            : `<div class="empty">Aucun bien trouvé.</div>`;
        };
        btn.addEventListener('click', doSearch);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });
        doSearch();
      } else {
        const data = await this.fetchData();
        this.root.innerHTML = data.items && data.items.length
          ? `<div class="grid">${data.items.map(i => this.cardHtml(i)).join('')}</div>`
          : `<div class="empty">Aucun bien publié.</div>`;
      }
    }
  }

  if (!customElements.get('ocre-widget')) {
    customElements.define('ocre-widget', OcreWidget);
  }
})();
