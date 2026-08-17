jQuery(document).ready(function ($) {
  // Inject the component into the #chart or #table container on your page
  $('#journey-chart').empty().html(`
    <style>
        journeys-table {
            --sort-both: url(${window.wpApiShare.template_dir + '/dt-assets/images/sort_both.png'});
            --sort-desc: url(${window.wpApiShare.template_dir + '/dt-assets/images/sort_desc.png'});
            --sort-asc: url(${window.wpApiShare.template_dir + '/dt-assets/images/sort_asc.png'});
        }
    </style>
    <journeys-table></journeys-table>`);
});

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
  };

  constructor() {
    super();

    this.journeys = [{ name: 'loading...' }];
    this.search = '';
    this.sort = '';
    
    // Grabs the translations we localized in the PHP file
    this.translations = window.SHAREDFUNCTIONS.escapeObject(
      window.journeys_table.translations,
    );
    
    this.getJourneys();
  }

  async getJourneys(search = '', sort = '', filter = {}) {
    this.loading = true;
    let searchParameters = {
        sort: sort,
        text: search,
    }
    for (const key in filter) {
        if (filter[key] !== '') {
            searchParameters[key] = filter[key];
        }
    }
    
    // NOTE: Make sure your custom REST API has a 'get-journeys' endpoint!
    let response = await fetch(window.journeys_table.rest_endpoint + `get-journeys`, {
      method: 'POST',
      body: JSON.stringify({
        limit: 500,
        searchParameters,
      }),
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wpApiShare.nonce,
      },
    }).then((res) => res.json());
    
    this.loading = false;
    this.journeys = response.journeys || [];
    this.total_journeys = response.total_journeys || 0;
  }

  search_text() {
    this.search = this.shadowRoot.querySelector('#search-journeys').value;
    this.getJourneys(this.search, this.sort);
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
    let filter = {};
    filter[column] = value;
    this.getJourneys(this.search, this.sort, filter);

    // Reset all other filter selects to empty so we only filter one at a time
    this.shadowRoot
      .querySelectorAll('.filter-select')
      .forEach((select) => (select.value = ''));
    e.target.value = value;
  }

  field_to_render(field) {
    let fieldsToRender = ['Name', 'Category', 'Applies to Roles', 'Stages', 'Last Modified'];
    return fieldsToRender.includes(field.name);
  }

  run_create() {
    console.log('Create New Journey');
  }

  run_edit(e, journey_id) {
    e.stopPropagation();
    console.log('Open Journey ID:', journey_id);
  }

  async duplicateJourney(e, journey_id) {
    e.stopPropagation();
    let response = await fetch(window.journeys_table.rest_endpoint + `duplicate-journey`, {
      method: 'POST',
      body: JSON.stringify({
        journey_id: journey_id
      }),
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wpApiShare.nonce,
      },
    }).then((res) => res.json()).then(() => this.getJourneys(this.search, this.sort));
  }

  async deleteJourney(e, journey_id) {
    e.stopPropagation();
    let response = await fetch(window.journeys_table.rest_endpoint + `delete-journey`, {
      method: 'POST',
      body: JSON.stringify({
        journey_id: journey_id
      }),
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wpApiShare.nonce,
      },
    }).then((res) => res.json()).then(() => this.getJourneys(this.search, this.sort));
  }

  render() {
    return html`
        <div id="title-row">
            <div class="search-section">
                <h2>${this.translations.journeys} ${this.loading ? html`<img style="height:1em;" src="${window.wpApiShare.template_dir}/spinner.svg" alt="spinner" />` : html`<span style="font-size: 14px;font-weight: normal">${this.translations.showing_x_of_y.replace('%1$s', this.journeys.length).replace('%2$s', this.total_journeys)}</span>`}</h2>
                <input id="search-journeys" type="search" placeholder="${this.translations.search}" @search="${this.search_text}" @keyup="${e => e.key === 'Enter' && this.search_text()}">
                <button class="button" @click="${this.search_text}">${this.translations.go}</button>
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
              <tr class="filter-row">
                  ${Object.keys(window.journeys_table.fields).map((k) => {
                    let field = window.journeys_table.fields[k];
                    if (field.hidden === true || !this.field_to_render(field)) return;

                    if (field.options) {
                      let options = field.options;
                      return html`<td data-field="${k}" data-type="${field.type}">
                        <select
                          class="filter-select"
                          @change="${(e) => this.filter_column(k, e.target.value, e)}"
                        >
                          <option value=""></option>
                          ${Object.keys(options).map(
                            (o) => html`<option value="${o}">${options[o].label || options[o]}</option>`
                          )}
                        </select>
                      </td>`;
                    } else {
                      return html`<td data-field="${k}" data-type="${field.type}"></td>`;
                    }
                  })}
              </tr>
              <tr>
                  ${Object.keys(window.journeys_table.fields).map((k) => {
                    let field = window.journeys_table.fields[k];
                    if (field.hidden === true || !this.field_to_render(field)) return;
                    return html`<th
                      id="${k}"
                      data-field="${k}"
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
                          ${Object.keys(window.journeys_table.fields).map((key) => {
                              let field = window.journeys_table.fields[key];
                              if (field.hidden === true || !this.field_to_render(field)) return;


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
                                let val = value.map((v) => html`<div>${v.post_title}</div>`);
                                let hoverString = '';
                                if (value.length > 2) {
                                  for (let i = 1; i < value.length; i++) {
                                    if (i === value.length - 1) {
                                      hoverString += value[i].post_title;
                                      break;
                                    }
                                    hoverString += value[i].post_title + '\n';
                                  }
                                  val = html`<span>${value[0].post_title}</span>
                                    <button 
                                      class="text-button"
                                      title=${hoverString} 
                                    >
                                     +${value.length - 1}
                                    </button>`;
                                } else if (value.length === 2) {
                                  val = value[0].post_title + `, ${value[1].post_title}`;
                                }
                                return html`<td>${val}</td>`;
                              }
                              else if (['multi_select', 'tags'].includes(field.type) && value) {
                                let val = value[0];
                                let hoverString = '';
                                if (value.length > 2) {
                                  for (let i = 1; i < value.length; i++) {
                                    if (i === value.length - 1) {
                                      hoverString += value[i];
                                      break;
                                    }
                                    hoverString += value[i] + '\n';
                                  }
                                  val = html`<span>${val}</span>
                                    <button 
                                      class="text-button"
                                      title=${hoverString} 
                                    >
                                     +${value.length - 1}
                                    </button>`;
                                } else if (value.length === 2) {
                                  val += `, ${value[1]}`;
                                }
                                return html`<td><div>${val}</div></td>`;
                              }
                              else {
                                return html`<td>${value}</td>`;
                              }
                            }
                          )}
                          <td>
                            <button @click="${(e) => this.run_edit(e, journey.ID)}">E</button>
                            <button @click="${(e) => this.duplicateJourney(e, journey.ID)}">D</button>
                            <button @click="${(e) => this.deleteJourney(e, journey.ID)}">X</button>
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
        table { font-family: arial, sans-serif; border-collapse: collapse; table-layout: auto; min-width: max-content; width: 100%; }
        td, th { border-block: 1px solid #dddddd; text-align: left; padding: 8px; white-space: nowrap; }
        tr:not(.filter-row) { border-inline: 1px solid #dddddd; }
        tr:not(.filter-row):nth-child(even) { background-color: #eee; }
        .sortable th { background-repeat: no-repeat; background-position: center right; padding-right: 1.5rem; background-image: var(--sort-both); cursor: pointer; }
        .sortable .sorting_desc { background-image: var(--sort-desc); }
        .sortable .sorting_asc { background-image: var(--sort-asc); }
        #title-row { display: flex; justify-content: space-between; column-gap: 1em; }
        .search-section { margin: auto 0; }
        .filter-row td { border: none; }
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
          color: #555;
          cursor: pointer;
          outline: none;
        }
        .text-button:hover {
          color: #3f729b
        }
      `,
    ];
  }
}

customElements.define('journeys-table', JourneysTable);