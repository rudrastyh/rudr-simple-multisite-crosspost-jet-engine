<?php
/*
 * Plugin name: Simple Multisite Crossposting – JetEngine
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Provides better compatibility with JetEngine.
 * Version: 1.0
 * Plugin URI: https://rudrastyh.com/support/jet-engine-compatibility
 * Network: true
 */

class Rudr_SMC_JE {

	function __construct() {

		add_filter( 'rudr_pre_crosspost_meta', array( $this, 'process_fields' ), 10, 3 );
		add_filter( 'rudr_pre_crosspost_termmeta', array( $this, 'process_fields' ), 10, 3 );

	}

	function get_field( $meta_key, $context, $post_type_or_taxonomy_name ) {
		// that's going to be our field
		$field = array();
		// the best way is to get all the fields for this specific context
		$fields = jet_engine()->meta_boxes->get_fields_for_context(
			$context, // post_type or taxonomy
			$post_type_or_taxonomy_name
		);
//echo '<pre>';print_r( $fields );exit;
		// nothing found
		if( ! $fields ) {
			return $field;
		}
		// find the field in the array of fields
		foreach( $fields as $this_field ) {
			if( $meta_key === $this_field[ 'name' ] ) {
				$field = $this_field;
				break;
			}
		}

		return $field;

	}


	public function process_fields( $meta_value, $meta_key, $object_id ) {

		// if no jet engine installed
		if( ! function_exists( 'jet_engine' ) ) {
			return $meta_value;
		}

		$new_blog_id = get_current_blog_id();
		restore_current_blog();

		// we can not just use acf_get_field( $meta_key ) because it won't work for nested repeater fields
		if( 'rudr_pre_crosspost_termmeta' == current_filter() ) {
			$context = 'taxonomy';
			$post_type_or_taxonomy_name = get_term( $object_id )->taxonomy;
		} else {
			$context = 'post_type';
			$post_type_or_taxonomy_name = get_post_type( $object_id );
		}

		$field = $this->get_field( $meta_key, $context, $post_type_or_taxonomy_name );

		switch_to_blog( $new_blog_id );

		// not a jet engine field specifically
		if( empty( $field ) ) {
			return $meta_value;
		}

		return $this->process_field_by_type( $meta_value, $field );

	}

	public function process_field_by_type( $meta_value, $field ) {

		switch( $field[ 'type' ] ) {
			case 'media': {
				$meta_value = $this->process_media_field( $meta_value, $field );
				break;
			}
			case 'gallery' : {
				$meta_value = $this->process_gallery_field( $meta_value, $field );
				break;
			}
			case 'posts' : {
				$meta_value = $this->process_posts_field( $meta_value, $field );
				break;
			}
		}

		return $meta_value;

	}

	// media
	private function process_media_field( $meta_value, $field ) {
		// if store it as URL, do nothing is ok
		// id, url, both
		if( 'url' === $field[ 'value_format' ] ) {
			return $meta_value;
		}
//echo '<pre>';print_r( $meta_value );exit;
		$meta_value = maybe_unserialize( $meta_value );
		// at this moment we can have:
		// ID
		// Array( 'id' =>, 'url' => )
		if( 'both' === $field[ 'value_format' ] ) {
			$id = $meta_value[ 'id' ];
		} else {
			$id = $meta_value;
		}
		// let's do the image crossposting
		$new_blog_id = get_current_blog_id();
		restore_current_blog();
		$attachment_data = Rudr_Simple_Multisite_Crosspost::prepare_attachment_data( $id );
		switch_to_blog( $new_blog_id );
		$upload = Rudr_Simple_Multisite_Crosspost::maybe_copy_image( $attachment_data );

		if( 'both' === $field[ 'value_format' ] ) {
			if( isset( $upload[ 'id' ] ) && $upload[ 'id' ] ) {
				$meta_value = array(
					'id' => $upload[ 'id' ],
					'url' => $upload[ 'url' ],
				);
			} else {
				$meta_value = array();
			}
		} else {
			if( isset( $upload[ 'id' ] ) && $upload[ 'id' ] ) {
				$meta_value = $upload[ 'id' ];
			} else {
				$meta_value = 0;
			}
		}

		$meta_value = maybe_serialize( $meta_value );
//echo '<pre>';print_r( $meta_value );exit;
		return $meta_value;
	}

	// gallery
	private function process_gallery_field( $meta_value, $field ) {
		// if store it as URL, do nothing is ok
		// id, url, both
		if( 'url' === $field[ 'value_format' ] ) {
			return $meta_value;
		}
//echo '<pre>';print_r( $meta_value );exit;
		$meta_value = maybe_unserialize( $meta_value );
		if( 'both' === $field[ 'value_format' ] ) {
			$ids = array_column( $meta_value, 'id' );
		} else {
			$ids = array_map( 'trim', explode( ',', $meta_value ) );
		}
//echo '<pre>';print_r( $ids );exit;
		// let's do the image crossposting
		$new_blog_id = get_current_blog_id();
		restore_current_blog();
		$attachments_data = array();
		foreach( $ids as $id ) {
			$attachments_data[] = Rudr_Simple_Multisite_Crosspost::prepare_attachment_data( $id );
		}
		switch_to_blog( $new_blog_id );
		$meta_value = array();
		foreach( $attachments_data as $attachment_data ) {
			$upload = Rudr_Simple_Multisite_Crosspost::maybe_copy_image( $attachment_data );
			if( isset( $upload[ 'id' ] ) && $upload[ 'id' ] ) {
				if( 'both' === $field[ 'value_format' ] ) {
					$meta_value[] = array(
						'id' => $upload[ 'id' ],
						'url' => $upload[ 'url' ],
					);
				} else {
					$meta_value[] = $upload[ 'id' ];
				}
			}
		}

		$meta_value = 'both' === $field[ 'value_format' ] ? serialize( $meta_value ) : join( ',', $meta_value );

		return $meta_value;
	}

	// posts
	private function process_posts_field( $meta_value, $field ) {

		$meta_value = maybe_unserialize( $meta_value );
		$ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );
		$new_blog_id = get_current_blog_id();
		restore_current_blog();

		$crossposted_ids = array();
		$crossposted_skus = array(); // we will process it after switching to a new blog
		foreach( $ids as $id ) {
			$post_type = get_post_type( $id );
			if( 'product' === $post_type && 'sku' === Rudr_Simple_Multisite_Woo_Crosspost::connection_type() ) {
				$crossposted_skus[] = get_post_meta( $id, '_sku', true );
			} else {
				if( $new_id = Rudr_Simple_Multisite_Crosspost::is_crossposted( $id, $new_blog_id ) ) {
					$crossposted_ids[] = $new_id;
				}
			}
		}

		switch_to_blog( $new_blog_id );

		// do we have some crossposted SKUs here? let's check if there are some in a new blog
		if( $crossposted_skus ) {
			foreach( $crossposted_skus as $crossposted_sku ) {
				if( $new_id = Rudr_Simple_Multisite_Woo_Crosspost::maybe_is_crossposted_product__sku( array( 'sku' => $crossposted_sku ) ) ) {
					$crossposted_ids[] = $new_id;
				}
			}
		}

		return is_array( $meta_value ) ? maybe_serialize( $crossposted_ids ) : ( $crossposted_ids ? reset( $crossposted_ids ) : 0 );

	}


}


new Rudr_SMC_JE;
