<?php
/**
 * Template Name: Journeys Admin Page
 */

$journey_id = get_query_var( 'dt_journey_id' );

$post_settings = DT_Posts::get_post_settings( 'journeys' );
$field_options = isset( $post_settings['fields'] ) ? $post_settings['fields'] : [];

$stage_settings = DT_Posts::get_post_settings( 'journey_stages' );
$stage_fields = isset( $stage_settings['fields'] ) ? $stage_settings['fields'] : [];

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

foreach ( $stages as &$stage ) {
    foreach ( $stage_fields as $field_key => $field_config ) {
        $field_type = $field_config['type'] ?? '';

        if ( in_array( $field_type, [ 'connection', 'user_select', 'multi_select' ], true ) && ! empty( $stage[ $field_key ] ) ) {
            if ( is_array( $stage[ $field_key ] ) ) {
                foreach ( $stage[ $field_key ] as &$item ) {
                    if ( is_array( $item ) ) {
                        $item['id'] = $item['id'] ?? $item['ID'] ?? 0;
                        $item['label'] = $item['label'] ?? $item['name'] ?? $item['post_title'] ?? '';
                        $item['link'] = $item['link'] ?? $item['permalink'] ?? '';
                    }
                }
            }
        }
    }
}
unset( $stage );

add_action( 'wp_enqueue_scripts', function () use ( $journey, $stage_fields, $field_options, $stages ) {
    $stage_js_fields = array_map( function( $field ) {
        return $field['type'] ?? 'text';
    }, $stage_fields );

    $journey_js_fields = array_map( function( $field ) {
        return $field['type'] ?? 'text';
    }, $field_options );

    wp_localize_script( 'journey_details_js', 'dtJourneyData', [
        'journeysBaseUrl' => site_url( '/admin/journeys/' ),
        'journeyName'     => $journey['name'] ?? $journey['post_title'] ?? 'Unknown Journey',
        'stages'          => $stages,
        'stageFields'     => $stage_js_fields,
        'journeyFields'   => $journey_js_fields,
        'i18n'            => [
            'continue' => __( 'Save & Continue', 'disciple_tools' ),
            'add_new'  => __( 'Save & Add New', 'disciple_tools' ),
            'go_back'  => __( 'Save & Go Back', 'disciple_tools' )
        ]
    ] );
}, 99 );

get_header();

?>

<!-- List Section -->
<div id="content" class="grid-container" style="min-height: 80vh;">
    <div class="title-row title">
        <button class="link-button" onclick="go_back()">
            <dt-icon icon="mdi:chevron-left"></dt-icon>
            <?php esc_html_e( 'Back', 'disciple_tools' ); ?>
        </button>
    </div>
    <div class="slider-viewport">
        <div id="slider-track" class="grid-x grid-margin-x">
            <section id="section-journey-details" class="medium-7 small-12 cell">
                <div class="bordered-box">
                    <span class="error-text" id="journey-detail-error" style="display: none;"></span>
                    <div class="title-row">
                    <h6 class="journey-header"><?php esc_html_e( 'Journey Details', 'disciple_tools' ); ?></h6>
                        <?php if ( empty( $journey_id ) ) : ?>
                            <div class="split-button-wrapper" id="save-split-button">
                                <button type="submit" form="journey-form" class="button split-main-btn" id="save-btn">
                                    <span id="top-btn-label"><?php esc_html_e( 'Save & Go Back', 'disciple_tools' ); ?></span>
                                </button>
                                <button class="button split-toggle-btn" type="button" onclick="toggle_save_dropdown(event)">
                                    <dt-icon icon="mdi:chevron-down" id="top-icon"></dt-icon>
                                </button>
                                <div class="split-dropdown-menu top" id="save-dropdown-top">
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
                    </div>
                    <form id="journey-form" onsubmit="save_journey(event)">
                        <div class="margin-top-1 fields-container">
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

                                $is_required = ! empty( $field['required'] ) ? true : false;
                                $display_settings = $field_options;
                                $display_settings[ $field_key ]['required'] = $is_required;

                                render_field_for_display( $field_key, $display_settings, $journey, true, true, 'journey_', [] );
                            }
                            ?>
                        </div>
                    </form>
                    <div class="button-container">
                        <?php if ( ! empty( $journey_id ) ) : ?>
                            <button class="button button-delete" onclick="delete_journey(<?php echo esc_js( $journey_id ); ?>)">
                                <?php esc_html_e( 'Delete Journey', 'disciple_tools' ); ?>
                            </button>
                        <?php else : ?>
                            <div class="split-button-wrapper" id="save-split-button">
                                <button type="submit" form="journey-form" class="button split-main-btn" id="save-btn">
                                    <span id="bottom-btn-label"><?php esc_html_e( 'Save & Go Back', 'disciple_tools' ); ?></span>
                                </button>
                                <button class="button split-toggle-btn" type="button" onclick="toggle_save_dropdown(event)">
                                    <dt-icon icon="mdi:chevron-down" id="bottom-icon"></dt-icon>
                                </button>
                                <div class="split-dropdown-menu bottom" id="save-dropdown-bottom">
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
                    </div>
                </div>
            </section>
            <section id="section-stages-list" class="medium-5 small-12 cell">
                <div class="bordered-box">
                    <span class="error-text" id="stage-list-error" style="display: none;"></span>
                    <div class="title-row">
                        <div class="stage-list-header" id="stage-list-header">
                            <h6 class="journey-header"><?php esc_html_e( 'Stages', 'disciple_tools' ); ?></h6>
                        </div>
                        <button class="button" onclick="edit_stage()">
                            <?php esc_html_e( 'Add Stage', 'disciple_tools' ); ?>
                        </button>
                    </div>
                    <div>
                        <div id="no-stages-text" class="margin-top-1">
                            <?php esc_html_e( 'No journey stages created.', 'disciple_tools' ); ?>
                            <button class="link-button inline" onclick="edit_stage()"><?php esc_html_e( 'Add Stage', 'disciple_tools' ); ?></button>
                        </div>
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
                                    <button class="icon-btn" onclick="edit_stage(<?php echo esc_js( $stage['ID'] ); ?>)"><dt-icon icon="mdi:edit"></dt-icon></button>
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
            <section id="section-edit-stage" class="medium-7 small-12 cell">
                <div class="bordered-box">
                    <span class="error-text" id="stage-detail-error" style="display: none;"></span>
                    <div class="title-row">
                        <h6 id="edit-section-title" class="journey-header"><?php esc_html_e( 'Edit Stage', 'disciple_tools' ); ?></h6>
                        <button class="icon-btn" onclick="close_edit(<?php echo esc_html( $journey_id > 0 ) ?>)" style="transform: scale(1.2);">
                            <dt-icon icon="mdi:close"></dt-icon>
                        </button>
                    </div>

                    <div id="edit-stage-content" class="margin-top-1">
                        <form id="stage-form" class="stage-edit-form" onsubmit="save_stage(event)">
                            <div class="fields-container">
                            <?php
                            // Still need to empty the fields for Add Stage
                            foreach ( $stage_fields as $field_key => $field ) {
                                if ( empty( $field['tile'] ) || $field_key === 'journey' ) {
                                    continue;
                                }
                                $is_required = ! empty( $field['required'] ) ? true : false;
                                $display_settings = $stage_fields;
                                $display_settings[ $field_key ]['required'] = $is_required;

                                if ( $display_settings[$field_key]['type'] === 'multi_select' ) {
                                    $display_settings[$field_key]['display'] = 'typeahead';
                                }

                                render_field_for_display( $field_key, $display_settings, [ 'post_type' => 'journey_stages' ], true, true, 'stage_', [] );
                            }
                            ?>
                            </div>
                            <div id="stage-save-container" class="button-container">
                                <button type="button" class="button button-back" onclick="close_edit()">
                                    <?php esc_html_e( 'Cancel', 'disciple_tools' ); ?>
                                </button>
                                <button type="submit" class="button">
                                    <?php esc_html_e( 'Save Stage', 'disciple_tools' ); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<style>
    .fields-container {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem 1.5rem;
        margin-block-end: 1rem;
    }

    .slider-viewport {
        overflow-x: hidden;
        width: 100%;
        position: relative;
    }

    #slider-track {
        flex-wrap: nowrap !important;
        transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
    }

    #slider-track > .cell {
        flex-shrink: 0 !important;
    }

    .shift-left {
        transform: translateX(-58.33333%);
    }

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
        justify-content: flex-end;
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
        right: 0;
        background-color: #ffffff;
        min-width: 170px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        border: 1px solid #cccccc;
        border-radius: 5px;
        z-index: 100;
        overflow: hidden;
    }
    .split-dropdown-menu.top {
        top: 100%;
        margin-top: 4px;
    }
    .split-dropdown-menu.bottom {
        bottom: 100%;
        margin-bottom: 4px;
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

    .link-button {
        background: none;
        border: none;
        padding-left: 0.5rem;
        margin: 0;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
    }

    .link-button dt-icon {
        display: inline-flex;
        align-items: center;
        font-size: 1.25em;
    }
    .link-button.inline {
        color: #0000ee;
        text-decoration: underline;
    }

    .journey-header {
        font-weight: bold;
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

    /* Or 39.9375em for standard mobile view */
    @media screen and (max-width: 63.9375em) {

        .button-container {
            justify-content: space-between;
        }

        .fields-container {
            grid-template-columns: 1fr;
        }

        #stage-list li {
            flex-direction: column;
            align-items: flex-start;
            gap: 1em;
        }
        .stage-actions {
            width: 100%;
            justify-content: flex-end;
            border-top: 1px solid #eeeeee;
            padding-top: 0.75em;
        }

        #slider-track {
            flex-wrap: wrap !important;
            transform: none !important;
            transition: none !important;
        }

        #slider-track > .cell {
            flex-shrink: 1 !important;
            width: 100%;
            margin-bottom: 1rem;
        }

        #slider-track.shift-left #section-journey-details,
        #slider-track.shift-left #section-stages-list {
            display: none;
        }

        #section-edit-stage {
            display: none;
        }

        #slider-track.shift-left #section-edit-stage {
            display: block;
        }

        ul {
            margin: 0;
        }
    }

    dialog.dt-delete-dialog {
        border: 1px solid #cccccc;
        border-radius: .5rem;
        padding: 0;
        max-width: 26rem;
        box-shadow: 0 .25rem 1rem rgba(0, 0, 0, 0.15);
        background-color: #ffffff;
        overflow: hidden;
        width: 90%;
    }

    .dialog-content {
        padding: 1.5rem;
    }

    dialog.dt-delete-dialog::backdrop {
        background-color: rgba(0, 0, 0, 0.4);
        backdrop-filter: blur(2px);
    }

    .dialog-title {
        font-weight: bold;
        font-size: 1.25rem;
        margin-top: 0;
        margin-bottom: 1rem;
    }

    .dialog-message {
        color: #555555;
    }

</style>

<template id="stage-row-template">
    <li data-id="" style="border: 1px solid #ccc; padding: 1em; margin: 1em 0; display: flex; justify-content: space-between; background: #fff;">
        <div class="item-details">
            <div class="stage-order">
                <dt-icon class="drag-handle" icon="mdi:reorder-horizontal"></dt-icon>
            </div>
            <div class="stage-data">
                <div class="stage-name" class="stage-name"></div>
                <div class="stage-description" class="stage-description"></div>
                <div class="stage-fields" class="stage-fields"></div>
            </div>
        </div>
        <div class="stage-actions">
            <button class="icon-btn edit-btn"><dt-icon icon="mdi:edit"></dt-icon></button>
            <button class="icon-btn delete-btn"><dt-icon icon="mdi:delete"></dt-icon></button>
        </div>
    </li>
</template>

<dialog id="dt-confirm-dialog" class="dt-delete-dialog">
    <div class="dialog-content">
        <h5 id="dialog-title" class="dialog-title">Confirm Delete</h5>
        <p id="dialog-message" class="dialog-message">Are you sure you want to perform this action?</p>
        <div class="button-container">
            <button type="button" class="button button-back" id="dialog-cancel-btn">
                <?php esc_html_e( 'Cancel', 'disciple_tools' ); ?>
            </button>
            <button type="button" class="button button-delete" id="dialog-confirm-btn">
                <?php esc_html_e( 'Delete', 'disciple_tools' ); ?>
            </button>
        </div>
    </div>
</dialog>

<?php
// Load the Disciple.Tools footer
get_footer();


?>
