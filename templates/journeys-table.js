import {
  html,
  css,
  LitElement,
} from 'https://cdn.jsdelivr.net/gh/lit/dist@2/core/lit-core.min.js';

export class JourneysTable extends LitElement {
  static properties = {
    journeys: { type: Array, state: true },
    search: { type: String, state: true },
    sort: { type: String, state: true },
    loading: { type: Boolean, state: true },
    total_journeys: { type: Number, state: true },
    search_filter: { type: Object, state: true },
    expanded_list: { type: Object, state: true },
  };

  constructor() {
    super();

    this.journeys = [{ name: 'loading...' }];
    this.search = '';
    this.sort = '';
    this.search_filter = {};
    this.expanded_list = {};
    this.searchTimeout = null;

    // Grabs the translations we localized in the PHP file
    this.translations = window.SHAREDFUNCTIONS.escapeObject(
      window.journeys_table.translations,
    );
    this.fields = window.SHAREDFUNCTIONS.escapeObject(
      window.journeys_table.fields,
    );
    this.category_options = window.journeys_table.category_options;
    this.role_options = window.journeys_table.role_options;

    this.getJourneys();
  }

  async getJourneys(search = '', sort = '', filter = {}) {
    this.loading = true;
    let searchParameters = {
        sort: sort,
        text: search,
    }
    for (const key in filter) {
      let value = filter[key];
      if (Array.isArray(value)) {
        value = [];
        for (const item of filter[key]) {
          if (typeof item === 'string' && item.charAt(0) !== '-') {
            value.push(item);
          }
        }
      }
      if (value !== '' && value.length > 0) {
          searchParameters[key] = value;
      }
    }

    const queryParams = new URLSearchParams({
        limit: 500,
        ...searchParameters // Spreads existing keys (e.g., status, order, etc.)
    });

    let response = await fetch(window.journeys_table.rest_endpoint + `journeys?${queryParams.toString()}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wpApiShare.nonce,
      },
    }).then((res) => res.json());

    this.loading = false;
    this.journeys = response.journeys || [];
    this.total_journeys = response.total_journeys || 0;
  }

  search_text(e) {
    clearTimeout(this.searchTimeout);
    if (e.inputType === 'insertText') {
      this.searchTimeout = setTimeout(() => {
        this.search = this.shadowRoot.querySelector('#search-journeys').value;
        this.getJourneys(this.search, this.sort);
      }, 600);
    } else {
        this.search = this.shadowRoot.querySelector('#search-journeys').value;
        this.getJourneys(this.search, this.sort);
    }
  }

  clear_search() {
    this.search = '';
    this.shadowRoot.querySelector('#search-journeys').value = '';
    this.getJourneys(this.search, this.sort);
  }

  sort_column(e) {
    let id = e.target.id;
    let sort = e.target.id;
    if (this.sort === sort) {
      sort = sort.includes('-') ? sort.replace('-', '') : '-' + sort;
    }
    this.getJourneys(this.search, sort);

    this.shadowRoot
      .querySelectorAll('th')
      .forEach((th) => th.classList.remove('sorting_asc', 'sorting_desc'));
    this.shadowRoot
      .querySelector('#' + id)
      .classList.add(sort.includes('-') ? 'sorting_desc' : 'sorting_asc');

    this.sort = sort;
  }

  filter_column(column, value, e) {
    this.search_filter[column] = value;
    this.getJourneys(this.search, this.sort, this.search_filter);

    // Reset all other filter selects to empty so we only filter one at a time
    this.shadowRoot
      .querySelectorAll('.filter-select')
      .forEach((select) => (select.value = ''));
    e.target.value = value;
  }

  getOrderedFields() {
    const fieldList = ['Name', 'Category', 'Applies to Roles', 'Stages', 'Last Modified'];
    const fields = window.journeys_table.fields;
    let orderedFields = [];

    fieldList.forEach(name => {
      const key = Object.keys(fields).find(k => fields[k].name === name);
      if (key && fields[key].hidden !== true) {
        orderedFields.push(key);
      }
    });

    return orderedFields;
  }

  run_create() {
    console.log('Create New Journey');
    window.location.href = '/admin/journeys/seeker-path/';
  }

  run_edit(e, journey_id) {
    e.stopPropagation();
    console.log("ran");
    window.location.href = '/admin/journeys/seeker-path/' + journey_id;
  }

  async duplicateJourney(e, journey_id) {
    e.stopPropagation();
    let response = await fetch(window.journeys_table.rest_endpoint + `journeys/${journey_id}/duplicate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wpApiShare.nonce,
      },
    }).then((res) => res.json()).then(() => this.getJourneys(this.search, this.sort));
  }

  async deleteJourney(e, journey_id) {
    e.stopPropagation();
    let response = await fetch(window.journeys_table.rest_endpoint + `journeys/${journey_id}`, {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wpApiShare.nonce,
      },
    }).then((res) => res.json()).then(() => this.getJourneys(this.search, this.sort));
  }

  toggleCell(journey_id, key, e) {
    e.stopPropagation();
    const cell = `${journey_id}-${key}`;
    
    this.expanded_list = {
      ...this.expanded_list,
      [cell]: !this.expanded_list[cell]
    };
  }

  render() {
    return html`
        <div id="title-row">
            <div class="search-section">
                <h2 class="journey-header">${this.translations.journeys} ${this.loading ? html`<img style="height:1em;" src="${window.wpApiShare.template_dir}/spinner.svg" alt="spinner" />` : html`<span style="font-size: 14px;font-weight: normal">${this.translations.showing_x_of_y.replace('%1$s', this.journeys.length).replace('%2$s', this.total_journeys)}</span>`}</h2>
                <div class="filter-inputs">
                  <dt-text id="search-journeys" type="search" placeholder="${this.translations.search}" @input="${e => this.search_text(e)}"></dt-text>
                  <dt-multi-select placeholder="Category" id="category-filter" @change="${(e) => this.filter_column('journey_category', e.detail.newValue, e)}" options="${JSON.stringify(this.category_options)}"></dt-multi-select>
                  <dt-multi-select placeholder="Roles" id="role-filter" @change="${(e) => this.filter_column('journey_roles', e.detail.newValue, e)}" options="${JSON.stringify(this.role_options)}"></dt-multi-select>
                </div>
            </div>
            <div id="create-button">
                <button class="button" @click="${this.run_create}">
                    ${this.translations.create_journey}
                </button>
            </div>
        </div>
        <br>
        <div class="journeys-table-div" style="overflow: auto;">
          <table class="sortable">
              <tr>
                  ${this.getOrderedFields().map((key) => {
                    let field = window.journeys_table.fields[key];
                    return html`<th
                      id="${key}"
                      data-field="${key}"
                      @click="${this.sort_column}"
                      data-type="${field.type}"
                    >
                      ${field.label || field.name}
                    </th>`;
                  })}
                  <th>
                    Actions
                  </th>
              </tr>

              ${
                this.journeys.length
                  ? this.journeys.map(
                      (journey) => html`
                        <tr
                          class="journeys_row"
                          data-journey="${journey.ID}"
                          @click="${(e) => this.run_edit(e, journey.ID)}"
                        >
                          ${this.getOrderedFields().map((key) => {
                              let field = window.journeys_table.fields[key];


                              let value = journey[key] !== undefined ? journey[key] : '';
                              
                              if (field.type === 'date') {
                                let lastModified = value.timestamp;
                                let daysAgo = lastModified ? Math.floor((new Date()/1000 - new Date(lastModified)) / (60 * 60 * 24)) : null;
                                let daysAgoText = '';
                                if (daysAgo >= 365) {
                                  daysAgoText = `${Math.floor(daysAgo / 365)} years ago`;
                                } else if (daysAgo >= 30) {
                                  daysAgoText = `${Math.floor(daysAgo / 30)} months ago`;
                                } else if (daysAgo >= 7) {
                                  daysAgoText = `${Math.floor(daysAgo / 7)} weeks ago`;
                                } else if (daysAgo >= 2) {
                                    daysAgoText = daysAgo !== null ? `${daysAgo} days ago` : '';
                                } else if (daysAgo === 1) {
                                  daysAgoText = 'Yesterday';
                                } else {
                                  daysAgoText = 'Today';
                                }
                                return html`<td>${daysAgoText}</td>`;
                              }
                              else if (['key_select'].includes(field.type)) {
                                return html`<td>${value.label}</td>`;
                              }
                              else if (['connection'].includes(field.type) && value) {
                                return html`<td>${value.length}</td>`;
                              }
                              /*
                              else if (['multi_select', 'tags'].includes(field.type) && value) {
                                return html`<td>
                                  ${value.map((item) => html`<div>${item}</div>`)}
                                </td>`;
                              }
                              */
                              else if (['multi_select', 'tags'].includes(field.type) && value) {
                                const cell = `${journey.ID}-${key}`;
                                const isExpanded = this.expanded_list[cell];
                                if (isExpanded) {
                                  return html`<td>
                                    ${value.map(item => html`<div>${item}</div>`)}
                                    <button class="text-button" style="font-size: 0.9em;" @click="${(e) => this.toggleCell(journey.ID, key, e)}">
                                      - Show less
                                    </button>
                                  </td>`;
                                }
                                else {
                                  let hoverString = '';
                                  if (value.length > 2) {
                                    for (let i = 2; i < value.length; i++) {
                                      if (i === value.length - 1) {
                                        hoverString += value[i];
                                        break;
                                      }
                                      hoverString += value[i] + '\n';
                                    }
                                    return html`<td>
                                      <span>${value[0]}, ${value[1]}, </span>
                                      <button class="text-button" style="font-size: 0.9em;" title=${hoverString} @click="${(e) => this.toggleCell(journey.ID, key, e)}">
                                       +${value.length - 2} more
                                      </button>
                                    </td>`;
                                  } else if (value.length === 2) {
                                    return html`<td>${value[0]}, ${value[1]}</td>`;
                                  } else {
                                    return html`<td>${value[0]}</td>`;
                                  }
                                }
                              }
                              else {
                                return html`<td>${value}</td>`;
                              }
                            }
                          )}
                          <td>
                            <button class="icon-btn" @click="${(e) => this.run_edit(e, journey.ID)}"><dt-icon icon="mdi:edit"></dt-icon></button>
                            <button class="icon-btn" @click="${(e) => this.duplicateJourney(e, journey.ID)}"><dt-icon icon="mdi:content-duplicate"></dt-icon></button>
                            <button class="icon-btn" @click="${(e) => this.deleteJourney(e, journey.ID)}"><dt-icon icon="mdi:delete"></dt-icon></button>
                          </td>
                        </tr>
                      `
                    )
                  : html`<tr><td colspan="${Object.keys(window.journeys_table.fields).length}">No journeys found.</td></tr>`
              }
          </table>
        </div>
    `;
  }
  
  static get styles() {
    return [
      css`
        /* Include the same exact CSS block from your journeys-table here */
        table { font-family: arial, sans-serif; border-collapse: collapse; table-layout: auto; width: 100%; }
        td, th { border-block: 1px solid #dddddd; text-align: left; padding: .5em; }
        tr { border-inline: 1px solid #dddddd; }
        tr:nth-child(odd) { background-color: #eee; }
        .sortable th { background-repeat: no-repeat; background-position: center right; padding-right: 1.5rem; background-image: var(--sort-both); cursor: pointer; }
        .sortable .sorting_desc { background-image: var(--sort-desc); }
        .sortable .sorting_asc { background-image: var(--sort-asc); }
        #title-row { display: flex; justify-content: space-between; column-gap: 1em; }
        .search-section { margin: auto 0; }
        .journey-header { margin-block-start: 0em; margin-block-end: 0.5em; }
        .filter-select { width: 100%; font-size: 14px; line-height: 2; padding: 0 24px 0 8px; min-height: 30px; border-radius: 3px; border: 1px solid #8c8f94; cursor: pointer; }
        tr.journeys_row:hover { background-color: #ddd; cursor: pointer; }
        button { padding: 0.4em 0.75em; border-radius: 5px; background-color: #3f729b; color: #fefefe; border: 1px solid transparent; cursor: pointer; }
        input { padding: 0 8px; line-height: 2; min-height: 30px; border-radius: 4px; border: 1px solid #8c8f94; }
        .text-button {
          background: none;
          border: none;
          padding: 0;
          margin: 0;
          font: inherit;
          color: #3f729b;
          cursor: pointer;
          outline: none;
        }
        .text-button:hover {
          text-decoration: underline;
        }

        .icon-btn {
          background-color: transparent;
          border-width: medium;
          border-style: none;
          border-color: currentcolor;
          border-image: none;
          cursor: pointer;
          height: 0.9em;
          padding: 0px;
          color: #3f729b;
          transform: scale(1.5);
          padding-inline-start: .5em;
          padding-inline-end: .5em;
        }

        .filter-inputs {
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 1em;
          margin-top: 1em;
        }
      `,
    ];
  }
}

customElements.define('journeys-table', JourneysTable);