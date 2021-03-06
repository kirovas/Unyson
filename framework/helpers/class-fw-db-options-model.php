<?php if (!defined('FW')) die('Forbidden');

/**
 * Lets you create easy functions for get/set database option values
 * it will handle all clever logic with default values, multikeys and processing options fw-storage parameter
 * @since 2.5.9
 */
abstract class FW_Db_Options_Model {
	/**
	 * @return string Must not contain '/'
	 */
	abstract protected function get_id();

	/**
	 * @param null|int $item_id
	 * @param array $extra_data
	 * @return mixed
	 */
	abstract protected function get_values($item_id, array $extra_data = array());

	/**
	 * @param null|int $item_id
	 * @param mixed $values
	 * @param array $extra_data
	 * @return void
	 */
	abstract protected function set_values($item_id, $values, array $extra_data = array());

	/**
	 * @param null|int $item_id
	 * @param array $extra_data
	 * @return array
	 */
	abstract protected function get_options($item_id, array $extra_data = array());

	/**
	 * @param null|int $item_id
	 * @param array $extra_data
	 * @return array E.g. for post options {'post-id': $item_id}
	 * @see fw_db_option_storage_type()
	 */
	abstract protected function get_fw_storage_params($item_id, array $extra_data = array());

	abstract protected function _init();

	/**
	 * @param null|int $item_id
	 * @param null|string $option_id
	 * @param null|string $sub_keys
	 * @param mixed $old_value
	 * @param array $extra_data
	 */
	protected function _after_set($item_id, $option_id, $sub_keys, $old_value, array $extra_data = array()) {}

	/**
	 * @param string $key
	 * @param null|int $item_id
	 * @param array $extra_data
	 * @return null|string
	 */
	protected function _get_cache_key($key, $item_id, array $extra_data = array()) {
		return empty($item_id) ? null : $item_id;
	}

	/**
	 * @var array {'id': bool}
	 */
	private static $merge_values_with_defaults = array();

	/**
	 * @var array {'id': mixed}
	 */
	private static $instances = array();

	/**
	 * @param string $id
	 * @return FW_Db_Options_Model
	 * @internal
	 */
	final public static function _get_instance($id) {
		return self::$instances[$id];
	}
	
	final public function __construct() {
		if (isset(self::$instances[ $this->get_id() ])) {
			trigger_error(__CLASS__ .' with id "'. $this->get_id() .'" was already defined', E_USER_ERROR);
		} else {
			self::$instances[ $this->get_id() ] = $this;
		}

		$this->_init();
	}

	private function get_cache_key($key, $item_id, array $extra_data = array()) {
		$item_key = $this->_get_cache_key($key, $item_id, $extra_data);

		return 'fw-options-model:'. $this->get_id() .'/'. $key . (empty($item_key) ? '' : '/'. $item_key);
	}

	/**
	 * @param null|int $item_id Post or Term ID
	 * @param null|string $option_id
	 * @param mixed $default_value
	 * @param array $extra_data
	 * @return mixed
	 */
	final public function get( $item_id = null, $option_id = null, $default_value = null, array $extra_data = array() ) {
		if (empty($option_id)) {
			$sub_keys = null;
		} else {
			$option_id = explode('/', $option_id); // 'option_id/sub/keys'
			$_option_id = array_shift($option_id); // 'option_id'
			$sub_keys = empty($option_id) ? null : implode('/', $option_id); // 'sub/keys'
			$option_id = $_option_id;
			unset($_option_id);
		}

		try {
			/**
			 * Cached because values are merged with extracted default values
			 */
			$values = FW_Cache::get($cache_key_values = $this->get_cache_key('values', $item_id, $extra_data));
		} catch (FW_Cache_Not_Found_Exception $e) {
			FW_Cache::set(
				$cache_key_values,
				$values = is_array($values = $this->get_values($item_id, $extra_data)) ? $values : array()
			);

			self::$merge_values_with_defaults[ $this->get_id() ] = true;
		}

		/**
		 * If db value is not found and default value is provided
		 * return default value before loading options file
		 * to prevent infinite recursion in case if this function is called in options file
		 */
		if ( ! is_null($default_value) ) {
			if ( empty( $option_id ) ) {
				if ( empty( $values ) && is_array( $default_value ) ) {
					return $default_value;
				}
			} else {
				if ( is_null( $sub_keys ) ) {
					if ( ! isset( $values[ $option_id ] ) ) {
						return $default_value;
					}
				} else {
					if ( is_null( fw_akg( $sub_keys, $values[ $option_id ] ) ) ) {
						return $default_value;
					}
				}
			}
		}

		try {
			$options = FW_Cache::get( $cache_key = $this->get_cache_key('options', $item_id, $extra_data));
		} catch (FW_Cache_Not_Found_Exception $e) {
			FW_Cache::set($cache_key, array()); // prevent recursion
			FW_Cache::set($cache_key, $options = fw_extract_only_options($this->get_options($item_id, $extra_data)));
		}

		/**
		 * Complete missing db values with default values from options array
		 */
		if (self::$merge_values_with_defaults[ $this->get_id() ]) {
			self::$merge_values_with_defaults[ $this->get_id() ] = false;
			FW_Cache::set(
				$cache_key_values,
				$values = array_merge(
					fw_get_options_values_from_input($options, array()),
					$values
				)
			);
		}

		if (empty($option_id)) {
			foreach ($options as $id => $option) {
				$values[$id] = fw()->backend->option_type($options[$id]['type'])->storage_load(
					$id,
					$options[$id],
					isset($values[$id]) ? $values[$id] : null,
					$this->get_fw_storage_params($item_id, $extra_data)
				);
			}
		} else {
			if (isset($options[$option_id])) {
				$values[ $option_id ] = fw()->backend->option_type( $options[ $option_id ]['type'] )->storage_load(
					$option_id,
					$options[ $option_id ],
					isset($values[ $option_id ]) ? $values[ $option_id ] : null,
					$this->get_fw_storage_params($item_id, $extra_data)
				);
			}
		}

		if (empty($option_id)) {
			return (empty($values) && is_array($default_value)) ? $default_value : $values;
		} else {
			if (is_null($sub_keys)) {
				return isset($values[$option_id]) ? $values[$option_id] : $default_value;
			} else {
				return fw_akg($sub_keys, $values[$option_id], $default_value);
			}
		}
	}
	
	final public function set( $item_id = null, $option_id = null, $value, array $extra_data = array() ) {
		FW_Cache::del($cache_key_values = $this->get_cache_key('values', $item_id, $extra_data));
		
		try {
			$options = FW_Cache::get($cache_key = $this->get_cache_key('options', $item_id, $extra_data));
		} catch (FW_Cache_Not_Found_Exception $e) {
			FW_Cache::set($cache_key, array()); // prevent recursion
			FW_Cache::set($cache_key, $options = fw_extract_only_options($this->get_options($item_id, $extra_data)));
		}

		$sub_keys = null;

		if ($option_id) {
			$option_id = explode('/', $option_id); // 'option_id/sub/keys'
			$_option_id = array_shift($option_id); // 'option_id'
			$sub_keys = empty($option_id) ? null : implode('/', $option_id); // 'sub/keys'
			$option_id = $_option_id;
			unset($_option_id);

			$old_values = is_array($old_values = $this->get_values($item_id, $extra_data)) ? $old_values : array();
			$old_value = isset($old_values[$option_id]) ? $old_values[$option_id] : null;

			if ($sub_keys) { // update sub_key in old_value and use the entire value
				$new_value = $old_value;
				fw_aks($sub_keys, $value, $new_value);
				$value = $new_value;
				unset($new_value);

				$old_value = fw_akg($sub_keys, $old_value);
			}

			if (isset($options[$option_id])) {
				$value = fw()->backend->option_type($options[$option_id]['type'])->storage_save(
					$option_id,
					$options[$option_id],
					$value,
					$this->get_fw_storage_params($item_id, $extra_data)
				);
			}

			$old_values[$option_id] = $value;

			$this->set_values($item_id, $old_values, $extra_data);

			unset($old_values);
		} else {
			$old_value = is_array($old_values = $this->get_values($item_id, $extra_data)) ? $old_values : array();

			if ( ! is_array($value) ) {
				$value = array();
			}

			foreach ($value as $_option_id => $_option_value) {
				if (isset($options[$_option_id])) {
					$value[$_option_id] = fw()->backend->option_type($options[$_option_id]['type'])->storage_save(
						$_option_id,
						$options[$_option_id],
						$_option_value,
						$this->get_fw_storage_params($item_id, $extra_data)
					);
				}
			}

			$this->set_values($item_id, $value, $extra_data);
		}

		FW_Cache::del($cache_key_values); // fixes https://github.com/ThemeFuse/Unyson/issues/1538

		$this->_after_set($item_id, $option_id, $sub_keys, $old_value, $extra_data);
	}
}