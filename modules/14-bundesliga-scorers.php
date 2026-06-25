<?php
// Migrated from Code Snippet #14 "Bayern Scorers Shortcode" 2026-06-14 (task 260614-gj8).
// Gate: gasf_site_enable_bundesliga (default ON). Backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_site_enabled( 'gasf_site_enable_bundesliga' ) ) {
/**
 * Bayern München Bundesliga Scorers Shortcode [bundesliga_scorers]
 *
 * Data: OpenLigaDB — parses all season match data to extract Bayern-specific
 *       goal scorers. Cross-references goalGetterId with the getgoalgetters
 *       endpoint for display names.
 *
 * Cache: 1-hour WordPress transient.
 * Season: auto-detects same way as [bundesliga_table].
 * Usage: [bundesliga_scorers] or [bundesliga_scorers limit="15"]
 */
add_shortcode( 'bundesliga_scorers', function( $atts ) {

    $atts  = shortcode_atts( [ 'season' => '', 'limit' => 15 ], $atts );
    $limit = max( 1, intval( $atts['limit'] ) );

    // ── Fetch & compute with season fallback ─────────────────
    $scorers = false;
    $season  = $atts['season'] ? intval( $atts['season'] ) : intval( date('Y') );

    for ( $try = $season; $try >= $season - 2; $try-- ) {
        $cache_key = 'gasf_buli_scorers_' . $try;
        $scorers   = get_transient( $cache_key );
        if ( ! empty( $scorers ) ) { $season = $try; break; }

        // Fetch match data
        $match_resp = wp_remote_get(
            "https://api.openligadb.de/getmatchdata/bl1/{$try}",
            [ 'timeout' => 15 ]
        );
        if ( is_wp_error( $match_resp ) || wp_remote_retrieve_response_code( $match_resp ) !== 200 ) continue;
        $matches = json_decode( wp_remote_retrieve_body( $match_resp ), true );
        if ( empty( $matches ) ) continue;

        // Fetch player name lookup from goal getters endpoint
        $gg_resp  = wp_remote_get( "https://api.openligadb.de/getgoalgetters/bl1/{$try}", [ 'timeout' => 10 ] );
        $name_map = [];
        if ( ! is_wp_error( $gg_resp ) && wp_remote_retrieve_response_code( $gg_resp ) === 200 ) {
            foreach ( json_decode( wp_remote_retrieve_body( $gg_resp ), true ) ?: [] as $g ) {
                $name_map[ $g['goalGetterId'] ] = $g['goalGetterName'];
            }
        }

        // Process Bayern matches (teamId 40)
        $goals_map    = [];
        $pen_map      = [];
        $bayern_id    = 40;

        foreach ( $matches as $match ) {
            if ( empty( $match['matchIsFinished'] ) ) continue;

            $is_t1 = ( intval( $match['team1']['teamId'] ) === $bayern_id );
            $is_t2 = ( intval( $match['team2']['teamId'] ) === $bayern_id );
            if ( ! $is_t1 && ! $is_t2 ) continue;

            $prev_s1 = 0;
            foreach ( $match['goals'] ?? [] as $goal ) {
                if ( empty( $goal['goalGetterID'] ) ) { $prev_s1 = $goal['scoreTeam1']; continue; }

                $t1_scored      = ( intval( $goal['scoreTeam1'] ) > $prev_s1 );
                $is_own         = ! empty( $goal['isOwnGoal'] );
                $is_bayern_goal = ( $is_t1 && $t1_scored && ! $is_own )
                               || ( $is_t2 && ! $t1_scored && ! $is_own );

                if ( $is_bayern_goal ) {
                    $id = intval( $goal['goalGetterID'] );
                    $goals_map[ $id ] = ( $goals_map[ $id ] ?? 0 ) + 1;
                    if ( ! empty( $goal['isPenalty'] ) ) {
                        $pen_map[ $id ] = ( $pen_map[ $id ] ?? 0 ) + 1;
                    }
                }
                $prev_s1 = intval( $goal['scoreTeam1'] );
            }
        }

        if ( empty( $goals_map ) ) continue;

        arsort( $goals_map );
        $scorers = [];
        foreach ( $goals_map as $id => $count ) {
            $scorers[] = [
                'name'      => $name_map[ $id ] ?? "Player #{$id}",
                'goals'     => $count,
                'penalties' => $pen_map[ $id ] ?? 0,
            ];
        }

        $season = $try;
        set_transient( $cache_key, $scorers, HOUR_IN_SECONDS );
        // Save Bayern player IDs for use by [bundesliga_top_scorers]
        set_transient( 'gasf_buli_bayern_ids_' . $try, array_keys( $goals_map ), HOUR_IN_SECONDS );
        break;
    }

    if ( empty( $scorers ) ) {
        return '<p style="color:#999;font-size:13px;">Bayern scorer data unavailable — please check back later.</p>';
    }

    // ── Build HTML ────────────────────────────────────────────
    $season_display = $season . '/' . substr( $season + 1, -2 );
    $display        = array_slice( $scorers, 0, $limit );

    $html  = '<div class="gasf-buli-wrap" style="margin-top:16px;">';
    $html .= '<div class="gasf-buli-header">';
    $html .= '<span class="gasf-buli-logo">🥅</span>';
    $html .= '<span class="gasf-buli-title">Bayern Scorers ' . esc_html( $season_display ) . '</span>';
    $html .= '</div>';
    $html .= '<div class="gasf-buli-scroll"><table class="gasf-buli-table">';
    $html .= '<thead><tr>';
    $html .= '<th class="gasf-buli-th gasf-buli-rank">#</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-club">Player</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-pts" title="Goals">G</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-num" title="Penalties scored">(P)</th>';
    $html .= '</tr></thead><tbody>';

    foreach ( $display as $i => $s ) {
        $rank       = $i + 1;
        $row_style  = ( $rank % 2 === 0 ) ? 'background:#f8f8f8;' : '';
        $pen_label  = $s['penalties'] > 0 ? $s['penalties'] : '—';
        $html .= '<tr style="' . $row_style . '">';
        $html .= '<td class="gasf-buli-td gasf-buli-rank">' . $rank . '</td>';
        $html .= '<td class="gasf-buli-td gasf-buli-club"><span class="gasf-buli-name">' . esc_html( $s['name'] ) . '</span></td>';
        $html .= '<td class="gasf-buli-td gasf-buli-pts">' . $s['goals'] . '</td>';
        $html .= '<td class="gasf-buli-td gasf-buli-num">' . $pen_label . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    $html .= '<p class="gasf-buli-source">Bundesliga goals only &middot; Data: <a href="https://openligadb.de" target="_blank" rel="noopener" style="color:inherit;">OpenLigaDB</a></p>';
    $html .= '</div>';

    return $html;
} );
}
