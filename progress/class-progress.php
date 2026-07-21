<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

/**
 * Class DT_Journeys_Progress
 *
 * Progress service layer: tracks a record's (contact/group) progress through
 * `journeys` definitions. Progress lives entirely in post meta on the record,
 * not on the journey/stage posts themselves.
 *
 * Any edit to the record's own fields (e.g. a stage's `related_fields`) must go
 * through `DT_Posts::update_post()` directly, not through this service, so
 * normal field validation/permissions/activity-log apply.
 */
class DT_Journeys_Progress {

    const META_KEY = 'dt_journeys_progress';
    const ACTIVE_MARKER_KEY = 'dt_journeys_active';
    const COMMENT_TYPE = 'journeys';

    const STAGE_STATUSES = [ 'not_started', 'started', 'paused', 'incomplete', 'complete', 'skipped' ];

    /**
     * Get a record's journeys progress.
     *
     * Backfills any stage connected to an active journey since progress was
     * last read/written (e.g. a stage added in the builder after the record
     * started that journey), persisting the merged result so raw-meta readers
     * stay in sync too. Completed journeys are left untouched.
     *
     * @param string $post_type 'contacts' or 'groups'
     * @param int $post_id
     * @param int|null $journey_id When given, return just that journey's progress entry (or null).
     * @return array|null
     */
    public static function get_progress( string $post_type, int $post_id, $journey_id = null ) {
        unset( $post_type );
        $progress = get_post_meta( $post_id, self::META_KEY, true );
        if ( !is_array( $progress ) ) {
            $progress = [];
        }

        $progress = self::backfill_new_stages( $post_id, $progress );

        if ( null !== $journey_id ) {
            return $progress[ (string) $journey_id ] ?? null;
        }
        return $progress;
    }

    /**
     * Add a `not_started` entry for any stage connected to an active journey
     * that isn't yet in its progress entry, and persist the merge if anything
     * changed. Completed journeys are skipped so their historical record
     * doesn't shift when the definition changes after the fact.
     */
    private static function backfill_new_stages( int $post_id, array $progress ) {
        $changed = false;

        foreach ( $progress as $journey_id => &$entry ) {
            if ( ( $entry['status'] ?? '' ) !== 'active' ) {
                continue;
            }

            $journey = DT_Posts::get_post( 'journeys', (int) $journey_id );
            if ( is_wp_error( $journey ) || empty( $journey['stages'] ) ) {
                continue;
            }

            foreach ( $journey['stages'] as $stage ) {
                $stage_key = (string) $stage['ID'];
                if ( !isset( $entry['stages'][ $stage_key ] ) ) {
                    $entry['stages'][ $stage_key ] = [
                        'status' => 'not_started',
                        'date'   => null,
                        'note'   => '',
                    ];
                    $changed = true;
                }
            }
        }
        unset( $entry );

        if ( $changed ) {
            update_post_meta( $post_id, self::META_KEY, $progress );
        }

        return $progress;
    }

    /**
     * Start a journey on a record: initializes progress with all connected
     * stages set to `not_started` and marks the journey active.
     *
     * @return array|WP_Error The journey's progress entry.
     */
    public static function start_journey( string $post_type, int $post_id, int $journey_id ) {
        $journey = DT_Posts::get_post( 'journeys', $journey_id );
        if ( is_wp_error( $journey ) ) {
            return $journey;
        }

        $progress = self::get_progress( $post_type, $post_id );
        $key = (string) $journey_id;
        $old_status = $progress[ $key ]['status'] ?? null;

        $stages = [];
        foreach ( $journey['stages'] ?? [] as $stage ) {
            $stages[ (string) $stage['ID'] ] = [
                'status' => 'not_started',
                'date'   => null,
                'note'   => '',
            ];
        }

        $progress[ $key ] = [
            'started'        => current_time( 'Y-m-d' ),
            'status'         => 'active',
            'completed_date' => null,
            'stages'         => $stages,
        ];

        update_post_meta( $post_id, self::META_KEY, $progress );
        self::sync_active_marker( $post_id, $journey_id, true );
        self::log_activity( $post_type, $post_id, 'journeys_started', $journey_id, $journey['title'] ?? '', 'active', $old_status );

        return $progress[ $key ];
    }

    /**
     * Mark a journey complete.
     *
     * For sequential journeys, `$force` cascades any stage that isn't already
     * `complete`/`skipped` to `skipped` before completing (an explicit
     * "mark journey complete" action). Non-forced completion (the automatic
     * cascade once every stage is independently marked complete) never touches
     * stage statuses.
     *
     * @return array|WP_Error The journey's progress entry.
     */
    public static function complete_journey( string $post_type, int $post_id, int $journey_id, bool $force = false ) {
        $progress = self::get_progress( $post_type, $post_id );
        $key = (string) $journey_id;

        if ( empty( $progress[ $key ] ) ) {
            return new WP_Error( 'journeys_progress', 'Journey has not been started for this record', [ 'status' => 400 ] );
        }

        $journey = DT_Posts::get_post( 'journeys', $journey_id );
        if ( is_wp_error( $journey ) ) {
            return $journey;
        }

        if ( $force && !empty( $journey['is_sequential'] ) ) {
            foreach ( $progress[ $key ]['stages'] as &$stage ) {
                if ( !in_array( $stage['status'], [ 'complete', 'skipped' ], true ) ) {
                    $stage['status'] = 'skipped';
                    $stage['date'] = current_time( 'Y-m-d' );
                }
            }
            unset( $stage );
        }

        $old_status = $progress[ $key ]['status'];
        $progress[ $key ]['status'] = 'completed';
        $progress[ $key ]['completed_date'] = current_time( 'Y-m-d' );

        update_post_meta( $post_id, self::META_KEY, $progress );
        self::sync_active_marker( $post_id, $journey_id, false );
        self::log_activity( $post_type, $post_id, 'journeys_completed', $journey_id, $journey['title'] ?? '', 'completed', $old_status );

        return $progress[ $key ];
    }

    /**
     * Remove a journey instance from a record entirely -- its whole progress
     * entry (started/active or completed) is deleted, not just marked
     * inactive. Distinct from `complete_journey()`: this is for undoing a
     * mistaken or test "Start", not finishing one.
     *
     * @return true|WP_Error
     */
    public static function remove_journey( string $post_type, int $post_id, int $journey_id ) {
        $progress = self::get_progress( $post_type, $post_id );
        $key = (string) $journey_id;

        if ( empty( $progress[ $key ] ) ) {
            return new WP_Error( 'journeys_progress', 'Journey has not been started for this record', [ 'status' => 400 ] );
        }

        $journey = DT_Posts::get_post( 'journeys', $journey_id );
        $journey_name = is_wp_error( $journey ) ? '' : ( $journey['name'] ?? $journey['title'] ?? '' );
        $old_status = $progress[ $key ]['status'] ?? '';

        unset( $progress[ $key ] );
        update_post_meta( $post_id, self::META_KEY, $progress );
        self::sync_active_marker( $post_id, $journey_id, false );
        self::log_activity( $post_type, $post_id, 'journeys_removed', $journey_id, $journey_name, 'removed', $old_status );

        return true;
    }

    /**
     * Set a single stage's status on a record's journey progress.
     *
     * Starts the journey automatically if it hasn't been started yet. Once
     * every connected stage is independently `complete`, the journey is
     * auto-completed (without the sequential skip-cascade `complete_journey()`
     * applies for an explicit "mark complete" action).
     *
     * @return array|WP_Error The journey's progress entry.
     */
    public static function set_stage_status( string $post_type, int $post_id, int $journey_id, int $stage_id, string $status, string $note = '' ) {
        if ( !in_array( $status, self::STAGE_STATUSES, true ) ) {
            return new WP_Error( 'journeys_progress', 'Invalid stage status', [ 'status' => 400 ] );
        }

        $progress = self::get_progress( $post_type, $post_id );
        $key = (string) $journey_id;

        if ( empty( $progress[ $key ] ) ) {
            $started = self::start_journey( $post_type, $post_id, $journey_id );
            if ( is_wp_error( $started ) ) {
                return $started;
            }
            $progress = self::get_progress( $post_type, $post_id );
        }

        $stage_key = (string) $stage_id;
        $old_status = $progress[ $key ]['stages'][ $stage_key ]['status'] ?? 'not_started';

        $progress[ $key ]['stages'][ $stage_key ] = [
            'status' => $status,
            'date'   => current_time( 'Y-m-d' ),
            'note'   => $note,
        ];

        $stage_post = DT_Posts::get_post( 'journey_stages', $stage_id );
        $stage_name = is_wp_error( $stage_post ) ? '' : ( $stage_post['name'] ?? $stage_post['title'] ?? '' );

        update_post_meta( $post_id, self::META_KEY, $progress );
        self::log_activity( $post_type, $post_id, 'journeys_stage_status', $journey_id, $stage_name, $status, $old_status, $stage_id );

        if ( '' !== $note ) {
            $comment_html = '' !== $stage_name ? $stage_name . ': ' . $note : $note;

            DT_Posts::add_post_comment( $post_type, $post_id, $comment_html, self::COMMENT_TYPE, [
                'comment_meta' => [
                    'journeys_journey_id' => $journey_id,
                    'journeys_stage_id'   => $stage_id,
                ],
            ] );
        }

        if ( self::all_stages_complete( $journey_id, $progress[ $key ]['stages'] ) && 'completed' !== $progress[ $key ]['status'] ) {
            return self::complete_journey( $post_type, $post_id, $journey_id, false );
        }

        return $progress[ $key ];
    }

    /**
     * Whether every stage connected to the journey is independently `complete`.
     */
    private static function all_stages_complete( int $journey_id, array $stages ) {
        $journey = DT_Posts::get_post( 'journeys', $journey_id );
        if ( is_wp_error( $journey ) || empty( $journey['stages'] ) ) {
            return false;
        }

        foreach ( $journey['stages'] as $stage ) {
            $status = $stages[ (string) $stage['ID'] ]['status'] ?? 'not_started';
            if ( 'complete' !== $status ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Keep the `dt_journeys_active` marker meta (one value per active journey
     * id) in sync, so the dashboard can query active journeys without scanning
     * the JSON progress blob across every record.
     */
    private static function sync_active_marker( int $post_id, int $journey_id, bool $active ) {
        $existing = get_post_meta( $post_id, self::ACTIVE_MARKER_KEY );
        $has_marker = in_array( (string) $journey_id, array_map( 'strval', $existing ), true );

        if ( $active && !$has_marker ) {
            add_post_meta( $post_id, self::ACTIVE_MARKER_KEY, $journey_id );
        } elseif ( !$active && $has_marker ) {
            delete_post_meta( $post_id, self::ACTIVE_MARKER_KEY, $journey_id );
        }
    }

    /**
     * Mirror a progress change to the DT activity log.
     */
    private static function log_activity( string $post_type, int $post_id, string $action, int $journey_id, string $object_name, $new_value, $old_value, $stage_id = null ) {
        $meta_key = self::META_KEY . '.' . $journey_id;
        if ( null !== $stage_id ) {
            $meta_key .= '.stages.' . $stage_id;
        }

        dt_activity_insert( [
            'action'         => $action,
            'object_type'    => $post_type,
            'object_subtype' => 'journeys',
            'object_id'      => $post_id,
            'object_name'    => $object_name,
            'meta_key'       => $meta_key,
            'meta_value'     => $new_value,
            'old_value'      => $old_value ?? '',
            'field_type'     => 'journeys',
        ] );
    }

    /**
     * The generic activity-feed formatter has no idea what a
     * `dt_journeys_progress.*` meta_key means, so without this it renders
     * these entries as a bare "dt_journeys_progress:" line. `object_name` is
     * the journey's name (start/complete/remove actions) or the stage's name
     * (stage status changes) -- see the `log_activity()` calls above. Action
     * leads (e.g. "Stage Completed: X"), not the name, so a quick scan of the
     * feed shows what happened before which record it happened to.
     */
    public static function format_activity_message( $message, $activity ) {
        if ( ( $activity->field_type ?? '' ) !== 'journeys' ) {
            return $message;
        }

        $stage_status_labels = [
            'not_started' => __( 'Reset to Not Started', 'dt-journeys' ),
            'started'     => __( 'Started', 'dt-journeys' ),
            'paused'      => __( 'Paused', 'dt-journeys' ),
            'incomplete'  => __( 'Stalled', 'dt-journeys' ),
            'complete'    => __( 'Completed', 'dt-journeys' ),
            'skipped'     => __( 'Skipped', 'dt-journeys' ),
        ];
        $name = $activity->object_name ?? '';

        switch ( $activity->action ) {
            case 'journeys_started':
                return '' !== $name
                    ? sprintf( __( 'Journey Started: %s', 'dt-journeys' ), $name )
                    : __( 'Journey Started', 'dt-journeys' );

            case 'journeys_completed':
                return '' !== $name
                    ? sprintf( __( 'Journey Completed: %s', 'dt-journeys' ), $name )
                    : __( 'Journey Completed', 'dt-journeys' );

            case 'journeys_removed':
                return '' !== $name
                    ? sprintf( __( 'Journey Removed: %s', 'dt-journeys' ), $name )
                    : __( 'Journey Removed', 'dt-journeys' );

            case 'journeys_stage_status':
                $status_label = $stage_status_labels[ $activity->meta_value ] ?? $activity->meta_value;
                return '' !== $name
                    /* translators: 1: status label, 2: stage name, e.g. "Stage Completed: Reading Plan" */
                    ? sprintf( __( 'Stage %1$s: %2$s', 'dt-journeys' ), $status_label, $name )
                    : sprintf( __( 'Stage %s', 'dt-journeys' ), $status_label );
        }

        return $message;
    }
}

add_filter( 'dt_format_activity_message', [ 'DT_Journeys_Progress', 'format_activity_message' ], 10, 2 );

/**
 * DT's generic post-meta-change logger (`Disciple_Tools_Hook_Posts`) fires on
 * every `update_post_meta()` call and, not knowing what these two keys mean,
 * dumps their raw (serialized, for META_KEY) value into the activity feed as
 * a second, noisy entry alongside the readable one `log_activity()` above
 * already records for the same change.
 */
add_filter( 'dt_ignore_fields_logging', function ( $ignore_fields ) {
    $ignore_fields[] = DT_Journeys_Progress::META_KEY;
    $ignore_fields[] = DT_Journeys_Progress::ACTIVE_MARKER_KEY;
    return $ignore_fields;
} );
