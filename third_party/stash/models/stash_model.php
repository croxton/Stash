<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Set and get template variables, EE snippets and persistent variables.
 *
 * @package             Stash
 * @version				2.1.0
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2011 Hallmark Design
 * @license             http://creativecommons.org/licenses/by-nc-sa/3.0/
 * @link                http://hallmark-design.co.uk
 */

class Stash_model extends CI_Model {
	
	public $EE;
	protected static $keys = array();
	protected static $inserted_keys = array();
	protected static $bundle_ids = array();

    function __construct()
    {
        parent::__construct();
		$this->EE = get_instance();
    }
    
	/**
	 * Delete last activity records older than given expiration period
	 *
	 * @param integer $expire
	 * @return boolean
	 */
    function prune_last_activity($expire=7200)
    {
       if ($result = $this->db->delete('stash', array(
			'key_name' 		=> '_last_activity', 
			'created <' 	=> $this->EE->localize->now - $expire
		)))
		{
			return TRUE;
		}
		return FALSE;
    }

	/**
	 * Get the last activity date for the current session
	 *
	 * @param string $session_id
	 * @param integer $site_id
	 * @return integer
	 */
	function get_last_activity_date($session_id, $site_id)
	{	
		$result = $this->db->select('created')
				 		  ->from('stash')
				 		  ->where('key_name', '_last_activity')
				 		  ->where('site_id', $site_id)
				 		  ->where('session_id', $session_id)
				 		  ->limit(1)
				 		  ->get();
		
		if ($result->num_rows() == 1) 
		{
			return $result->row('created');
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Update last activity date for the current session
	 *
	 * @param string $session_id
	 * @param integer $site_id
	 * @return integer
	 */
	function update_last_activity($session_id, $site_id)
	{	
		if ($result = $this->update_key('_last_activity', 1, $session_id, $site_id)) 
		{
			return (bool) $this->db->affected_rows();
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Insert last activity date for the current session
	 *
	 * @param string $session_id
	 * @param integer $site_id
	 * @return integer
	 */
	function insert_last_activity($session_id, $site_id, $parameters = '')
	{	
		if ($result = $this->insert_key('_last_activity', 1, $session_id, $site_id, 0, $parameters)) 
		{
			return $this->db->insert_id();
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Insert key for user session
	 *
	 * @param string $key
	 * @param string $session_id
	 * @param integer $site_id
	 * @param integer $expire
	 * @param string $parameters
	 * @param string $label
	 * @param integer $bundle_id
	 * @return integer
	 */
	function insert_key($key, $bundle_id = 1, $session_id, $site_id = 1, $expire = 0, $parameters = '', $label = '')
	{
		$data = array(	
				'key_name' 		=> $key,
				'bundle_id'		=> $bundle_id,
				'session_id' 	=> $session_id,
				'site_id'		=> $site_id,
				'created' 		=> $this->EE->localize->now,
				'expire' 		=> $expire,
				'parameters' 	=> $parameters,
				'key_label'		=> $label
		);
		
		if ( $result = $this->db->insert('stash', $data) )
		{
			// store a record of the newly created key
			$cache_key = $key . '_'. $bundle_id .'_' .$site_id . '_' . $session_id;
			self::$inserted_keys[] = $cache_key;
			
			// return insert id
			return $this->db->insert_id();
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Check to see if a key has been inserted within this page load
	 *
	 * @param string $cache_key
	 * @return bool
	 */
	function is_inserted_key($cache_key)
	{
		return in_array($cache_key, self::$inserted_keys) ? TRUE : FALSE;
	}
	
	/**
	 * Update key for user session
	 *
	 * @param string $key
	 * @param string $session_id
	 * @param integer $site_id
	 * @param integer $expire
	 * @param string $parameters
	 * @return boolean
	 */
	function update_key($key, $bundle_id = 1, $session_id = '', $site_id = 1, $expire = 0, $parameters = NULL)
	{
		$data = array(
				'created' 		=> $this->EE->localize->now,
				'expire' 		=> $expire
		);
		
		if ($parameters !== NULL)
		{
			$data += array('parameters' => $parameters);
		}
		
		$this->db->where('key_name', $key)
				 ->where('bundle_id', $bundle_id)
				 ->where('site_id', $site_id);				 
				
		if ( ! empty($session_id))
		{
			$this->db->where('session_id', $session_id);
		}		
		
		if ($result = $this->db->update('stash', $data))		 		   
		{
			// 0 affected rows = no update, likely key doesn't exist
			return (bool) $this->db->affected_rows();
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Refresh key for user session
	 *
	 * @param string $key
	 * @param string $session_id
	 * @param integer $site_id
	 * @param integer $refresh Seconds to expiry date
	 * @return boolean
	 */
	function refresh_key($key, $bundle_id = 1, $session_id = '', $site_id = 1, $refresh)
	{	
		$expire = $this->EE->localize->now + $refresh;
		return $this->update_key($key, $bundle_id, $session_id, $site_id, $expire);
	}
	
	/**
	 * Get key value
	 *
	 * @param string $key
	 * @param string $session_id
	 * @param integer $site_id
	 * @param string $col
	 * @return string
	 */
	function get_key($key, $bundle_id = 1, $session_id = '', $site_id = 1, $col = 'parameters')
	{
		$cache_key = $key . '_'. $bundle_id .'_' .$site_id . '_' . $session_id;
		
		if ( ! isset(self::$keys[$cache_key]))
		{
			$this->db->select($col .', created, expire')
					 ->from('stash')
					 ->where('key_name', $key)
					 ->where('bundle_id', $bundle_id)
					 ->where('site_id', $site_id)
					 ->limit(1);
			if ( ! empty($session_id))
			{
				$this->db->where('session_id', $session_id);
			}
		
			$result = $this->db->get();
		
			if ($result->num_rows() == 1) 
			{
				// if this key expires soon and is scoped to user session, refresh it
				if ($result->row('expire') > 0 && $session_id != '_global' && ! empty($session_id))
				{
					$refresh = $result->row('expire') - $result->row('created'); // refresh period  (seconds)
					$expire  = $result->row('expire') - $this->EE->localize->now; // time to expiry (seconds)
			
					if ( ($refresh / $expire) > 2 ) 
					{
						// more than half the refresh time has passed since the last time key was accessed
						// so let's refresh the key expiry
						$this->refresh_key($key, $bundle_id, $session_id, $site_id, $refresh);	
					}
				}
			
				// cache result
				self::$keys[$cache_key] = $result->row($col);
			}
			else
			{
				// don't cache a negative result, in case the variable is created later on in this session
				return FALSE;
			}
		}
		return self::$keys[$cache_key];
	}
	
	/**
	 * Delete key(s), optionally limited to keys registered with the user session
	 *
	 * @param string $key
	 * @param string $session_id
	 * @param integer $site_id
	 * @return boolean
	 */
	function delete_key($key, $bundle_id = 1, $session_id = '', $site_id = 1)
	{
		$this->db->where('key_name', $key)
				 ->where('bundle_id', $bundle_id)
				 ->where('site_id', $site_id);
				
		if ( ! empty($session_id))
		{
			$this->db->where('session_id', $session_id)
					 ->limit(1);
		}
		
		if ($this->EE->db->delete('stash')) 
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Prune expired keys
	 *
	 * @return boolean
	 */
	function prune_keys()
	{
		if ($result = $this->db->delete('stash', array(
				'expire <'  => $this->EE->localize->now, 
				'expire !=' => '0'
		)))
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Flush cache
	 *
	 * @param integer $site_id
	 * @return boolean
	 */
	function flush_cache($site_id = 1, $bundle_id = 1)
	{
		$this->db->where('site_id', $site_id)
				 ->where('key_name !=',  '_last_activity')
				 ->where('bundle_id',  $bundle_id);

		if ($this->EE->db->delete('stash')) 
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Get a bundle id from the name
	 *
	 * @return integer
	 */
	function get_bundle_by_name($bundle, $site_id = 1)
	{
		$cache_key = $bundle . '_' . $site_id;
		
		if ( ! isset(self::$bundle_ids[$cache_key]))
		{
			$result = $this->db->select('id')
					 ->from('stash_bundles')
					 ->where('bundle_name', $bundle)
					 ->where('site_id', $site_id)
					 ->limit(1)
					 ->get();
				
			if ($result->num_rows() == 1) 
			{
				self::$bundle_ids[$cache_key] = $result->row('id');
			}
			else
			{
				self::$bundle_ids[$cache_key] = FALSE;
			}
		}		
		return self::$bundle_ids[$cache_key];
	}
	
	/**
	 * Insert a bundle
	 *
	 * @param string $bundle
	 * @param integer $site_id
	 * @param integer $bundle_label
	 */
	function insert_bundle($bundle, $site_id = 1, $bundle_label = '')
	{
		$data = array(	
				'bundle_name' 	=> $bundle,
				'site_id'		=> $site_id,
				'bundle_label'	=> $bundle_label
		);
		
		if ( $result = $this->db->insert('stash_bundles', $data) )
		{
			return $this->db->insert_id();
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Check if there is at least one entry for the bundle in this site
	 *
	 * @return integer
	 */
	function bundle_entry_exists($bundle_id, $site_id = 1)
	{
		$result = $this->db->select('id')
				 ->from('stash')
				 ->where('bundle_id', $bundle_id)
				 ->where('site_id', $site_id)
				 ->limit(1)
				 ->get();
				
		if ($result->num_rows() == 1) 
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Count the number of entries for a given bundle
	 *
	 * @return boolean
	 */
	function bundle_entry_count($bundle_id, $site_id = 1)
	{
		$result = $this->db->select('COUNT(id) AS row_count')
				 ->from('stash')
				 ->where('bundle_id', $bundle_id)
				 ->where('site_id', $site_id)
				 ->get();
				
		if ($result->num_rows() == 1) 
		{
			return $result->row('row_count');
		}
		else
		{
			return 0;
		}
	}
}