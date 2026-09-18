<?php
/**
 * Template Name: Journeys Admin Page
 */

get_header();
$journey_id = get_query_var( 'dt_journey_id' );

$post_settings = DT_Posts::get_post_settings( 'journeys' );
$field_options = isset( $post_settings['fields'] ) ? $post_settings['fields'] : [];

if ( empty( $journey_id ) ) {
    $journey = [
        'post_type' => 'journeys',
        'stages'    => []
    ];
} else {
    $journey = DT_Posts::get_post( 'journeys', $journey_id );

    if ( empty( $journey ) || is_wp_error( $journey ) ) {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        include get_404_template();
        exit;
    }
}

$stages = [];
$p2p_type = 'journeys_to_stages';

foreach ( $journey['stages'] ?? [] as $connected_stage ) {
    $stage_id = $connected_stage['ID'];
    $stage = DT_Posts::get_post( 'journey_stages', $stage_id );

    if ( ! is_wp_error( $stage ) && ! empty( $stage ) ) {
        $p2p_ids = p2p_get_connections( $p2p_type, array(
            'from'   => $journey_id,
            'to'     => $stage_id,
            'fields' => 'p2p_id',
        ) );

        $p2p_id = !empty( $p2p_ids ) ? (int) $p2p_ids[0] : false;

        $order_val = $p2p_id ? p2p_get_meta( $p2p_id, 'stage_order', true ) : 0;

        $stage['stage_order'] = (int) $order_val;
        $stages[] = $stage;
    }
}

// Sort stages by their contextual order
usort( $stages, function ( $a, $b ) {
    return ( $a['stage_order'] ?? 0 ) <=> ( $b['stage_order'] ?? 0 );
} );

?>

<!-- List Section -->
<div id="content" class="grid-container" style="min-height: 80vh;">
    <div class="title-row title">
        <h2 class="title-header"><?php esc_html_e( 'Edit Journey', 'disciple_tools' ); ?></h2>
            <div class="button-container">
            <?php if ( ! empty( $journey_id ) ) : ?>
                <!-- Show Delete only if editing an existing journey -->
                <button class="button button-delete" onclick="delete_journey(<?php echo esc_js( $journey_id ); ?>)">
                    <?php esc_html_e( 'Delete Journey', 'disciple_tools' ); ?>
                </button>
            <?php else : ?>
                <!-- Split Save Button with LocalStorage Mode Selection -->
                <div class="split-button-wrapper" id="save-split-button">
                    <button type="submit" form="journey-form" class="button split-main-btn" id="save-btn">
                        <span id="save-btn-label"><?php esc_html_e( 'Save & Go Back', 'disciple_tools' ); ?></span>
                    </button>
                    <button class="button split-toggle-btn" type="button" onclick="toggle_save_dropdown(event)">
                        <dt-icon icon="mdi:chevron-down"></dt-icon>
                    </button>
                    <div class="split-dropdown-menu" id="save-dropdown-menu">
                        <button type="button" class="dropdown-item" onclick="select_save_mode('continue')">
                            <?php esc_html_e( 'Save & Continue', 'disciple_tools' ); ?>
                        </button>
                        <button type="button" class="dropdown-item" onclick="select_save_mode('add_new')">
                            <?php esc_html_e( 'Save & Add New', 'disciple_tools' ); ?>
                        </button>
                        <button type="button" class="dropdown-item" onclick="select_save_mode('go_back')">
                            <?php esc_html_e( 'Save & Go Back', 'disciple_tools' ); ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Back button always shows -->
            <button class="button button-back" id="back-btn" onclick="go_back()">
                <?php esc_html_e( 'Back', 'disciple_tools' ); ?>
            </button>
        </div>
    </div>
    <div class="grid-x grid-margin-x">
        <section class="medium-4 small-12 cell">
            <div class="bordered-box">
                <h6 class="journey-header"><?php esc_html_e( 'Journey Details', 'disciple_tools' ); ?></h6>
                <form id="journey-form" onsubmit="save_journey(event)">
                    <div class="margin-top-1">
                        <?php

                        foreach ( $field_options as $field_key => $field ) {

                            if ( !isset( $field['tile'] ) || $field_key === 'stages' ) {
                                continue;
                            }

                            if ( ! array_key_exists( $field_key, $journey ) ) {

                                $array_types = [ 'tags', 'multi_select', 'connection', 'user_select' ];

                                if ( isset( $field['type'] ) && in_array( $field['type'], $array_types, true ) ) {
                                    $journey[ $field_key ] = [];
                                } elseif ( isset( $field['type'] ) && $field['type'] === 'key_select' ) {
                                    $journey[ $field_key ] = [ 'key' => '' ];
                                } else {
                                    $journey[ $field_key ] = '';
                                }
                            }

                            echo '<div style="margin-bottom: 15px;">';

                            $is_required = ! empty( $field['required'] ) ? true : false;
                            $display_settings = $field_options;
                            $display_settings[ $field_key ]['required'] = $is_required;

                            render_field_for_display( $field_key, $display_settings, $journey, true, true, '', [] );

                            echo '</div>';
                        }
                        ?>
                    </div>
                </form>
            </div>
        </section>
        <section class="medium-8 small-12 cell">
            <div class="bordered-box">
                <div class="title-row">
                    <div class="stage-list-header" id="stage-list-header">
                        <h6 class="journey-header"><?php esc_html_e( 'Stages', 'disciple_tools' ); ?></h6>
                    </div>
                    <button class="button" onclick="add_stage()">
                        <?php esc_html_e( 'Add Stage', 'disciple_tools' ); ?>
                    </button>
                </div>
                <div>
                    <ul id="stage-list">
                        <?php foreach ( $stages as $stage ) { ?>
                        <li id="stage-<?php echo esc_attr( $stage['ID'] ); ?>" data-id="<?php echo esc_attr( $stage['ID'] ); ?>" style="border: 1px solid #ccc; padding: 1em; margin: 1em 0; display: flex; justify-content: space-between; background: #fff;">
                            <div class="item-details">
                                <div class="stage-order">
                                    <dt-icon class="drag-handle" icon="mdi:reorder-horizontal"></dt-icon>
                                </div>
                                <div class="stage-data">
                                    <div class="stage-name" id="stage-name-<?php echo esc_attr( $stage['ID'] ); ?>">
                                        <?php echo esc_html( $stage['name'] ); ?>
                                    </div>
                                    <div class="stage-description" id="stage-description-<?php echo esc_attr( $stage['ID'] ); ?>">
                                        <?php echo esc_html( $stage['description'] ?? '' ); ?>
                                    </div>
                                    <div class="stage-fields" id="stage-fields-<?php echo esc_attr( $stage['ID'] ); ?>">
                                        <?php echo 'Links: ' . esc_html( count( $stage['links'] ?? [] ) ) . ' Attachments: ' . esc_html( count( $stage['attachments'] ?? [] ) ) . ' Related Fields: ' . esc_html( count( $stage['related_fields'] ?? [] ) ); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="stage-actions">
                                <button class="icon-btn" onclick="run_edit(<?php echo esc_js( $stage['ID'] ); ?>)"><dt-icon icon="mdi:edit"></dt-icon></button>
                                <button class="icon-btn" onclick="delete_stage(<?php echo esc_js( $stage['ID'] ); ?>)"><dt-icon icon="mdi:delete"></dt-icon></button>
                            </div>
                        </li>
                            <?php
                        }
                        ?>
                    </ul>
                </div>
            </div>
        </section>
    </div>
</div>

<style>
    .title-header {
        font-weight: bold;
        margin: 0;
    }
    .title-row { display: flex; justify-content: space-between; column-gap: 1em; width: 100%; }
    .title {
        padding-block: .5rem;
    }

    .help-text {
        margin-top: 0;
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
    .button-container {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
    }

    .split-button-wrapper {
        position: relative;
        display: inline-flex;
        border-radius: 5px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }

    .split-main-btn {
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
        border-right: 1px solid rgba(255, 255, 255, 0.2);
    }

    .split-toggle-btn {
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
        padding-inline: 0.4em;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .split-dropdown-menu {
        display: none;
        position: absolute;
        top: 100%;
        right: 0;
        margin-top: 4px;
        background-color: #ffffff;
        min-width: 170px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        border: 1px solid #cccccc;
        border-radius: 5px;
        z-index: 100;
        overflow: hidden;
    }

    .split-dropdown-menu.show {
        display: block;
    }

    .dropdown-item {
        display: block;
        width: 100%;
        text-align: left;
        padding: 0.5em 0.85em;
        background: transparent;
        border: none;
        color: #333333;
        cursor: pointer;
        font-size: 0.9em;
    }

    .dropdown-item:hover {
        background-color: #f0f4f8;
        color: #3f729b;
    }

    .button-delete {
        background-color: #ffffff;
        color: #ff0000;
        border-color: #cccccc;
    }
    .button-delete:hover {
        background-color: #f0f0f0;
        color: #ff0000;
    }
    .button-delete:focus {
        background-color: #f0f0f0;
        color: #ff0000;
    }

    .button-back {
        background-color: #ffffff;
        color: #000000;
        border-color: #cccccc;
    }
    .button-back:hover {
        background-color: #f0f0f0;
        color: #000000;
    }
    .button-back:focus {
        background-color: #f0f0f0;
        color: #000000;
    }

    .journey-header {
        font-weight: bold;
    }

    .subsection {
        padding: 1em;
        border-radius: 5px;
        border: 1px solid #cccccc;
    }

    .sortable-placeholder {
        border: 1px dashed #a0c4d9;
        background-color: #f7fbfc;
        margin: 1em 0;
        padding: 1em;
        border-radius: 5px;
    }

    .stage-name {
        font-weight: bold;
    }

    .stage-list-header {
        display: flex;
        align-items: center;
    }

    .stage-description {
        font-style: italic;
    }

    .stage-order {
        display: flex;
        align-items: center;
        gap: .25rem;
    }

    .item-details {
        display: flex;
        align-items: center;
        gap: 0.5em;
    }

    .drag-handle {
        color: #999;
        cursor: grab;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background-color: transparent;
        border: none;
        font-size: 1.5em;
        padding: 0;
    }

    .stage-actions {
        display: flex;
        align-items: center;
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

    @keyframes fadeOut {
        0% {
        opacity: 1;
        }
        75% {
        opacity: 1;
        }
        100% {
        opacity: 0;
        }
    }

    .icon-overlay.fade-out {
        opacity: 0;
        animation: fadeOut 4s;
    }

    .icon-overlay {
        display: inline-flex;
        align-items: center;
        margin-left: 0.75rem;
        margin-bottom: 0.5rem;
        pointer-events: none;
    }

    .icon-overlay.success {
        color: var(--success-color);
        width: 1.4rem;
    }

</style>

<script>
    const SAVE_MODES = {
        continue: '<?php echo esc_js( __( 'Save & Continue', 'disciple_tools' ) ); ?>',
        add_new:  '<?php echo esc_js( __( 'Save & Add New', 'disciple_tools' ) ); ?>',
        go_back:  '<?php echo esc_js( __( 'Save & Go Back', 'disciple_tools' ) ); ?>'
    };

    let currentSaveMode = localStorage.getItem('dt_journey_save_action') || 'go_back';

    function updateSaveButtonUI() {
        const labelEl = document.getElementById('save-btn-label');
        if (labelEl && SAVE_MODES[currentSaveMode]) {
            labelEl.innerHTML = SAVE_MODES[currentSaveMode];
        }
    }

    function toggle_save_dropdown(event) {
        event.stopPropagation();
        const menu = document.getElementById('save-dropdown-menu');
        if (menu) {
            menu.classList.toggle('show');
        }
    }

    function select_save_mode(mode) {
        if (SAVE_MODES[mode]) {
            currentSaveMode = mode;
            localStorage.setItem('dt_journey_save_action', mode);
            updateSaveButtonUI();
        }
        const menu = document.getElementById('save-dropdown-menu');
        if (menu) {
            menu.classList.remove('show');
        }
    }

    // Close split dropdown on outside clicks
    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('save-split-button');
        const menu = document.getElementById('save-dropdown-menu');
        if (menu && wrapper && !wrapper.contains(e.target)) {
            menu.classList.remove('show');
        }
    });

    function go_back() {
        window.location.href = '/admin/journeys/';
    }

    function add_stage() {
        console.log("adding new stage");
    }

    function run_edit(stage_id) {
        console.log(stage_id);
    }

    async function delete_stage(stage_id) {

        const stageRow = document.getElementById(`stage-${stage_id}`);

        let response = await fetch(window.journey_details_js.rest_endpoint + `journeys/stage/${stage_id}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.wpApiShare.nonce,
            },
        }).then((res) => res.json()).then(() => stageRow.remove());
    }

    async function delete_journey(journey_id) {

        try {
            let response = await fetch(window.journey_details_js.rest_endpoint + `journeys/${journey_id}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.wpApiShare.nonce,
                },
            });

            let result = await response.json();

            if (response.ok) {
                window.location.href = '/admin/journeys/';
            }
        } catch (error) {
            console.error("Delete Journey Error:", error);
        }
    }

    async function save_journey(event) {
        event.preventDefault();

        const journeyFields = <?php
            $js_fields = array_map( function( $field ) {
                return $field['type'] ?? 'text';
            }, $field_options );

            echo wp_json_encode( $js_fields );
            ?>;

        const payload = {};

        for ( const [fieldKey, fieldType] of Object.entries(journeyFields) ) {
            const el = document.getElementById(fieldKey);
            if ( ! el ) continue;
            
            payload[fieldKey] = el.value;
        }

        try {
            let response = await fetch(window.journey_details_js.rest_endpoint + `journeys`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.wpApiShare.nonce,
                },
                body: JSON.stringify(payload)
            });

            let result = await response.json();

            const newId = result.ID || '';

            if (currentSaveMode === 'continue' && newId) {
                window.location.href = `/admin/journeys/${newId}/`;
            } else if (currentSaveMode === 'add_new') {
                window.location.href = '/admin/journeys/new/';
            } else {
                // Default: 'go_back'
                window.location.href = '/admin/journeys/';
            }
        } catch (error) {
            console.error("Create Journey Error:", error);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateSaveButtonUI();

        const journeyId = <?php echo absint( $journey_id ); ?>;
        const currentPostType = 'journeys'; // Hardcoded since this is the Journeys admin page
        
        const apiNonce = window.wpApiSettings ? window.wpApiSettings.nonce : (window.wpApiShare ? window.wpApiShare.nonce : '');
        const apiRoot = window.wpApiSettings ? window.wpApiSettings.root : (window.wpApiShare ? window.wpApiShare.root : '/wp-json/');

        if (window.DtWebComponents && window.DtWebComponents.ComponentService) {
            const service = new window.DtWebComponents.ComponentService(
                currentPostType,
                journeyId,
                apiNonce,
                apiRoot
            );
            
            service.initialize();
            
            window.componentService = service;
        }

    });
</script>

<?php
// Load the Disciple.Tools footer
get_footer();


?>