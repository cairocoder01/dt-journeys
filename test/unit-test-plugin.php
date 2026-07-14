<?php

class PluginTest extends TestCase
{
    public function test_plugin_installed() {
        activate_plugin( 'dt-journeys/dt-journeys.php' );

        $this->assertContains(
            'dt-journeys/dt-journeys.php',
            get_option( 'active_plugins' )
        );
    }
}
