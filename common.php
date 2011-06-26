<?php
namespace Jamm\Memory;

class Key_AutoUnlocker
{
	public $key = '';
	protected $Unlock = NULL;

	/**
	 * @param callback $Unlock
	 */
	public function __construct($Unlock)
	{
		if (is_callable($Unlock)) $this->Unlock = $Unlock;
	}

	public function revoke()
	{
		$this->Unlock = NULL;
	}

	public function __destruct()
	{
		if (isset($this->Unlock)) call_user_func($this->Unlock, $this);
	}
}

interface IMemoryStorage
{
	/**
	 * Add value to memory storage, only if this key does not exists (or false will be returned).
	 *
	 * @param string $k
	 * @param mixed $v
	 * @param int $ttl
	 * @param array|string $tags
	 * @return boolean
	 */
	public function add($k, $v, $ttl = 259200, $tags = NULL);

	/**
	 * Save variable in memory storage
	 *
	 * @param string $k - key
	 * @param mixed $v - value
	 * @param int $ttl - time to live (store) in seconds
	 * @param array|string $tags - array of tags for this key
	 * @return bool
	 */
	public function save($k, $v, $ttl = 259200, $tags = NULL);

	/**
	 * Read data from memory storage
	 *
	 * @param string|array $k (string or array of string keys)
	 * @param mixed $ttl_left = (ttl - time()) of key. Use to exclude dog-pile effect, with lock/unlock_key methods.
	 * @return mixed
	 */
	public function read($k, &$ttl_left = -1);

	/**
	 * Delete key or array of keys from storage
	 * @param string|array $k - keys
	 * @return boolean|array - if array of keys was passed, on error will be returned array of not deleted keys, or 'true' on success.
	 */
	public function del($k);

	/**
	 * Delete old (by ttl) variables from storage
	 * @return boolean
	 */
	public function del_old();

	/**
	 * Delete keys by tags
	 *
	 * @param array|string $tag - tag or array of tags
	 * @return boolean
	 */
	public function del_by_tags($tag);

	/**
	 * Select from storage by params
	 * Only values of 'array' type will be selected
	 * k - key, r - relation, v - value
	 * relations: "<", ">", "=" or "==", "!=" or "<>"
	 * example: select(array(array('k'=>'user_id',	'r'=>'<',	'v'=>1))); - select where user_id<1
	 * @deprecated
	 * @param array $params
	 * @param bool $get_array
	 * @return mixed
	 */
	public function select($params, $get_array = false);

	/**
	 * Select from storage via callback function
	 * Only values of 'array' type will be selected
	 * @param callback $fx ($value_array,$key)
	 * @param bool $get_array
	 * @return mixed
	 */
	public function select_fx($fx, $get_array = false);

	/**
	 * Increment value of key
	 * @param string $key
	 * @param mixed $by_value
	 * if stored value is array:
	 *			 if $by_value is value in array, new element will be pushed to the end of array,
	 *			if $by_value is key=>value array, key=>value pair will be added (or updated)
	 * @param int $limit_keys_count - maximum count of elements (used only if stored value is array)
	 * @return int|string|array new value of key
	 */
	public function increment($key, $by_value = 1, $limit_keys_count = 0);

	/**
	 * Get exclusive mutex for key. Key will be still accessible to read and write, but
	 * another process can exclude dog-pile effect, if before updating the key he will try to get this mutex.
	 * @param mixed $key
	 * @param mixed $auto_unlocker_variable - pass empty, just declared variable
	 */
	public function lock_key($key, &$auto_unlocker_variable);

	/**
	 * Unlock key, locked by method 'lock_key'
	 * @param Key_AutoUnlocker $auto_unlocker
	 * @return bool
	 */
	public function unlock_key(Key_AutoUnlocker $auto_unlocker);

	/** Return array of all stored keys */
	public function get_keys();

	/**
	 * @return string
	 */
	public function getLastErr();

	/**
	 * @return array
	 */
	public function get_stat();

	public function getErrLog();
}

abstract class MemoryObject
{
	const max_ttl = 2592000;

	protected $last_err;
	protected $err_log;

	public function getLastErr()
	{
		$t = $this->last_err;
		$this->last_err = '';
		return $t;
	}

	protected function ReportError($msg, $line)
	{
		$this->last_err = $line.': '.$msg;
		$this->err_log[] = $line.': '.$msg;
		return false;
	}

	public function getErrLog()
	{ return $this->err_log; }
}

class APCObject extends MemoryObject implements IMemoryStorage
{
	protected $prefix = 'K'; //because I love my wife Katya :)
	protected $lock_key_prefix;
	protected $defragmentation_prefix;
	protected $tags_prefix;

	const lock_key_prefix = '.lock_key.';
	const defragmentation_prefix = '.clean.';
	const tags_prefix = '.tags.';
	const apc_arr_key = 'key';
	const apc_arr_ctime = 'creation_time';
	const apc_arr_ttl = 'ttl';
	const apc_arr_value = 'value';
	const apc_arr_atime = 'access_time';

	/**
	 * @param string $prefix
	 */
	public function __construct($prefix = '')
	{
		if (!empty($prefix))
		{
			$this->prefix = str_replace('.', '_', $prefix).'.';
		}

		$this->lock_key_prefix = self::lock_key_prefix.$this->prefix;
		$this->defragmentation_prefix = self::defragmentation_prefix;
		$this->tags_prefix = self::tags_prefix.$this->prefix;
	}

	/**
	 * Add value to memory storage, only if this key does not exists (or false will be returned).
	 *
	 * @param string $k
	 * @param mixed $v
	 * @param int $ttl
	 * @param array|string $tags
	 * @return boolean
	 */
	public function add($k, $v, $ttl = 259200, $tags = NULL)
	{
		if (empty($k))
		{
			$this->ReportError('empty keys are not allowed', __LINE__);
			return false;
		}

		$add = apc_add($this->prefix.$k, $v, intval($ttl));
		if (!$add)
		{
			$this->ReportError('key already exists', __LINE__);
			return false;
		}
		if (!empty($tags)) $this->set_tags($k, $tags, $ttl);
		return true;
	}

	/**
	 * Associate tags with keys
	 * @param string $key
	 * @param string|array $tags
	 * @param int $ttl
	 * @return bool
	 */
	public function set_tags($key, $tags, $ttl = self::max_ttl)
	{
		if (!is_array($tags))
		{
			if (is_scalar($tags)) $tags = array($tags);
			else $tags = array();
		}
		if (!empty($tags))
		{
			return apc_store($this->tags_prefix.$key, $tags, intval($ttl));
		}
		return false;
	}

	/**
	 * Save variable in memory storage
	 *
	 * @param string $k
	 * @param mixed $v
	 * @param int $ttl - time to live (store) in seconds
	 * @param array|string $tags - array of tags for this key
	 * @return bool
	 */
	public function save($k, $v, $ttl = 259200, $tags = NULL)
	{
		if (empty($k))
		{
			$this->ReportError('empty keys are not allowed', __LINE__);
			return false;
		}

		static $cleaned = false;
		if (!$cleaned)
		{
			$this->del_old();
			$cleaned = true;
		}

		if (!apc_store($this->prefix.$k, $v, intval($ttl)))
		{
			$this->ReportError('apc can not store key', __LINE__);
			return false;
		}

		if (!empty($tags)) $this->set_tags($k, $tags, $ttl);
		return true;
	}

	/**
	 * Returns, how many seconds left till key expiring.
	 * @param  $key
	 * @return int
	 */
	public function getKeyTTL($key)
	{
		$i = new \APCIterator('user', '/^'.$this->prefix.$key.'$/', APC_ITER_TTL+APC_ITER_CTIME, 1);
		foreach ($i as $key)
		{
			if ($key[self::apc_arr_ttl]!=0) return (($key[self::apc_arr_ctime]+$key[self::apc_arr_ttl])-time());
			else return self::max_ttl;
		}
		return self::max_ttl;
	}

	/**
	 * Read data from memory storage
	 *
	 * @param string|array $k (string or array of string keys)
	 * @param mixed $ttl_left = (ttl - time()) of key. Use to exclude dog-pile effect, with lock/unlock_key methods.
	 * @return mixed
	 */
	public function read($k, &$ttl_left = -1)
	{
		if (empty($k))
		{
			$this->ReportError('empty key are not allowed', __LINE__);
			return NULL;
		}
		if (is_array($k))
		{
			$data = array();
			$return_ttl = ($ttl_left!==-1 ? true : false);
			$ttl_left = array();
			foreach ($k as $key)
			{
				$key = (string)$key;
				$data[$key] = apc_fetch($this->prefix.$key, $success);
				if (!$success)
				{
					unset($data[$key]);
					continue;
				}
				if ($return_ttl) $ttl_left[$key] = $this->getKeyTTL($key);
			}
		}
		else
		{
			$data = apc_fetch($this->prefix.$k, $success);
			if (!$success)
			{
				$this->ReportError('apc can not fetch key '.$k.' (or not exists)', __LINE__);
				return false;
			}
			if ($ttl_left!==-1)
			{
				$ttl_left = $this->getKeyTTL($k);
				if ($ttl_left < 0) $data = false; //key expired
			}
		}
		return $data;
	}

	/** Return array of all stored keys */
	public function get_keys()
	{
		$map = array();
		$l = strlen($this->prefix);
		$i = new \APCIterator('user', '/^'.$this->prefix.'/', APC_ITER_KEY);
		foreach ($i as $item)
		{
			$map[] = substr($item[self::apc_arr_key], $l);
		}
		return $map;
	}

	/**
	 * Delete key or array of keys from storage
	 * @param string|array $k
	 * @return boolean
	 */
	public function del($k)
	{
		if (empty($k))
		{
			$this->ReportError('empty key are not allowed', __LINE__);
			return false;
		}

		if (is_array($k))
		{
			$todel = array();
			foreach ($k as $key)
			{
				$todel[] = $this->prefix.$key;
				if (apc_exists($this->tags_prefix.$key)) $todel[] = $this->tags_prefix.$key;
				if (apc_exists($this->lock_key_prefix.$key)) $todel[] = $this->lock_key_prefix.$key;
			}
			$r = apc_delete($todel);
			if (empty($r)) return true;
			else return $r;
		}
		else
		{
			if (apc_exists($this->tags_prefix.$k)) apc_delete($this->tags_prefix.$k);
			if (apc_exists($this->lock_key_prefix.$k)) apc_delete($this->lock_key_prefix.$k);
			return apc_delete($this->prefix.$k);
		}
	}

	/**
	 * Delete old (by ttl) variables from storage
	 * It's very important function to prevent APC's cache fragmentation.
	 * @return boolean
	 */
	public function del_old()
	{
		$t = time();
		$apc_user_info = apc_cache_info('user', true);
		$apc_ttl = 0;
		if (!empty($apc_user_info['ttl']))
		{
			$apc_ttl = $apc_user_info['ttl']/2;
			$check_period = $apc_ttl;
		}
		if (empty($check_period) || $check_period > 1800) $check_period = 1800;

		$ittl = new \APCIterator('user', '/^'.$this->defragmentation_prefix.'$/', APC_ITER_ATIME, 1);
		$cttl = $ittl->current();
		$previous_cleaning = $cttl[self::apc_arr_atime];
		if (empty($previous_cleaning) || ($t-$previous_cleaning) > $check_period)
		{
			apc_store($this->defragmentation_prefix, $t, $check_period);
			$todel = array();

			$i = new \APCIterator('user', null, APC_ITER_TTL+APC_ITER_KEY+APC_ITER_CTIME+APC_ITER_ATIME);
			foreach ($i as $key)
			{
				if ($key[self::apc_arr_ttl] > 0 && ($t-$key[self::apc_arr_ctime]) > $key[self::apc_arr_ttl]) $todel[] = $key[self::apc_arr_key];
				else
				{
					//this code is necessary to prevent deletion variables from cache by apc.ttl (they deletes not by their ttl+ctime, but apc.ttl+atime)
					if ($apc_ttl > 0 && (($t-$key[self::apc_arr_atime]) > $apc_ttl)) apc_fetch($key[self::apc_arr_key]);
				}
			}
			if (!empty($todel))
			{
				$r = apc_delete($todel);
				if (!empty($r)) return $r;
				else return true;
			}
		}
		return true;
	}

	/**
	 * Delete keys by tags
	 *
	 * @param array|string $tags - tag or array of tags
	 * @return boolean
	 */
	public function del_by_tags($tags)
	{
		if (!is_array($tags)) $tags = array($tags);

		$todel = array();
		$l = strlen($this->tags_prefix);
		$i = new \APCIterator('user', '/^'.$this->tags_prefix.'/', APC_ITER_KEY+APC_ITER_VALUE);
		foreach ($i as $key_tags)
		{
			if (is_array($key_tags[self::apc_arr_value]))
			{
				$intersect = array_intersect($tags, $key_tags[self::apc_arr_value]);
				if (!empty($intersect)) $todel[] = substr($key_tags[self::apc_arr_key], $l);
			}
		}

		if (!empty($todel)) return $this->del($todel);
		return true;
	}

	/**
	 * Select from storage by params
	 * k - key, r - relation, v - value
	 * example: select(array(array('k'=>'user_id',	'r'=>'<',	'v'=>1))); - select where user_id<1
	 * @param array $params
	 * @param bool $get_array
	 * @return mixed
	 */
	public function select($params, $get_array = false)
	{
		$arr = array();
		$l = strlen($this->prefix);
		$i = new \APCIterator('user', '/^'.$this->prefix.'/', APC_ITER_KEY+APC_ITER_VALUE);
		foreach ($i as $item)
		{
			if (!is_array($item[self::apc_arr_value])) continue;
			$s = $item[self::apc_arr_value];
			$key = substr($item[self::apc_arr_key], $l);
			$matched = true;
			foreach ($params as $p)
			{
				if ($p['r']=='=' || $p['r']=='==')
				{
					if ($s[$p['k']]!=$p['v'])
					{
						$matched = false;
						break;
					}
				}
				elseif ($p['r']=='<')
				{
					if ($s[$p['k']] >= $p['v'])
					{
						$matched = false;
						break;
					}
				}
				elseif ($p['r']=='>')
				{
					if ($s[$p['k']] <= $p['v'])
					{
						$matched = false;
						break;
					}
				}
				elseif ($p['r']=='<>' || $p['r']=='!=')
				{
					if ($s[$p['k']]==$p['v'])
					{
						$matched = false;
						break;
					}
				}
			}
			if ($matched==true)
			{
				if (!$get_array) return $s;
				else $arr[$key] = $s;
			}
		}
		if (!$get_array || empty($arr)) return false;
		else return $arr;
	}

	/**
	 * Select from storage via callback function
	 *
	 * @param callback $fx ($value_array,$key)
	 * @param bool $get_array
	 * @return mixed
	 */
	public function select_fx($fx, $get_array = false)
	{
		$arr = array();
		$l = strlen($this->prefix);
		$i = new \APCIterator('user', '/^'.$this->prefix.'/', APC_ITER_KEY+APC_ITER_VALUE);
		foreach ($i as $item)
		{
			if (!is_array($item[self::apc_arr_value])) continue;
			$s = $item[self::apc_arr_value];
			$index = substr($item[self::apc_arr_key], $l);

			if ($fx($s, $index)===true)
			{
				if (!$get_array) return $s;
				else $arr[$index] = $s;
			}
		}
		if (!$get_array || empty($arr)) return false;
		else return $arr;
	}

	/**
	 * Increment value of key
	 * @param string $key
	 * @param mixed $by_value
	 * if stored value is array:
	 *	if $by_value is value in array, new element will be pushed to the end of array,
	 *	if $by_value is key=>value array, key=>value pair will be added (or updated)
	 * @param int $limit_keys_count - maximum count of elements (used only if stored value is array)
	 * @return int|string|array new value of key
	 */
	public function increment($key, $by_value = 1, $limit_keys_count = 0)
	{
		if (empty($key))
		{
			$this->ReportError('empty keys are not allowed', __LINE__);
			return false;
		}
		$value = apc_fetch($this->prefix.$key, $success);
		if (!$success)
		{
			if ($this->save($key, $by_value)) return $by_value;
			else return false;
		}
		if (is_array($value))
		{
			if ($limit_keys_count > 0 && (count($value) > $limit_keys_count)) $value = array_slice($value, $limit_keys_count*(-1)+1);

			if (is_array($by_value))
			{
				$set_key = key($by_value);
				if (!empty($set_key)) $value[$set_key] = $by_value[$set_key];
				else $value[] = $by_value[0];
			}
			else $value[] = $by_value;

			if ($this->save($key, $value)) return $value;
			else return false;
		}
		elseif (is_numeric($value) && is_numeric($by_value)) return apc_inc($this->prefix.$key, $by_value);
		else
		{
			$value .= $by_value;
			if ($this->save($key, $value)) return $value;
			else return false;
		}
	}

	/**
	 * Get exclusive mutex for key. Key will be still accessible to read and write, but
	 * another process can exclude dog-pile effect, if before updating the key he will try to get this mutex.
	 * Example:
	 * Process 1 reads key simultaneously with Process 2.
	 * Value of this key are too old, so Process 1 going to refresh it. Simultaneously with Process 2.
	 * But both of them trying to lock_key, and Process 1 only will refresh value of key (taking it from database, e.g.),
	 * and Process 2 can decide, what he want to do - use old value and not spent time to database, or something else.
	 * @param mixed $key
	 * @param mixed $auto_unlocker_variable - pass empty, just declared variable
	 */
	public function lock_key($key, &$auto_unlocker_variable)
	{
		$r = apc_add($this->lock_key_prefix.$key, 1, 30);
		if (!$r) return false;
		$auto_unlocker_variable = new Key_AutoUnlocker(array($this, 'unlock_key'));
		$auto_unlocker_variable->key = $key;
		return true;
	}

	/**
	 * Unlock key, locked by method 'lock_key'
	 * @param Key_AutoUnlocker $auto_unlocker
	 * @return bool
	 */
	public function unlock_key(Key_AutoUnlocker $auto_unlocker)
	{
		if (empty($auto_unlocker->key))
		{
			$this->ReportError('autoUnlocker should be passed', __LINE__);
			return false;
		}
		$auto_unlocker->revoke();
		return apc_delete($this->lock_key_prefix.$auto_unlocker->key);
	}

	/**
	 * @return array
	 */
	public function get_stat()
	{
		return array(
			'system' => apc_cache_info('', true),
			'user' => apc_cache_info('user', true)
		);
	}
}