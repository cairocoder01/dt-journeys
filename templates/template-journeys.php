<?php
/**
 * Template Name: Journeys Admin Page
 */

// Load the Disciple.Tools header (which includes the top nav bar)
get_header();

$template_dir = get_template_directory_uri();

?>

<!-- List Section -->

<div id="content" class="grid-container" style="min-height: 80vh;">
    <div class="grid-x grid-padding-x grid-padding-y">
        <div class="cell">
            <section id="metrics-container" class="medium-12 cell">
                <div class="bordered-box">
                    <div id="journey-chart">
                        <journeys-table/>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<style>
    journeys-table {
        --sort-both: url('<?php echo esc_url( $template_dir . '/dt-assets/images/sort_both.png' ); ?>');
        --sort-desc: url('<?php echo esc_url( $template_dir . '/dt-assets/images/sort_desc.png' ); ?>');
        --sort-asc: url('<?php echo esc_url( $template_dir . '/dt-assets/images/sort_asc.png' ); ?>');
    }
</style>

<?php
// Load the Disciple.Tools footer
get_footer();
?>