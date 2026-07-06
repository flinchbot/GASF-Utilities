<?php
/**
 * PDF → PNG — modules/38-pdf-to-png.php
 *
 * Admin tool: upload a PDF (flyer, program, poster…) and every page is
 * rasterized to a PNG attachment in the Media Library, ready to drop into
 * heroes, pages, or events. Server-side via Imagick + Ghostscript (both
 * verified on this host); nothing is sent to any third party.
 *
 * Gate: gasf_site_enable_pdf2png (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_pdf2png' ) : true ) {

	if ( ! defined( 'GASF_PDF2PNG_MAX_PAGES' ) ) { define( 'GASF_PDF2PNG_MAX_PAGES', 40 ); }
	if ( ! defined( 'GASF_PDF2PNG_MAX_BYTES' ) ) { define( 'GASF_PDF2PNG_MAX_BYTES', 30 * 1024 * 1024 ); }

	/**
	 * Convert a PDF file to PNG attachments. Returns
	 * array( 'pages' => [ [id, url] … ], 'errors' => [ … ] ).
	 */
	function gasf_pdf2png_convert( $path, $dpi, $basename ) {
		$out = array( 'pages' => array(), 'errors' => array() );
		if ( ! class_exists( 'Imagick' ) ) { $out['errors'][] = 'Imagick not available on this server.'; return $out; }
		$dpi = in_array( (int) $dpi, array( 72, 100, 150, 200, 300 ), true ) ? (int) $dpi : 150;

		try {
			$probe = new Imagick();
			$probe->pingImage( $path );
			$pages = $probe->getNumberImages();
			$probe->clear();
		} catch ( Throwable $e ) {
			$out['errors'][] = 'Could not read PDF: ' . $e->getMessage();
			return $out;
		}
		if ( $pages < 1 ) { $out['errors'][] = 'PDF has no pages.'; return $out; }
		if ( $pages > GASF_PDF2PNG_MAX_PAGES ) {
			$out['errors'][] = sprintf( 'PDF has %d pages; converting the first %d.', $pages, GASF_PDF2PNG_MAX_PAGES );
			$pages = GASF_PDF2PNG_MAX_PAGES;
		}

		$slug   = sanitize_file_name( preg_replace( '/\.pdf$/i', '', $basename ) ) ?: 'pdf';
		$budget = microtime( true ) + 50; // stay under request limits; report what didn't fit

		require_once ABSPATH . 'wp-admin/includes/image.php';
		for ( $i = 0; $i < $pages; $i++ ) {
			if ( microtime( true ) > $budget ) {
				$out['errors'][] = sprintf( 'Time limit — stopped after page %d of %d. Re-upload with a lower DPI for the rest.', $i, $pages );
				break;
			}
			try {
				$im = new Imagick();
				$im->setResolution( $dpi, $dpi );
				$im->readImage( $path . '[' . $i . ']' );
				$im->setImageBackgroundColor( '#ffffff' );
				$im->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
				$im = $im->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
				$im->setImageFormat( 'png' );
				$blob = $im->getImageBlob();
				$im->clear();
			} catch ( Throwable $e ) {
				$out['errors'][] = 'Page ' . ( $i + 1 ) . ': ' . $e->getMessage();
				continue;
			}

			$fname = $slug . '-p' . ( $i + 1 ) . '.png';
			$up    = wp_upload_bits( $fname, null, $blob );
			if ( ! empty( $up['error'] ) ) { $out['errors'][] = 'Page ' . ( $i + 1 ) . ': ' . $up['error']; continue; }

			$att_id = wp_insert_attachment( array(
				'post_mime_type' => 'image/png',
				'post_title'     => $slug . ' — page ' . ( $i + 1 ),
				'post_status'    => 'inherit',
			), $up['file'] );
			if ( is_wp_error( $att_id ) ) { $out['errors'][] = 'Page ' . ( $i + 1 ) . ': ' . $att_id->get_error_message(); continue; }
			wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $up['file'] ) );
			$out['pages'][] = array( 'id' => (int) $att_id, 'url' => $up['url'] );
		}
		return $out;
	}

	/* ---- admin tab ---- */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) { gasf_utilities_add_tab( 'pdf2png', 'PDF → PNG', 'gasf_pdf2png_admin', 70 ); }
	} );

	function gasf_pdf2png_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$result = null;
		if ( isset( $_POST['gasf_pdf2png_go'] ) && check_admin_referer( 'gasf_pdf2png' ) ) {
			$f = $_FILES['gasf_pdf'] ?? null;
			if ( ! $f || ! empty( $f['error'] ) || empty( $f['tmp_name'] ) ) {
				echo '<div class="notice notice-error"><p>Upload failed — pick a PDF and try again.</p></div>';
			} elseif ( (int) $f['size'] > GASF_PDF2PNG_MAX_BYTES ) {
				echo '<div class="notice notice-error"><p>File is larger than ' . esc_html( size_format( GASF_PDF2PNG_MAX_BYTES ) ) . '.</p></div>';
			} else {
				$check = wp_check_filetype_and_ext( $f['tmp_name'], $f['name'], array( 'pdf' => 'application/pdf' ) );
				$finfo = function_exists( 'mime_content_type' ) ? mime_content_type( $f['tmp_name'] ) : '';
				if ( 'application/pdf' !== ( $check['type'] ?? '' ) && 'application/pdf' !== $finfo ) {
					echo '<div class="notice notice-error"><p>That file isn&rsquo;t a PDF.</p></div>';
				} else {
					$result = gasf_pdf2png_convert( $f['tmp_name'], (int) ( $_POST['dpi'] ?? 150 ), (string) $f['name'] );
					if ( $result['pages'] ) {
						echo '<div class="notice notice-success is-dismissible"><p>Converted ' . count( $result['pages'] ) . ' page(s) to the Media Library.</p></div>';
					}
					foreach ( $result['errors'] as $err ) {
						echo '<div class="notice notice-warning"><p>' . esc_html( $err ) . '</p></div>';
					}
				}
			}
		}

		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'Converts a PDF into PNG images — one per page — saved straight into the Media Library, named "<em>filename — page N</em>". Use it for event flyers, the Oktoberfest program, posters, or anything that arrives as a PDF but needs to be an image on the site (heroes, pages, event covers). Conversion happens entirely on this server (Imagick/Ghostscript); the PDF itself is not kept.',
				'needs'  => array( 'Nothing external — server support verified. Just a PDF under ' . size_format( GASF_PDF2PNG_MAX_BYTES ) . ' and up to ' . GASF_PDF2PNG_MAX_PAGES . ' pages per run.' ),
				'fields' => array(
					'PDF file'   => 'The document to convert. Multi-page PDFs produce one PNG per page.',
					'Resolution' => '<strong>150 DPI</strong> (default) is right for web use — sharp on screens, reasonable file size. Use <strong>72</strong> for quick previews, <strong>300</strong> only if the image will be zoomed/printed (files get big and large PDFs may need two runs).',
					'Convert'    => 'Runs the conversion and lists each created image with links. Everything lands in the Media Library like any normal upload — nothing extra to clean up.',
				),
			) );
		}
		?>
		<h2>PDF &rarr; PNG</h2>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'gasf_pdf2png' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="gasf_pdf">PDF file</label></th>
					<td><input type="file" name="gasf_pdf" id="gasf_pdf" accept="application/pdf,.pdf" required></td></tr>
				<tr><th scope="row"><label for="gasf_dpi">Resolution</label></th>
					<td><select name="dpi" id="gasf_dpi">
						<option value="72">72 DPI — preview</option>
						<option value="150" selected>150 DPI — web (recommended)</option>
						<option value="300">300 DPI — print quality</option>
					</select></td></tr>
			</table>
			<p><button name="gasf_pdf2png_go" value="1" class="button button-primary">Convert to PNG</button></p>
		</form>
		<?php
		if ( $result && $result['pages'] ) {
			echo '<h3 class="title">Created images</h3><div style="display:flex;flex-wrap:wrap;gap:12px">';
			foreach ( $result['pages'] as $p ) {
				$thumb = wp_get_attachment_image( $p['id'], array( 150, 150 ) );
				echo '<div style="width:170px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:8px;text-align:center">'
					. $thumb
					. '<div style="margin-top:6px"><a href="' . esc_url( get_edit_post_link( $p['id'] ) ) . '">Edit</a> · <a href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener">View</a></div>'
					. '<input type="text" readonly onclick="this.select()" value="' . esc_attr( $p['url'] ) . '" style="width:100%;margin-top:4px;font-size:10px">'
					. '</div>';
			}
			echo '</div>';
		}
	}
}
