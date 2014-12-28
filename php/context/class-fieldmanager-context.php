<?php
/**
 * @package Fieldmanager_Context
 */

/**
 * Base class for context
 * @package Fieldmanager_Context
 */
abstract class Fieldmanager_Context {

	/**
	 * @var Fieldmanager_Field
	 * The base field associated with this context
	 */
	public $fm = Null;

	/**
	 * @var string
	 * Unique ID of the form. Used for forms that are not built into WordPress.
	 */
	public $uniqid;

	/**
	 * Store the meta keys this field saves to, to catch naming conflicts.
	 * @var array
	 */
	public $save_keys = array();

	/**
	 * Check if the nonce is valid. Returns false if the nonce is missing and
	 * throws an exception if it's invalid. If all goes well, returns true.
	 *
	 * @return boolean
	 */
	protected function is_valid_nonce() {
		if ( empty( $_POST['fieldmanager-' . $this->fm->name . '-nonce'] ) ) {
			return false;
		}

		if ( ! wp_verify_nonce( $_POST['fieldmanager-' . $this->fm->name . '-nonce'], 'fieldmanager-save-' . $this->fm->name ) ) {
			$this->fm->_unauthorized_access( __( 'Nonce validation failed', 'fieldmanager' ) );
		}

		return true;
	}


	/**
	 * Prepare the data for saving.
	 *
	 * @param  mixed $old_value Optional. The previous value.
	 * @param  mixed $new_value Optional. The new value for the field.
	 * @param  object $fm Optional. The Fieldmanager field to prepare.
	 * @return mixed The filtered and sanitized value, safe to save.
	 */
	protected function prepare_data( $old_value = null, $new_value = null, $fm = null ) {
		if ( null === $fm ) {
			$fm = $this->fm;
		}
		if ( null === $new_value ) {
			$new_value = isset( $_POST[ $this->fm->name ] ) ? $_POST[ $this->fm->name ] : '';
		}
		$new_value = apply_filters( "fm_context_before_presave_data", $new_value, $old_value, $this );
		$data = $fm->presave_all( $new_value, $old_value );
		return apply_filters( "fm_context_after_presave_data", $data, $old_value, $this );
	}


	/**
	 * Render the field.
	 *
	 * @param array $args {
	 *     Optional. Arguments to adjust the rendering behavior.
	 *
	 *     @type mixed $data The existing data to display with the field. If
	 *                       absent, data will be loaded using
	 *                       Fieldmanager_Context::_load().
	 *     @type boolean $echo Output if true, return if false. Default is true.
	 * }
	 * @return string if $args['echo'] == false.
	 */
	protected function render_field( $args = array() ) {
		$data = array_key_exists( 'data', $args ) ? $args['data'] : $this->load();
		$echo = isset( $args['echo'] ) ? $args['echo'] : true;

		$nonce = wp_nonce_field( 'fieldmanager-save-' . $this->fm->name, 'fieldmanager-' . $this->fm->name . '-nonce', true, false );
		$field = $this->fm->element_markup( $data );
		if ( $echo ) {
			echo $nonce . $field;
		} else {
			return $nonce . $field;
		}
	}


	/**
	 * Handle saving data for any context.
	 *
	 * @param mixed $data Data to save. Should be raw, e.g. POST data.
	 */
	protected function save( $data = null ) {
		// Reset the save keys in the event this context instance is saved twice
		$this->save_keys = array();

		if ( $this->fm->serialize_data ) {
			$this->save_field( $this->fm, $data, $this->fm->data_id );
		} else {
			if ( null === $data ) {
				$data = isset( $_POST[ $this->fm->name ] ) ? $_POST[ $this->fm->name ] : '';
			}
			$this->save_walk_children( $this->fm, $data, $this->fm->data_id );
		}
	}

	/**
	 * Save a single field.
	 *
	 * @param  object $field Fieldmanager field.
	 * @param  mixed $data Data to save.
	 */
	protected function save_field( $field, $data ) {
		$field->data_id = $this->fm->data_id;
		$field->data_type = $this->fm->data_type;

		if ( isset( $this->save_keys[ $field->get_element_key() ] ) ) {
			throw new FM_Developer_Exception( sprintf( esc_html__( 'You have two fields in this group saving to the same key: %s', 'fieldmanager' ), $field->get_element_key() ) );
		} else {
			$this->save_keys[ $field->get_element_key() ] = true;
		}

		$current = $this->get_data( $this->fm->data_id, $field->get_element_key(), $field->serialize_data );
		$data = $this->prepare_data( $current, $data, $field );
		if ( ! $field->skip_save ) {
			if ( $field->serialize_data ) {
				$this->update_data( $this->fm->data_id, $field->get_element_key(), $data );
			} else {
				$this->delete_data( $this->fm->data_id, $field->get_element_key() );
				foreach ( $data as $value ) {
					$this->add_data( $this->fm->data_id, $field->get_element_key(), $value );
				}
			}
		}
	}

	/**
	 * Walk group children to save when serialize_data => false.
	 *
	 * @param  object $field Fieldmanager field.
	 * @param  mixed $data Data to save.
	 */
	protected function save_walk_children( $field, $data ) {
		if ( $field->serialize_data || ! $field->is_group() ) {
			$this->save_field( $field, $data );
		} else {
			foreach ( $field->children as $child ) {
				if ( isset( $data[ $child->name ] ) ) {
					$this->save_walk_children( $child, $data[ $child->name ] );
				}
			}
		}
	}


	/**
	 * Handle loading data for any context.
	 *
	 * @return mixed The loaded data.
	 */
	protected function load() {
		if ( $this->fm->serialize_data ) {
			return $this->load_field( $this->fm, $this->fm->data_id );
		} else {
			return $this->load_walk_children( $this->fm, $this->fm->data_id );
		}
	}

	/**
	 * Load a single field.
	 *
	 * @param  object $field The Fieldmanager field for which to load data.
	 * @return mixed Data stored for that field in this context.
	 */
	protected function load_field( $field ) {
		$data = $this->get_data( $this->fm->data_id, $field->get_element_key() );
		if ( $field->serialize_data ) {
			return empty( $data ) ? null : reset( $data );
		} else {
			return $data;
		}
	}

	/**
	 * Walk group children to load when serialize_data => false.
	 *
	 * @param  object $field Fieldmanager field for which to load data.
	 * @return mixed Data stored for a singular field with serialized data, or
	 *               array of data for a groups's children.
	 */
	protected function load_walk_children( $field ) {
		if ( $field->serialize_data || ! $field->is_group() ) {
			return $this->load_field( $field );
		} else {
			$return = array();
			foreach ( $field->children as $child ) {
				$return[ $child->name ] = $this->load_walk_children( $child );
			}
			return $return;
		}
	}

	/**
	 * Method to get data from the context's storage engine.
	 *
	 * @param int $data_id The ID of the object holding the data, e.g. Post ID.
	 * @param string $data_key The key for the data, e.g. a meta_key.
	 * @param boolean $single Optional. If true, only returns the first value
	 *                        found for the given data_key. This won't apply to
	 *                        every context. Default is false.
	 * @return string|array The stored data. If no data is found, should return
	 *                      an empty string (""). @see get_post_meta().
	 */
	abstract protected function get_data( $data_id, $data_key, $single = false );

	/**
	 * Method to add data to the context's storage engine.
	 *
	 * @param int $data_id The ID of the object holding the data, e.g. Post ID.
	 * @param string $data_key The key for the data, e.g. a meta_key.
	 * @param mixed $data_value The value to store.
	 * @param boolean $unique Optional. If true, data will only be added if the
	 *                        object with the given $data_id doesn't already
	 *                        contain data for the given $data_key. This may not
	 *                        apply to every context. Default is false.
	 * @return boolean|integer On success, should return the ID of the stored
	 *                         data (an integer, which will evaluate as true).
	 *                         If the $unique argument is set to true and data
	 *                         with the given key already exists, this should
	 *                         return false.  @see add_post_meta().
	 */
	abstract protected function add_data( $data_id, $data_key, $data_value, $unique = false );

	/**
	 * Method to update the data in the context's storage engine.
	 *
	 * @param int $data_id The ID of the object holding the data, e.g. Post ID.
	 * @param string $data_key The key for the data, e.g. a meta_key.
	 * @param mixed $data_value The value to store.
	 * @param mixed $data_prev_value Optional. Only update data if the previous
	 *                               value matches the data provided. This may
	 *                               not apply to every context.
	 * @return mixed Should return data id if the data doesn't exist, otherwise
	 *               should return true on success and false on failure. If the
	 *               value passed to this method is the same as the stored
	 *               value, this method should return false.
	 *               @see update_post_meta().
	 */
	abstract protected function update_data( $data_id, $data_key, $data_value, $data_prev_value = '' );

	/**
	 * Method to delete data from the context's storage engine.
	 *
	 * @param int $data_id The ID of the object holding the data, e.g. Post ID.
	 * @param string $data_key The key for the data, e.g. a meta_key.
	 * @param mixed $data_value Only delete the data if the stored value matches
	 *                          $data_value. This may not apply to every
	 *                          context.
	 * @return boolean False for failure. True for success.
	 *                  @see delete_post_meta().
	 */
	abstract protected function delete_data( $data_id, $data_key, $data_value = '' );
}