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
    deleting_id: { type: Number, state: true },
    import_data: { type: Object, state: true },
    error_message: { type: String, state: true },
    importing: { type: Boolean, state: true },
  };

  constructor() {
    super();

    this.journeys = [{ name: 'loading...' }];
    this.search = '';
    this.sort = '';
    this.search_filter = {};
    this.expanded_list = {};
    this.searchTimeout = null;
    this.deleting_id = null;
    this.has_file = false;
    this.file_error = false;
    this.error_message = '';
    this.importing = false;

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
    window.location.href = window.journeys_table.base_url + 'new/';
  }

  run_import(e) {
    e.stopPropagation();
    const dialog = this.shadowRoot.querySelector('#import-dialog');
    if (dialog) {
      dialog.dispatchEvent(new Event('open'));
    }

  }

  cancelImport(e) {
    if (e) e.stopPropagation();

    const dialog = this.shadowRoot.querySelector('#import-dialog');
    if (dialog) {
      dialog.dispatchEvent(new Event('close'));
    }
    this.import_data = null;
    this.error_message = '';

    const uploader = this.shadowRoot.querySelector('#json-upload');
    uploader.value = null;
  }

  async confirmImport(e) {
    e.stopPropagation();
    this.error_message = '';

    if (!this.import_data) {
        this.error_message = this.translations.upload_message;
      return;
    }

    this.importing = true;

    try {
      const response = await this.save_journey(this.import_data);
      if (response && response.ok) {
        window.location.reload();
      } else {
        this.error_message = this.translations.import_failed;
        this.importing = false;
      }
    } catch (error) {
      console.error('Import error:', error);
      this.error_message = this.translations.import_failed;
      this.importing = false;
    }
  }

  async save_journey(journeyData) {

    const payload = journeyData;

    try {
        let response = await fetch(window.journeys_table.rest_endpoint + `journeys`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.wpApiShare.nonce,
            },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
          let result = await response.json();

          console.error(result.message || 'Failed to save journey', 'journey-detail-error');
        }

        return response;
    } catch (error) {
        console.error("Create Journey Error:", error);
        return null;
    }
  }

  async handleFileChange() {
    this.error_message = '';
    const uploader = this.shadowRoot.querySelector('#json-upload');
    const files = uploader?.getPendingFilesForUpload() || [];
    const file = files[0];

    if (!file) {
      this.import_data = null;
      return;
    }

    try {
      const textContent = await file.text();
      this.import_data = JSON.parse(textContent);
    } catch (error) {
      console.error('Failed to parse JSON', error);
      this.error_message = this.translations.invalid_json;
      console.log(this.error_message);
    }
  }

  run_edit(e, journey_id) {
    e.stopPropagation();
    window.location.href = window.journeys_table.base_url + journey_id;
  }

  async duplicateJourney(e, journey_id) {
    e.stopPropagation();
    await fetch(window.journeys_table.rest_endpoint + `journeys/${journey_id}/duplicate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wpApiShare.nonce,
      },
    }).then((res) => res.json()).then(() => this.getJourneys(this.search, this.sort));
  }

  deleteJourney(e, journey_id) {
    e.stopPropagation();
    this.deleting_id = journey_id;
    const dialog = this.shadowRoot.querySelector('#delete-dialog');
    if (dialog) {
      dialog.dispatchEvent(new Event('open'));
    }
  }

  cancelDelete() {
    const dialog = this.shadowRoot.querySelector('#delete-dialog');
    if (dialog) {
      dialog.dispatchEvent(new Event('close'));
    }
    this.deleting_id = null;
  }

  async confirmDelete() {
    if (!this.deleting_id) return;

    const journey_id = this.deleting_id;
    this.cancelDelete();

    try {
      const response = await fetch(window.journeys_table.rest_endpoint + `journeys/${journey_id}`, {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.wpApiShare.nonce,
        },
      });

      if (response.ok) {
        await this.getJourneys(this.search, this.sort);
      } else {
        const errorData = await response.json();
        console.error('Delete Journey failed:', errorData);
      }
    } catch (error) {
      console.error('Delete Journey network error:', error);
    }
  }

  handleBackdropClick(e) {
    if (e.target.nodeName === 'DIALOG') {
      e.stopPropagation();
      this.cancelDelete();
      this.cancelImport();
    }
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
    const showingText = (this.translations.showing_x_of_y || '')
        .replace('%1$s', this.journeys.length)
        .replace('%2$s', this.total_journeys);

    const statusContent = this.loading 
        ? html`<img style="height:1em;" src="${window.wpApiShare?.template_dir}/spinner.svg" alt="spinner" />`
        : html`<span style="font-size: 14px;font-weight: normal">${showingText}</span>`;

    let hasFilters = false;
    const filters = this.search_filter;
    for (const [key, value] of Object.entries(filters)) {
      for (const item of value) {
        if (item.charAt(0) !== '-') {
          hasFilters = true;
          break;
        }
      }
    }

    let noJourneys = !this.loading && !this.search && !hasFilters && this.journeys.length === 0;

    return html`
        <div id="title-row">
            <h2 class="journey-header">
              ${this.translations.journeys}
              ${noJourneys ? '' : statusContent}
            </h2>
            ${noJourneys
              ? ''
              : html`
            <div id="create-button">
            <button class="button button-import" @click="${this.run_import}">${this.translations.import_journey}</button>
              <button class="button" @click="${this.run_create}">${this.translations.create_journey}</button>
            </div>
            ` }
        </div>
        ${noJourneys
          ? html`
              <div id="no-journeys-text" class="no-journeys-text">
                <span>${this.translations.no_journeys}</span>
                <span>${this.translations.no_journeys_desc}</span>
                <div class="button-container">
                  <button class="button" @click="${this.run_create}">${this.translations.create_journey}</button>
                  <button class="button button-import" @click="${this.run_import}">${this.translations.import_journey}</button>
                </div>
            </div>
            `
          : html`
        <div class="filter-inputs">
          <dt-text id="search-journeys" type="search" placeholder="${this.translations.search}" @input="${e => this.search_text(e)}"></dt-text>
          <dt-multi-select placeholder="Category" id="category-filter" @change="${(e) => this.filter_column('journey_category', e.detail.newValue, e)}" options="${JSON.stringify(this.category_options)}"></dt-multi-select>
          <dt-multi-select placeholder="Roles" id="role-filter" @change="${(e) => this.filter_column('journey_roles', e.detail.newValue, e)}" options="${JSON.stringify(this.role_options)}"></dt-multi-select>
        </div>
        <br>
        <div class="journeys-table-div" style="overflow: auto;">
          <table class="sortable">
              <tr>
                  ${this.getOrderedFields().map((key) => {
                    let field = window.journeys_table.fields[key];
                    return html`<th
                      id="${key}"
                      class="${key !== 'name' && key !== 'last_modified' ? 'mobile-hide' : ''}"
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

                              let cellContent = '';

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
                                cellContent = daysAgoText;
                              }
                              else if (['key_select'].includes(field.type)) {
                                cellContent = value.label;
                              }
                              else if (['connection'].includes(field.type) && value) {
                                cellContent = value.length;
                              }
                              else if (['multi_select', 'tags'].includes(field.type) && value) {
                                const cell = `${journey.ID}-${key}`;
                                const isExpanded = this.expanded_list[cell];
                                if (isExpanded) {
                                  cellContent = html`
                                    ${value.map(item => html`<div>${item}</div>`)}
                                    <button class="text-button" style="font-size: 0.9em;" @click="${(e) => this.toggleCell(journey.ID, key, e)}">
                                      - Show less
                                    </button>
                                  `;
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
                                    cellContent = html`
                                      <span>${value[0]}, ${value[1]}, </span>
                                      <button class="text-button" style="font-size: 0.9em;" title=${hoverString} @click="${(e) => this.toggleCell(journey.ID, key, e)}">
                                      +${value.length - 2} more
                                      </button>
                                    `;
                                  } else if (value.length === 2) {
                                    cellContent = `${value[0]}, ${value[1]}`;
                                  } else {
                                    cellContent = value[0] || '';
                                  }
                                }
                              }
                              else {
                                cellContent = value;
                              }
                            
                              return html`
                              <td class="${key !== 'name' && key !== 'last_modified' ? 'mobile-hide' : ''}">
                                ${cellContent}
                              </td>
                              `;
                            }
                          )}
                          <td>
                            <button class="icon-btn mobile-hide" @click="${(e) => this.run_edit(e, journey.ID)}"><dt-icon icon="mdi:edit"></dt-icon></button>
                            <button class="icon-btn mobile-button" @click="${(e) => this.duplicateJourney(e, journey.ID)}"><dt-icon icon="mdi:content-duplicate"></dt-icon></button>
                            <button class="icon-btn mobile-button" @click="${(e) => this.deleteJourney(e, journey.ID)}"><dt-icon icon="mdi:delete"></dt-icon></button>
                          </td>
                        </tr>
                      `
                    )
                  : html`<tr><td colspan="${Object.keys(window.journeys_table.fields).length}">No journeys found.</td></tr>`
              }
          </table>
        </div>
        <dt-modal 
          title="${this.translations.delete_journey}"
          id="delete-dialog" 
          class="delete-dialog" 
          hideButton="true"
          @click="${this.handleBackdropClick}"
          closebutton
          submitbutton
          hideHeader
        >
          <div slot="content" class="dialog-content">
            ${this.translations.delete_confirmation}
          </div>
          <button slot="submit-button" class="button button-delete" @click="${this.confirmDelete}">
            ${this.translations.delete}
          </button>
        </dt-modal>
        <dt-modal 
              id="import-dialog" 
              class="import-dialog"
              hideButton="true"
              @click="${this.handleBackdropClick}"
              hideHeader
            >
              <form slot="content" id="json-form">
                <div class="dialog-content">
                  <h3 class="dialog-title">${this.translations.import_journeys}</h3>
                  <dt-file-upload 
                    id="json-upload"
                    label="Test"
                    auto-upload="false"
                    accepted-file-types='[".json", "application/json"]'
                    max-files="1"
                    @change="${this.handleFileChange}">
                  </dt-file-upload>
                  ${this.error_message ? html`
                    <div class="error-container">
                      <dt-icon icon="mdi:alert-circle" class="alert" size="1.4rem"></dt-icon>
                      <span class="error-text">${this.error_message}</span>
                    </div>
                  ` : ''}
                  ${this.import_data ? html`
                    <div id="file-content">
                      <ul>
                        ${Object.entries(this.import_data).map(([key, value]) => {
                          if (key === 'source') return '';
                          const previewValue = key === 'stages' && Array.isArray(value) ? value.length : value;
                          return html`<li><strong>${key}:</strong> ${previewValue}</li>`;
                        })}
                      </ul>
                    </div>
                  ` : ''}
                  <div class="dialog-actions">
                    <button type="button" class="button button-cancel" @click="${this.cancelImport}">
                      ${this.translations.cancel}
                    </button>
                    <button id="submit-button" class="button" type="button" @click="${this.confirmImport}" ?disabled="${Boolean(this.error_message) || this.importing}">
                      Confirm
                    </button>
                  </div>
                </div>
              </form>
            </dt-modal>
        `
      }
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

        .button {
          padding: 0.4em 0.75em;
          border-radius: 5px;
          border: 1px solid transparent;
          cursor: pointer;
          background-color: #3f729b;
          color: #fefefe;
          margin: 0;
        }
        .button:hover {
          background-color: #2a4d6b;
        }

        .button-container {
          display: flex;
          gap: 1rem;
          margin-top: 1rem;
        }
        .button-import {
          background-color: #ffffff;
          color: #000000;
          border-color: #cccccc;
        }
        .button-import:hover {
          background-color: #f0f0f0;
          color: #000000;
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

        .no-journeys-text {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
        }

        .dialog-content {
          padding: 1.5rem;
          color: #555555;
        }

        .dialog-actions {
          display: flex;
          gap: 0.5rem;
          padding-block-start: 1rem;
        }

        .button-delete {
          background-color: #ffffff;
          color: #ff0000;
          border: 1px solid #cccccc;
        }
        .button-delete:hover {
          background-color: #f0f0f0;
          color: #ff0000;
        }
          
        @media screen and (max-width: 63.9375em) {
          .icon-btn {
            padding-inline-start: 0;
          }
        }

        @media screen and (max-width: 39.9375em) {
          .mobile-hide {
            display: none !important;
          }
          .icon-btn {
            padding: .3em;
            height: 1.7em;
            margin: .5em;
          }
          .mobile-button {
            background-color: #3f729b;
            color: white;
          }
        }

        .error-container {
          margin-top: 1rem;
          max-width: 100%;
          overflow: hidden;
        }

        .error-container {
          align-items: center;
          font-family: var(--font-family);
          font-size: 0.875rem;
          font-weight: 300;
          padding: 3px 0.5rem;
          gap: 0.5rem;

          border: solid 1px var(--color-alert);
          background-color: var(--color-alert-container);
          color: var(--color-on-alert-container);
        }
      `,
    ];
  }
}

customElements.define('journeys-table', JourneysTable);