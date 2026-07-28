/**
 * Shows a non-blocking, inline warning in the WordPress uploader when a .heic
 * file is added on a server that cannot convert it and does not have cloud
 * conversion set up. Gives immediate feedback where the user is working,
 * instead of a delayed admin notice on the next page load.
 *
 * @package HEIC_Support
 */
( function ( $ ) {
	'use strict';

	var settings  = window.heicSupportUploader || {};
	var NOTICE_ID = 'heic-support-upload-warning';

	function isHeic( name ) {
		return /\.heic$/i.test( name || '' );
	}

	function buildNotice() {
		var $notice = $( '<div>', {
			id: NOTICE_ID,
			'class': 'notice notice-warning is-dismissible',
			css: { margin: '12px 0' }
		} );
		var $p = $( '<p>' );
		$p.append( $( '<strong>' ).text( settings.title + ' ' ) );
		$p.append( document.createTextNode( settings.message + ' ' ) );
		$p.append(
			$( '<a>', { href: settings.url, target: '_blank', rel: 'noopener' } )
				.append( $( '<strong>' ).text( settings.linkText ) )
		);
		$notice.append( $p );

		var $dismiss = $( '<button>', { type: 'button', 'class': 'notice-dismiss' } );
		$dismiss.append( $( '<span>', { 'class': 'screen-reader-text' } ).text( settings.dismiss ) );
		$dismiss.on( 'click', function () {
			$notice.remove();
		} );
		$notice.append( $dismiss );

		return $notice;
	}

	function showNotice() {
		if ( $( '#' + NOTICE_ID ).length ) {
			return;
		}
		var $notice = buildNotice();

		var $ui = $( '#plupload-upload-ui' ).first();
		if ( $ui.length ) {
			$ui.before( $notice );
			return;
		}
		var $inline = $( '.uploader-inline' ).first();
		if ( $inline.length ) {
			$inline.prepend( $notice );
			return;
		}
		var $content = $( '.media-frame-content' ).first();
		if ( $content.length ) {
			$content.prepend( $notice );
			return;
		}
		var $wrap = $( '#wpbody-content .wrap' ).first();
		if ( $wrap.length ) {
			$wrap.prepend( $notice );
		}
	}

	function handleFiles( files ) {
		if ( ! files || ! files.length ) {
			return;
		}
		for ( var i = 0; i < files.length; i++ ) {
			if ( files[ i ] && isHeic( files[ i ].name ) ) {
				showNotice();
				return;
			}
		}
	}

	function bindUploader( instance ) {
		try {
			if ( instance && 'function' === typeof instance.bind ) {
				instance.bind( 'FilesAdded', function ( up, files ) {
					handleFiles( files );
				} );
			}
		} catch ( e ) {}
	}

	// Wrap plupload.Uploader so every uploader instance gets our FilesAdded
	// binding. Both the standalone Add Media page (which calls new
	// plupload.Uploader() directly) and the media modal (via wp.Uploader, which
	// also constructs a plupload.Uploader) funnel through this constructor. This
	// runs synchronously at parse time, before any instance is created on ready.
	if ( window.plupload && 'function' === typeof plupload.Uploader ) {
		var OriginalUploader = plupload.Uploader;

		var WrappedUploader = function ( options ) {
			var instance = new OriginalUploader( options );
			bindUploader( instance );
			return instance;
		};

		for ( var key in OriginalUploader ) {
			if ( Object.prototype.hasOwnProperty.call( OriginalUploader, key ) ) {
				WrappedUploader[ key ] = OriginalUploader[ key ];
			}
		}
		WrappedUploader.prototype = OriginalUploader.prototype;

		plupload.Uploader = WrappedUploader;
	}
} )( jQuery );
