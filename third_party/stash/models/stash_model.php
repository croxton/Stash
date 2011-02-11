<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Stash_model extends CI_Model {
	
	public $EE;

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
		if ($result = $this->update_key('_last_activity', $session_id, $site_id)) 
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
		if ($result = $this->insert_key('_last_activity', $session_id, $site_id, 0, $parameters)) 
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
	function insert_key($key, $session_id, $site_id = 1, $expire = 0, $parameters = '', $label = '', $bundle_id = 1)
	{
		$data = array(	
				'key_name' 		=> $key,
				'session_id' 	=> $session_id,
				'site_id'		=> $site_id,
				'created' 		=> $this->EE->localize->now,
				'expire' 		=> $expire,
				'parameters' 	=> $parameters,
				'key_label'		=> $label,
				'bundle_id'		=> $bundle_id
		);
		
		if ( $result = $this->db->insert('stash', $data) )
		{
			return $this->db->insert_id();
		}
		else
		{
			return FALSE;
		}
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
	function update_key($key, $session_id, $site_id = 1, $expire = 0, $parameters = '')
	{
		$data = array(
				'created' 		=> $this->EE->localize->now,
				'expire' 		=> $expire,
				'parameters' 	=> $parameters
		);
		
		$this->db->where('key_name', $key)
				 ->where('session_id', $session_id)
				 ->where('site_id', $site_id);
		
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
	 * Get key value
	 *
	 * @param string $key
	 * @param string $session_id
	 * @param integer $site_id
	 * @param string $col
	 * @return string
	 */
	function get_key($key, $session_id = '', $site_id = 1, $col = 'parameters')
	{
		$this->db->select($col)
				 ->from('stash')
				 ->where('key_name', $key)
				 ->where('site_id', $site_id)
				 ->limit(1);
		if ( ! empty($session_id))
		{
			$this->db->where('session_id', $session_id);
		}
		
		$result = $this->db->get();
		
		if ($result->num_rows() == 1) 
		{
			return $result->row('parameters');
		}
		else
		{
			return FALSE;
		}
	}
	
	/**
	 * Delete key(s), optionally limited to keys registered with the user session
	 *
	 * @param string $key
	 * @param string $session_id
	 * @param integer $site_id
	 * @return boolean
	 */
	function delete_key($key, $session_id = '', $site_id = 1)
	{
		$this->db->where('key_name', $key)
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
	function flush_cache($site_id = 1)
	{
		$this->db->where('site_id', $site_id)
				 ->where('key_name !=',  '_last_activity')
				 ->where('bundle_id',  '1');

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
		$result = $this->db->select('id')
				 ->from('stash_bundles')
				 ->where('bundle_name', $bundle)
				 ->where('site_id', $site_id)
				 ->limit(1)
				 ->get();
				
		if ($result->num_rows() == 1) 
		{
			return $result->row('id');
		}
		else
		{
			return FALSE;
		}
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
	 * @return boolean
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
}