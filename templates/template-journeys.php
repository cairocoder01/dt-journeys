<?php
/**
 * Template Name: Journeys Admin Page
 */

// Load the Disciple.Tools header (which includes the top nav bar)
get_header(); 

?>

<!-- List Section -->

<div id="content" class="grid-container" style="min-height: 80vh;">
    <div class="grid-x grid-padding-x grid-padding-y">
        <div class="cell">
            <section id="metrics-container" class="medium-12 cell">
                <div class="bordered-box">
                    <div id="journey-chart">

                    </div><!-- Container for charts -->
                </div>
            </section>
        </div>
    </div>
</div>

<?php 
// Load the Disciple.Tools footer
get_footer(); 
?>