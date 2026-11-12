/**
 * Media Library convert buttons.
 *
 * @package HEIC_Support
 */
( function ( $ ) {
	'use strict';

	var settings = window.heicSupportLibrary || {};

	function resultBox( $button ) {
		var $wrap = $button.closest( '.heic-support-convert-wrap' );
		var $result = $wrap.length ? $wrap.find( '.heic-support-convert-result' ) : $button.siblings( '.heic-support-convert-result' );
		if ( ! $result.length ) {
			$result = $( '<p>', { 'class': 'heic-support-convert-result' } );
			$button.after( $result );
		}
		return $result.first();
	}

	function showLibraryResult( id, mode, editUrl ) {
		var apply = function () {
			var $wrap = $( '.heic-support-convert[data-id="' + id + '"]' ).closest( '.heic-support-convert-wrap' );
			var $node;
			if ( 'replace' === mode ) {
				$node = $( '<p>' ).text( settings.replaced );
			} else {
				$node = $( '<a>', { href: editUrl, text: settings.viewCopy } );
			}
			if ( $wrap.length ) {
				$wrap.empty().append( $node );
				return;
			}
			var $info = $( '.attachment-details .attachment-info, .attachment-details' ).first();
			if ( $info.length && ! $info.find( '.heic-support-convert-done' ).length ) {
				$info.append( $( '<p>', { 'class': 'heic-support-convert-done' } ).append( $node ) );
			}
		};
		apply();
		window.setTimeout( apply, 400 );
	}

	function refreshReplacedAttachment( id, editUrl ) {
		var cached = wp.media.attachment( id );
		var frame = wp.media.frames && wp.media.frames.edit;
		var displayed = frame && frame.model && String( frame.model.get( 'id' ) ) === String( id ) ? frame.model : cached;

		displayed.fetch().done( function () {
			if ( cached !== displayed ) {
				cached.set( displayed.toJSON() );
			}
			if ( frame && frame.model === displayed && frame.content && frame.content.render ) {
				frame.content.render();
			}
			showLibraryResult( id, 'replace', editUrl );
		} ).fail( function () {
			showLibraryResult( id, 'replace', editUrl );
		} );
	}

	$( document ).on( 'click', '.heic-support-convert', function ( event ) {
		event.preventDefault();
		var $button = $( this );
		if ( $button.prop( 'disabled' ) || $button.attr( 'aria-disabled' ) ) {
			return;
		}
		var isRow = String( $button.data( 'row' ) ) === '1';
		var reloads = isRow || $button.closest( '.heic-support-submit' ).length > 0;
		if ( reloads && String( settings.replace ) === '1' && ! window.confirm( settings.warning ) ) {
			return;
		}

		var id = $button.data( 'id' );
		var label = $button.data( 'label' ) || $button.text();
		var $result = resultBox( $button );
		$button.prop( 'disabled', true ).attr( 'aria-disabled', 'true' ).text( settings.working );
		$result.text( '' );

		$.post( settings.ajaxUrl, {
			action: 'heic_support_convert',
			nonce: settings.nonce,
			id: id
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				var message = response && response.data && response.data.message ? response.data.message : settings.failed;
				if ( isRow ) {
					window.alert( message );
				} else {
					$result.text( message );
				}
				$button.prop( 'disabled', false ).removeAttr( 'aria-disabled' ).text( label );
				return;
			}
			if ( reloads ) {
				window.location.reload();
				return;
			}
			if ( response.data.mode === 'replace' && window.wp && wp.media && wp.media.attachment ) {
				refreshReplacedAttachment( id, response.data.editUrl );
				return;
			}
			showLibraryResult( id, response.data.mode, response.data.editUrl );
		} ).fail( function () {
			if ( isRow ) {
				window.alert( settings.failed );
			} else {
				$result.text( settings.failed );
			}
			$button.prop( 'disabled', false ).removeAttr( 'aria-disabled' ).text( label );
		} );
	} );

}( jQuery ) );
