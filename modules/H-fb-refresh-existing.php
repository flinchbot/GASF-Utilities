<?php
/**
 * Module H — Refresh existing events from Facebook (FB = source of truth).
 * On each sync, overwrites title, description, date/time and featured image on UPCOMING
 * FB-imported events so Facebook edits propagate (the importer dedup otherwise skips them).
 * Location is intentionally NOT synced (the club doesn't use FB place data).
 * Events whose FB source is deleted (not-found, 2 consecutive cycles) are auto-unpublished.
 *
 * Gate: gasf_mec_enable_fb_refresh (enabled via option).
 * Manual: gasf_mec_fb_refresh_event($post_id, $dry_run)  — works even when gate off.
 * Task 260620-2mv.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function gasf_mec_fb_refresh_event( $post_id, $dry_run = false ) {
	$post_id = (int) $post_id;
	$p = get_post( $post_id );
	if ( ! $p || $p->post_type !== 'mec-events' ) return array( 'skip' => 'not a mec-event' );
	$fb_id = get_post_meta( $post_id, 'mec_advimp_facebook_event_id', true );
	if ( ! $fb_id ) return array( 'skip' => 'no facebook id' );
	$token = function_exists( 'gasf_mec_fb_token' ) ? gasf_mec_fb_token() : null;
	if ( ! $token ) return array( 'skip' => 'no fb token' );

	$resp = wp_remote_get(
		'https://graph.facebook.com/v18.0/' . rawurlencode( $fb_id )
		. '?fields=id,name,description,start_time,end_time,timezone,cover&access_token=' . $token,
		array( 'timeout' => 20 )
	);
	if ( is_wp_error( $resp ) ) return array( 'skip' => 'http error: ' . $resp->get_error_message() );
	$http = wp_remote_retrieve_response_code( $resp );
	$ev = json_decode( wp_remote_retrieve_body( $resp ) );

	if ( ! $ev || isset( $ev->error ) ) {
		$ecode = isset( $ev->error->code ) ? (int) $ev->error->code : 0;
		$emsg  = isset( $ev->error->message ) ? (string) $ev->error->message : '';
		// Conservative 'deleted' test: a genuine not-found, NOT transient rate-limit/auth.
		$notfound = ( $http === 404 ) || ( $ecode === 100 && stripos( $emsg, 'does not exist' ) !== false );
		if ( $notfound ) {
			$miss = (int) get_post_meta( $post_id, 'gasf_mec_fb_missing', true ) + 1;
			if ( ! $dry_run ) update_post_meta( $post_id, 'gasf_mec_fb_missing', $miss );
			if ( $miss >= 2 && ! $dry_run && $p->post_status === 'publish' ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
				if ( function_exists( 'gasf_mec_log' ) ) gasf_mec_log( "FB-DELETED post=$post_id '$p->post_title' unpublished (fb event gone)" );
				return array( 'post_id' => $post_id, 'applied' => array( 'unpublished (fb deleted)' ) );
			}
			return array( 'skip' => 'fb not-found', 'miss' => $miss );
		}
		return array( 'skip' => 'fb transient error', 'code' => $ecode, 'http' => $http );
	}
	if ( ! $dry_run ) delete_post_meta( $post_id, 'gasf_mec_fb_missing' ); // recovered

	$changes = array(); $audit = array();
	$is_recurring = (bool) get_post_meta( $post_id, 'gasf_mec_recurring_parent', true );
	$post_update = array();

	// FIX 1 — Title entity normalization: decode HTML entities on BOTH sides before comparing
	// so &amp; stored in DB and & returned by FB API are treated as equal unless the title
	// truly changed. Without this, wp_update_post fires every cycle (writes & to DB, WP
	// re-encodes to &amp; on storage, mismatch persists forever → infinite write loop).
	if ( isset( $ev->name ) && trim( $ev->name ) !== '' ) {
		$ev_name_normalized    = html_entity_decode( trim( $ev->name ),    ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$stored_title_normalized = html_entity_decode( $p->post_title,       ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $ev_name_normalized !== $stored_title_normalized ) {
			$changes['title'] = true; $post_update['post_title'] = $ev->name;
			$audit[] = "title: '" . mb_substr( $p->post_title, 0, 60 ) . "' -> '" . mb_substr( $ev->name, 0, 60 ) . "'";
		}
	}
	$desc = isset( $ev->description ) ? (string) $ev->description : '';
	if ( $desc !== $p->post_content ) {
		$changes['content'] = true; $post_update['post_content'] = $desc;
		$audit[] = 'content: ' . strlen( $p->post_content ) . '->' . strlen( $desc ) . ' chars';
	}

	// date/time
	$dt_fields = null;
	if ( ! empty( $ev->start_time ) ) {
		try {
			$start = new DateTime( $ev->start_time ); $start->setTimezone( new DateTimeZone( 'America/New_York' ) );
			$end = ! empty( $ev->end_time ) ? new DateTime( $ev->end_time ) : ( clone $start )->modify( '+2 hours' );
			$end->setTimezone( new DateTimeZone( 'America/New_York' ) );
			$dt_fields = array(
				'sdate'=>$start->format('Y-m-d'),'sh'=>(int)$start->format('g'),'sm'=>(int)$start->format('i'),'sap'=>$start->format('A'),'ssec'=>(int)$start->format('G')*3600+(int)$start->format('i')*60,
				'edate'=>$end->format('Y-m-d'),'eh'=>(int)$end->format('g'),'em'=>(int)$end->format('i'),'eap'=>$end->format('A'),'esec'=>(int)$end->format('G')*3600+(int)$end->format('i')*60,
			);
			$cur_sd=get_post_meta($post_id,'mec_start_date',true); $cur_ss=(int)get_post_meta($post_id,'mec_start_day_seconds',true); $cur_es=(int)get_post_meta($post_id,'mec_end_day_seconds',true);
			$cur_str = $cur_sd.' '.gmdate('g:i A',$cur_ss).'-'.gmdate('g:i A',$cur_es);
			$new_str = $dt_fields['sdate'].' '.gmdate('g:i A',$dt_fields['ssec']).'-'.gmdate('g:i A',$dt_fields['esec']);
			if ( $is_recurring ) {
				if ( $cur_ss !== $dt_fields['ssec'] || $cur_es !== $dt_fields['esec'] ) { $changes['time']=true; $audit[]='time(recurring): '.gmdate('g:i A',$cur_ss).'->'.gmdate('g:i A',$dt_fields['ssec']); }
			} else {
				if ( $cur_sd !== $dt_fields['sdate'] || $cur_ss !== $dt_fields['ssec'] || $cur_es !== $dt_fields['esec'] ) { $changes['datetime']=true; $audit[]='datetime: '.$cur_str.' -> '.$new_str; }
			}
		} catch ( Exception $e ) { $dt_fields=null; }
	}

	// FIX 2 — Self-healing image check: if the current thumbnail's file is missing from disk,
	// force a re-sideload on every cycle regardless of whether the FB cover ID changed.
	// This recovers from any external cause (host cleanup, orphan-delete collision, etc.)
	// without requiring a manual intervention.
	$needs_image_heal = false;
	if ( isset( $ev->cover->source ) ) {
		$cover_id = isset( $ev->cover->id ) ? (string) $ev->cover->id : md5( $ev->cover->source );
		if ( get_post_meta( $post_id, 'gasf_mec_fb_cover_id', true ) !== $cover_id ) {
			// Cover changed on FB — always sideload.
			$changes['image'] = true; $audit[] = 'image: cover synced';
		} else {
			// Cover ID unchanged — but verify file is actually on disk (self-heal).
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumb_id ) {
				$thumb_file = get_attached_file( $thumb_id );
				if ( ! $thumb_file || ! file_exists( $thumb_file ) ) {
					// File is missing — force re-sideload so the event auto-recovers.
					$needs_image_heal = true;
					$changes['image'] = true;
					$audit[] = 'image: cover re-sideloaded (file was missing from disk)';
					if ( function_exists( 'gasf_mec_log' ) ) {
						gasf_mec_log( "FB-IMG-HEAL post=$post_id thumb=$thumb_id file missing — forcing re-sideload" );
					}
				}
			} else {
				// No thumbnail at all — force re-sideload.
				$needs_image_heal = true;
				$changes['image'] = true;
				$audit[] = 'image: cover re-sideloaded (no thumbnail set)';
				if ( function_exists( 'gasf_mec_log' ) ) {
					gasf_mec_log( "FB-IMG-HEAL post=$post_id no thumbnail — forcing re-sideload" );
				}
			}
		}
	}

	if ( $dry_run ) return array( 'post_id'=>$post_id, 'recurring'=>$is_recurring, 'changes'=>$audit ?: 'none' );
	if ( empty( $changes ) ) return array( 'post_id'=>$post_id, 'changes'=>'none' );

	// APPLY
	if ( $post_update ) { $post_update['ID']=$post_id; wp_update_post( $post_update ); }
	if ( $dt_fields && isset( $changes['datetime'] ) ) gasf_mec_write_single_datetime( $post_id, $dt_fields );
	elseif ( $dt_fields && $is_recurring && isset( $changes['time'] ) ) gasf_mec_write_time_meta_only( $post_id, $dt_fields );
	if ( isset( $changes['image'] ) ) {
		if ( $needs_image_heal ) {
			// On a heal, clear the stale thumbnail and cover-id first so sideload starts fresh.
			$stale_thumb = (int) get_post_thumbnail_id( $post_id );
			delete_post_thumbnail( $post_id );
			delete_post_meta( $post_id, 'gasf_mec_fb_cover_id' );
			// Purge the stale attachment record if its file is gone and it is not used elsewhere.
			if ( $stale_thumb && ! gasf_mec_attachment_in_use( $stale_thumb, $post_id ) ) {
				$stale_file = get_attached_file( $stale_thumb );
				if ( ! $stale_file || ! file_exists( $stale_file ) ) {
					// Safe to remove the orphan DB record whose file is already gone.
					wp_delete_attachment( $stale_thumb, true );
					if ( function_exists( 'gasf_mec_log' ) ) {
						gasf_mec_log( 'FB-IMG-HEAL-PURGE deleted stale attachment=' . $stale_thumb . ' (file was missing, post=' . $post_id . ')' );
					}
				}
			}
		}
		gasf_mec_sideload_cover( $post_id, $ev->cover );
	}

	if ( function_exists( 'gasf_mec_log' ) ) gasf_mec_log( "FB-REFRESH post=$post_id | " . implode( ' | ', $audit ) );
	return array( 'post_id'=>$post_id, 'applied'=>$audit );
}

function gasf_mec_write_single_datetime( $post_id, $f ) {
	global $wpdb;
	update_post_meta($post_id,'mec_start_date',$f['sdate']); update_post_meta($post_id,'mec_end_date',$f['edate']);
	update_post_meta($post_id,'mec_start_time_hour',$f['sh']); update_post_meta($post_id,'mec_start_time_minutes',$f['sm']); update_post_meta($post_id,'mec_start_time_ampm',$f['sap']);
	update_post_meta($post_id,'mec_end_time_hour',$f['eh']); update_post_meta($post_id,'mec_end_time_minutes',$f['em']); update_post_meta($post_id,'mec_end_time_ampm',$f['eap']);
	update_post_meta($post_id,'mec_start_day_seconds',$f['ssec']); update_post_meta($post_id,'mec_end_day_seconds',$f['esec']);
	update_post_meta($post_id,'mec_start_datetime',$f['sdate'].' '.sprintf('%d:%02d %s',$f['sh'],$f['sm'],$f['sap']));
	update_post_meta($post_id,'mec_end_datetime',$f['edate'].' '.sprintf('%d:%02d %s',$f['eh'],$f['em'],$f['eap']));
	$md=get_post_meta($post_id,'mec_date',true); if(!is_array($md))$md=array();
	$md['start']=array('date'=>$f['sdate'],'hour'=>(string)$f['sh'],'minutes'=>(string)$f['sm'],'ampm'=>$f['sap']);
	$md['end']=array('date'=>$f['edate'],'hour'=>(string)$f['eh'],'minutes'=>(string)$f['em'],'ampm'=>$f['eap']);
	update_post_meta($post_id,'mec_date',$md);
	$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}mec_events SET start=%s, end=%s, time_start=%d, time_end=%d WHERE post_id=%d",$f['sdate'],$f['edate'],$f['ssec'],$f['esec'],(int)$post_id));
	if (class_exists('MEC')) { $s=MEC::getInstance('app.libraries.schedule'); if ($s && method_exists($s,'reschedule')) $s->reschedule($post_id,300); }
}

function gasf_mec_write_time_meta_only( $post_id, $f ) {
	update_post_meta($post_id,'mec_start_time_hour',$f['sh']); update_post_meta($post_id,'mec_start_time_minutes',$f['sm']); update_post_meta($post_id,'mec_start_time_ampm',$f['sap']);
	update_post_meta($post_id,'mec_end_time_hour',$f['eh']); update_post_meta($post_id,'mec_end_time_minutes',$f['em']); update_post_meta($post_id,'mec_end_time_ampm',$f['eap']);
	update_post_meta($post_id,'mec_start_day_seconds',$f['ssec']); update_post_meta($post_id,'mec_end_day_seconds',$f['esec']);
}

function gasf_mec_sideload_cover( $post_id, $cover ) {
	static $count = 0;
	if ( $count >= 8 ) return false; // per-run cap; uncapped events retry next cycle
	if ( empty( $cover->source ) ) return false;
	$old_thumb = (int) get_post_thumbnail_id( $post_id );
	$cover_id = isset($cover->id) ? (string)$cover->id : md5($cover->source);
	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/image.php';
	}
	$tmp = download_url( $cover->source, 25 );
	if ( is_wp_error( $tmp ) ) return false;

	// DEDUP: recurring events (e.g. ~175 Biergartens) all carry the SAME cover. If this exact
	// image already exists as a master attachment, reuse it instead of storing another copy.
	$sha = @sha1_file( $tmp );
	$att = $sha ? gasf_mec_find_cover_master( $sha ) : 0;
	if ( $att ) {
		@unlink( $tmp ); // identical master found -- no new file stored
	} else {
		$att = media_handle_sideload( array('name'=>'fb-cover-'.$post_id.'.jpg','tmp_name'=>$tmp), $post_id, null );
		if ( is_wp_error( $att ) ) { @unlink($tmp); return false; }
		if ( $sha ) update_post_meta( $att, '_gasf_cover_sha1', $sha ); // register this master in the table
	}
	set_post_thumbnail( $post_id, $att );
	update_post_meta( $post_id, 'gasf_mec_fb_cover_id', $cover_id );
	$count++;

	// FIX 3 — Hardened orphan cleanup: before deleting the old thumbnail, verify that
	// its underlying file path DIFFERS from the new attachment's file path. If they share
	// the same path (stale DB record pointing to a file now owned by the new attachment),
	// skip the delete to avoid nuking the freshly-written files.
	// Also refuse to delete a dedup master (_gasf_cover_sha1) that is still referenced by
	// another post's _thumbnail_id — gasf_mec_attachment_in_use already covers that case,
	// but the file-path guard adds a second safety layer against the ghost-attachment bug.
	if ( $old_thumb && $old_thumb !== (int) $att ) {
		$old_file = get_attached_file( $old_thumb );
		$new_file = get_attached_file( (int) $att );
		// Skip delete if old record points to the same physical file as the new attachment
		// (ghost-attachment scenario: old file was already gone but DB path matches new file).
		$same_file = ( $old_file && $new_file && wp_normalize_path( $old_file ) === wp_normalize_path( $new_file ) );
		if ( ! $same_file && ! gasf_mec_attachment_in_use( $old_thumb, $post_id ) ) {
			wp_delete_attachment( $old_thumb, true );
			if ( function_exists( 'gasf_mec_log' ) ) gasf_mec_log( 'FB-IMG-ORPHAN deleted attachment=' . $old_thumb . ' (replaced on post=' . $post_id . ')' );
		} elseif ( $same_file ) {
			// Ghost attachment: old record points to the same file now owned by the new
			// attachment. Leave the orphan DB row in place — it is harmless (no file,
			// no thumbnail reference) and touching it risks deleting the new file.
			// A future media library cleanup can remove it safely.
			if ( function_exists( 'gasf_mec_log' ) ) gasf_mec_log( 'FB-IMG-GHOST skipped delete of attachment=' . $old_thumb . ' (same file path as new att=' . (int)$att . ', post=' . $post_id . ')' );
		}
	}
	return $att;
}

function gasf_mec_attachment_in_use( $att_id, $exclude_post ) {
	global $wpdb;
	$att_id = (int) $att_id;
	// used as another post's featured image?
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_key='_thumbnail_id' AND meta_value=%d AND post_id<>%d", $att_id, (int) $exclude_post ) ) ) return true;
	$url = wp_get_attachment_url( $att_id );
	$base = $url ? basename( parse_url( $url, PHP_URL_PATH ) ) : '';
	$base = $base ? preg_replace( '/-\\d+x\\d+(?=\\.\\w+$)/', '', $base ) : '';
	$base = $base ? preg_replace( '/\\.\\w+$/', '', $base ) : '';
	if ( $base ) {
		// embedded in post content (by filename or wp-image-<id> class)?
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_status NOT IN ('trash') AND ( post_content LIKE %s OR post_content LIKE %s )", '%' . $wpdb->esc_like( $base ) . '%', '%wp-image-' . $att_id . '%' ) ) ) return true;
		// referenced in SiteOrigin page-builder data?
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_key='panels_data' AND ( meta_value LIKE %s OR meta_value LIKE %s )", '%' . $wpdb->esc_like( $base ) . '%', '%"' . $att_id . '"%' ) ) ) return true;
	}
	return false;
}

/**
 * The de-dup table: find an existing 'master' cover attachment by content hash.
 * Returns the lowest-ID attachment tagged with this sha whose file still exists, else 0.
 */
function gasf_mec_find_cover_master( $sha ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key='_gasf_cover_sha1' AND meta_value=%s ORDER BY post_id ASC", $sha ) );
	foreach ( $ids as $id ) {
		$f = get_attached_file( (int) $id );
		if ( $f && file_exists( $f ) ) return (int) $id;
	}
	return 0;
}

function gasf_mec_fb_refresh_all() {
	$last=(int)get_option('gasf_mec_fb_refresh_at',0); if ((time()-$last)<3300) return; update_option('gasf_mec_fb_refresh_at',time());
	global $wpdb; $today=date('Y-m-d');
	$ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p JOIN {$wpdb->prefix}postmeta fb ON fb.post_id=p.ID AND fb.meta_key='mec_advimp_facebook_event_id' JOIN {$wpdb->prefix}postmeta sd ON sd.post_id=p.ID AND sd.meta_key='mec_start_date' WHERE p.post_type='mec-events' AND p.post_status='publish' AND sd.meta_value >= %s",$today));
	$n=0; foreach ($ids as $pid){ gasf_mec_fb_refresh_event((int)$pid,false); $n++; }
	if (function_exists('gasf_mec_log')) gasf_mec_log('FB-REFRESH-ALL upcoming='.$n);
}

if ( gasf_mec_enabled( 'gasf_mec_enable_fb_refresh', '0' ) ) {
	add_action( 'mec_advimp_sync_hook', 'gasf_mec_fb_refresh_all', 997 );
}
