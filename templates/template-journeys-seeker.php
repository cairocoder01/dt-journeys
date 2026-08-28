<?php
/**
 * Template Name: Journeys Admin Page
 */

// Load the Disciple.Tools header (which includes the top nav bar)
get_header();
$seeker_id = get_query_var( 'dt_seeker_id' );

$fields_to_render = [
    'name',
    'journey_category',
    'journey_roles',
    'is_sequential',
    'display_type',
    'next_journey',
    'previous_journeys'
];

$post_settings = DT_Posts::get_post_settings( 'journeys' );
$field_options = isset( $post_settings['fields'] ) ? $post_settings['fields'] : [];

function get_role_options( $field_options ) {
    $role_options = $field_options['journey_roles']['default'] ?? [];
    $role_labels = [];
    foreach ( $role_options as $key => $data ) {
        $role_labels[] = [
            'id'    => $key,
            'label' => isset( $data['label'] ) ? $data['label'] : $key,
        ];
    }
    return wp_json_encode( $role_labels );
}

function get_category_options() {
    $category_options = DT_Posts::get_multi_select_options( 'journeys', 'journey_category', $search = '' );
    $category_labels = [];
    foreach ( $category_options as $category ) {
        $category_labels[] = [
            'id'    => $category,
            'label' => $category,
        ];
    }
    return wp_json_encode( $category_labels );
}

$journey = DT_Posts::get_post( 'journeys', $seeker_id );
if ( is_wp_error( $journey ) ) {
    return null;
}

$stages = [];
foreach ( $journey['stages'] ?? [] as $connected_stage ) {
    // The 'stages' connection field only embeds a lightweight preview
    // (ID, post_title, ...) — fetch the full record for its own fields.
    $stage = DT_Posts::get_post( 'journey_stages', $connected_stage['ID'] );
    $stages[] = $stage;
}

usort( $stages, function( $a, $b ) {
    return $a['stage_order'] <=> $b['stage_order'];
} );

?>

<!-- List Section -->
<div id="content" class="grid-container" style="min-height: 80vh;">
    <div class="grid-x grid-padding-x grid-padding-y">
        <div class="cell">
            <section id="metrics-container" class="cell">
                <div class="bordered-box">
                    <div class="title-row">
                        <h2 class="journey-header"><?php esc_html_e( 'Edit Journey', 'disciple_tools' ); ?></h2>
                        <div>
                            <button class="button button-back" id="back-btn" onclick="go_back()">
                                <?php esc_html_e( 'Go Back', 'disciple_tools' ); ?>
                            </button>
                        </div>
                    </div>
                    <div class="grid-x grid-margin-x">
                        <section class="medium-4 small-12 cell subsection">
                            <h6 class="journey-header"><?php esc_html_e( 'Journey Details', 'disciple_tools' ); ?></h6>
                            
                            <div class="margin-top-1">
                                <?php
                                foreach ( $fields_to_render as $field_key ) {

                                    if ( ! isset( $field_options[ $field_key ] ) ) {
                                        continue;
                                    }

                                    $field = $field_options[ $field_key ];

                                    $field_value = $journey[ $field_key ] ?? '';
                                    $label = $field['name'] ?? $field_key;
                                    $is_required = ! empty( $field['required'] ) ? 'required' : '';

                                    echo '<div style="margin-bottom: 15px;">';
                                    echo '<label style="font-weight: bold; margin-bottom: 5px; display: block;">' . esc_html( $label ) . '</label>';

                                    switch ( $field['type'] ) {

                                        case 'text':
                                            DT_Components::render_text( $field_key, [
                                                $field_key => $field_options[$field_key]
                                            ], [ 'post_type' => 'journeys', $field_key => $field_value ], [ 'allow_add' => true, 'hide_label' => true ] );
                                            break;

                                        case 'boolean':
                                            DT_Components::render_toggle( $field_key, [
                                                $field_key => $field_options[$field_key]
                                            ], [ 'post_type' => 'journeys', $field_key => $field_value ], [ 'allow_add' => true, 'hide_label' => true ] );
                                            break;

                                        case 'key_select':
                                            DT_Components::render_key_select( $field_key, [
                                                $field_key => $field_options[$field_key]
                                            ], [ 'post_type' => 'journeys', $field_key => $field_value ], [ 'allow_add' => true, 'hide_label' => true ] );
                                            break;

                                        case 'tags':
                                            DT_Components::render_tags( $field_key, [
                                                $field_key => $field_options[$field_key]
                                            ], [ 'post_type' => 'journeys', $field_key => $field_value ], [ 'allow_add' => true, 'hide_label' => true, 'placeholder' => __( 'Type to search', 'disciple_tools' ) ] );
                                            break;

                                        case 'multi_select':
                                            $field_options[$field_key]['display'] = 'typeahead';
                                            DT_Components::render_multi_select( $field_key, [
                                                $field_key => $field_options[$field_key]
                                            ], [ 'post_type' => 'journeys', $field_key => $field_value ], [ 'allow_add' => true, 'hide_label' => true, 'placeholder' => __( 'Type to search', 'disciple_tools' ) ] );
                                            break;

                                        case 'connection':
                                            DT_Components::render_connection( $field_key, [
                                                $field_key => $field_options[$field_key]
                                            ], [ 'post_type' => 'journeys', $field_key => $field_value ], [ 'allow_add' => true, 'hide_label' => true, 'placeholder' => __( 'Type to search', 'disciple_tools' ) ] );
                                            break;

                                        default:
                                            echo '<div class="alert-box alert">Unsupported field type: ' . esc_html( $type ) . '</div>';
                                    }

                                    echo '</div>';
                                }
                                ?>
                            </div>
                            <div>
                                <button class="text-btn" onclick="delete_journey(<?php echo esc_js( $seeker_id ); ?>)">Delete Journey</button>
                            </div>
                        </section>
                        <section class="medium-8 small-12 cell subsection">
                            <div class="title-row">
                                <h6 class="journey-header"><?php esc_html_e( 'Stages', 'disciple_tools' ); ?></h6>
                                <button class="button" onclick="add_stage()">
                                    <?php esc_html_e( 'Add Stage', 'disciple_tools' ); ?>
                                </button>
                            </div>
                            <div>
                                <ul id="stage-list" style="cursor: move; list-style: none; padding: 0; margin: 0;">
                                    <?php
                                    foreach ( $stages as $stage ) {
                                        ?>
                                    <li data-id="<?php echo esc_attr( $stage['ID'] ); ?>" id="stage-<?php echo esc_attr( $stage['ID'] ); ?>" style="background-color: #ffffff; border: 1px solid #ccc; border-radius: 5px; padding: 1em; margin: 1em 0; display: flex; justify-content: space-between;">
                                        <div>
                                            <?php echo esc_html( $stage['name'] ); ?>
                                        </div>
                                        <div>
                                            <button class="icon-btn" onclick="run_edit(<?php echo esc_js( $stage['ID'] ); ?>)"><dt-icon icon="mdi:edit"></dt-icon></button>
                                            <button class="icon-btn" onclick="delete_stage(<?php echo esc_js( $seeker_id ); ?>, <?php echo esc_js( $stage['ID'] ); ?>)"><dt-icon icon="mdi:delete"></dt-icon></button>
                                        </div>
                                    </li>
                                        <?php
                                    }
                                    ?>
                                </ul>
                            </div>
                        </section>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<style>
    .title-row { display: flex; justify-content: space-between; column-gap: 1em; }
    .button {
        padding: 0.4em 0.75em;
        border-radius: 5px;
        border: 1px solid transparent;
        cursor: pointer;
        background-color: #3f729b;
        color: #fefefe;
        margin: 0;
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

    .text-btn {
        background: none;
        border: none;
        padding: 0;
        margin: 0;
        font: inherit;
        color: #ff0000;
        cursor: pointer;
        outline: none;
    }
    .text-btn:hover {
        text-decoration: underline;
    }

</style>

<script>
    function go_back() {
        window.location.href = '/admin/journeys/';
    }

    function add_stage() {
        console.log("adding new stage");
    }

    function run_edit(stage_id) {
        console.log(stage_id);
    }

    async function delete_stage(seeker_id, stage_id) {
    
        const stageRow = document.getElementById(`stage-${stage_id}`);

        let response = await fetch(window.seeker_path_js.rest_endpoint + `journeys/stage/${stage_id}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.wpApiShare.nonce,
        },
        }).then((res) => res.json()).then(() => stageRow.remove());
    }

    async function delete_journey(journey_id) {
        let response = await fetch(window.seeker_path_js.rest_endpoint + `journeys/${journey_id}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.wpApiShare.nonce,
        },
        }).then((res) => res.json()).then(() => window.location.href = '/admin/journeys/');
    }
    

    document.addEventListener('DOMContentLoaded', function() {

        const seekerId = <?php echo absint( $seeker_id ); ?>;
        const currentPostType = 'journeys'; // Hardcoded since this is the Journeys admin page
        
        const apiNonce = window.wpApiSettings ? window.wpApiSettings.nonce : (window.wpApiShare ? window.wpApiShare.nonce : '');
        const apiRoot = window.wpApiSettings ? window.wpApiSettings.root : (window.wpApiShare ? window.wpApiShare.root : '/wp-json/');

        if (window.DtWebComponents && window.DtWebComponents.ComponentService) {
            const service = new window.DtWebComponents.ComponentService(
                currentPostType,
                seekerId,
                apiNonce,
                apiRoot
            );
            
            service.initialize();
            
            window.componentService = service;
            
            window.componentService.postId = seekerId;
            window.componentService.postType = currentPostType;
        }
        
    });
</script>

<?php
// Load the Disciple.Tools footer
get_footer();


?>